#!/usr/bin/env python3
"""
Scrape business.gov.om via Tor — production version.

Strategy:
- Each company search uses a fresh browser context (fresh cookies + UA rotation)
- Tor circuit rotated every N requests via ControlPort NEWNYM
- Gemini 2.5 Flash solves BotDetect CAPTCHAs (case-preserved)
- Parse search result row directly (CR #, EN name, AR name, legal form, expiry, status)
- Optionally click "View" for full detail (address, activities, phone, email)
- On 429 / rate limit: rotate circuit + 60s backoff
- Resumable via /tmp/om_enriched.csv
"""
import argparse, base64, csv, json, os, random, re, sys, time, urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

try:
    from stem import Signal
    from stem.control import Controller
except ImportError:
    Controller = None

PORTAL = 'https://www.business.gov.om/portal/searchEstablishments'
OUT_CSV = '/tmp/om_enriched.csv'
INPUT_TSV = '/tmp/om_companies_todo.tsv'
GEMINI_KEY = os.environ.get('GEMINI_API_KEY', '')
TOR_PASSWORD = os.environ.get('TOR_PASSWORD', 'cardify')
MAX_CAPTCHA_ATTEMPTS = 5
REQUESTS_PER_CIRCUIT = 3
SEARCH_DELAY_MIN = 6
SEARCH_DELAY_MAX = 14
TOR_WAIT_AFTER_NEWNYM = 6
FETCH_DETAIL = True  # click "View" for full detail

USER_AGENTS = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:131.0) Gecko/20100101 Firefox/131.0',
]

FIELDS = [
    'id', 'slug', 'name_en_input',
    'cr_number', 'cr_status', 'legal_form',
    'name_en_official', 'name_ar_official',
    'expiry_date', 'registration_date', 'capital',
    'activities', 'address', 'phone', 'email',
    'attempt_count', 'matched', 'error', 'scraped_at',
]


def tor_new_circuit():
    if not Controller:
        return False
    try:
        with Controller.from_port(port=9051) as c:
            c.authenticate(password=TOR_PASSWORD)
            c.signal(Signal.NEWNYM)
        return True
    except Exception as e:
        print(f'  [tor rotation err] {e}', file=sys.stderr)
        return False


def solve_captcha(png_bytes: bytes) -> str:
    b64 = base64.b64encode(png_bytes).decode()
    body = {
        "contents": [{"parts": [
            {"text": "Read the BotDetect CAPTCHA. Preserve EXACT case. Output only the raw characters (letters+digits), no spaces, no commentary."},
            {"inlineData": {"mimeType": "image/png", "data": b64}}
        ]}],
        "generationConfig": {"temperature": 0, "maxOutputTokens": 150, "thinkingConfig": {"thinkingBudget": 0}},
    }
    url = f'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={GEMINI_KEY}'
    req = urllib.request.Request(url, data=json.dumps(body).encode(), headers={'Content-Type': 'application/json'})
    try:
        resp = urllib.request.urlopen(req, timeout=30)
        data = json.loads(resp.read())
        text = ''.join(p.get('text', '') for p in data['candidates'][0]['content'].get('parts', []))
        return re.sub(r'[^A-Za-z0-9]', '', text)
    except Exception as e:
        print(f'  [captcha err] {e}', file=sys.stderr)
        return ''


def parse_search_row(html: str) -> dict | None:
    """Extract CR fields from the results table first data row."""
    table = re.search(r'<table[^>]*>([\s\S]*?)</table>', html, re.I)
    if not table:
        return None
    rows = re.findall(r'<tr[^>]*>([\s\S]*?)</tr>', table.group(1), re.I)
    if len(rows) < 2:
        return None
    # Find the first row containing a numeric CR (6-8 digits)
    for row in rows[1:]:
        cells = re.findall(r'<(?:td|th)[^>]*>([\s\S]*?)</(?:td|th)>', row, re.I)
        cleaned = [re.sub(r'\s+', ' ', re.sub(r'<[^>]+>', ' ', c)).strip() for c in cells]
        if cleaned and re.match(r'^\d{6,8}$', cleaned[0]):
            return {
                'cr_number': cleaned[0] if len(cleaned) > 0 else '',
                'name_ar_official': cleaned[1] if len(cleaned) > 1 else '',
                'name_en_official': cleaned[2] if len(cleaned) > 2 else '',
                'legal_form': cleaned[3] if len(cleaned) > 3 else '',
                'expiry_date': cleaned[4] if len(cleaned) > 4 else '',
                'cr_status': cleaned[5] if len(cleaned) > 5 else '',
            }
    return None


def parse_detail(html: str) -> dict:
    """Extract detail page fields. Structure varies; grab label-value pairs generically."""
    # Generic dt/dd or label-then-span pattern
    out = {}
    # Pattern 1: label <span> value
    for pat, key in [
        (r'(?:Registration Date|تاريخ التسجيل)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', 'registration_date'),
        (r'(?:Capital|رأس المال)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', 'capital'),
        (r'(?:Email|البريد الإلكتروني)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', 'email'),
        (r'(?:Telephone|Phone|الهاتف)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', 'phone'),
        (r'(?:Mobile|Mobile Number)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', 'phone'),
    ]:
        m = re.search(pat, html, re.I)
        if m:
            v = re.sub(r'\s+', ' ', m.group(1)).strip()
            if v and not out.get(key):
                out[key] = v
    # Activities: usually a list — find the block
    act = re.search(r'(?:Activities|الأنشطة)\s*</[^>]+>[\s\S]{0,2000}?<ul[\s\S]*?</ul>', html, re.I)
    if act:
        acts = re.findall(r'<li[^>]*>([^<]+)</li>', act.group(0))
        if acts:
            out['activities'] = ' | '.join(re.sub(r'\s+', ' ', a).strip() for a in acts[:10])
    # Address: simple grab
    addr = re.search(r'(?:Address|العنوان)\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', html, re.I)
    if addr:
        out['address'] = re.sub(r'\s+', ' ', addr.group(1)).strip()
    return out


def search_company(browser, company_name: str) -> dict:
    """One-shot search for a single company. Creates fresh context."""
    ua = random.choice(USER_AGENTS)
    vp = {'width': random.choice([1280, 1366, 1440, 1536]), 'height': random.choice([800, 900, 1000])}
    ctx = browser.new_context(user_agent=ua, locale='en-US', viewport=vp)
    page = ctx.new_page()
    result = {'attempt_count': 0, 'matched': False}

    try:
        for attempt in range(1, MAX_CAPTCHA_ATTEMPTS + 1):
            result['attempt_count'] = attempt
            try:
                page.goto(PORTAL, wait_until='networkidle', timeout=45000)
                time.sleep(random.uniform(1.2, 2.5))
                html = page.content()
                if '429' in html[:500] or 'Too many requests' in html:
                    result['error'] = 'rate_limited_429'
                    return result
                img = page.locator('#exampleCaptcha_CaptchaImage').screenshot(timeout=15000)
                captcha = solve_captcha(img)
                if len(captcha) < 4:
                    continue
                page.fill('input[name="criteria.companyName"]', company_name)
                page.fill('input[name="captchaCode"]', captcha)
                page.click('button[name="_eventId__search"]')
                page.wait_for_load_state('networkidle', timeout=45000)
                time.sleep(random.uniform(0.6, 1.4))
                html = page.content()

                if '429' in html[:500]:
                    result['error'] = 'rate_limited_429'
                    return result
                if 'كلمة التحقق غير صحيحة' in html:
                    continue  # retry captcha

                row = parse_search_row(html)
                if not row:
                    result['error'] = 'no_match'
                    return result

                result.update(row)
                result['matched'] = True

                if FETCH_DETAIL:
                    try:
                        page.locator('a[href*="_view_cr"]').first.click(timeout=6000)
                        page.wait_for_load_state('networkidle', timeout=45000)
                        time.sleep(random.uniform(0.5, 1.2))
                        detail = page.content()
                        result.update(parse_detail(detail))
                    except Exception as e:
                        result['error'] = f'detail_click_failed: {str(e)[:80]}'
                return result
            except Exception as e:
                msg = str(e)[:120]
                print(f'  attempt {attempt} inner err: {msg}', file=sys.stderr)
                time.sleep(3)
        result['error'] = 'captcha_exhausted'
        return result
    finally:
        ctx.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--limit', type=int, default=20)
    ap.add_argument('--offset', type=int, default=0)
    args = ap.parse_args()

    if not GEMINI_KEY:
        sys.exit('ERR: GEMINI_API_KEY required')

    rows = [ln.rstrip('\n').split('\t') for ln in open(INPUT_TSV) if ln.strip()]
    rows = rows[args.offset:args.offset + args.limit]

    done = set()
    if Path(OUT_CSV).exists():
        with open(OUT_CSV) as f:
            done = {r['slug'] for r in csv.DictReader(f) if r.get('slug') and r.get('matched') == 'True'}
    print(f'{len(rows)} queued, {len(done)} already succeeded', file=sys.stderr)

    write_header = not Path(OUT_CSV).exists() or Path(OUT_CSV).stat().st_size == 0
    out_f = open(OUT_CSV, 'a', newline='')
    writer = csv.DictWriter(out_f, fieldnames=FIELDS, extrasaction='ignore')
    if write_header:
        writer.writeheader(); out_f.flush()

    req_count = 0
    with sync_playwright() as p:
        browser = p.chromium.launch(headless=True, proxy={'server': 'socks5://127.0.0.1:1080'})
        for i, row in enumerate(rows):
            id_, slug, name_en = row[0], row[1], row[2]
            if slug in done:
                continue

            if req_count > 0 and req_count % REQUESTS_PER_CIRCUIT == 0:
                browser.close()
                print(f'--- rotating Tor circuit (after {req_count} reqs) ---', file=sys.stderr)
                tor_new_circuit()
                time.sleep(TOR_WAIT_AFTER_NEWNYM)
                browser = p.chromium.launch(headless=True, proxy={'server': 'socks5://127.0.0.1:1080'})

            print(f'[{i+1}/{len(rows)}] {slug}: {name_en[:55]}', file=sys.stderr)
            result = search_company(browser, name_en)
            out = {'id': id_, 'slug': slug, 'name_en_input': name_en,
                   'scraped_at': time.strftime('%Y-%m-%d %H:%M:%S')}
            out.update(result)
            writer.writerow(out); out_f.flush()

            status = 'OK' if result.get('matched') else f"FAIL: {result.get('error','?')}"
            print(f'  → {status} (attempts={result.get("attempt_count",0)}, cr={result.get("cr_number","")})', file=sys.stderr)

            req_count += 1
            if result.get('error') == 'rate_limited_429':
                print('  >>> 429, rotating + longer backoff', file=sys.stderr)
                browser.close()
                tor_new_circuit()
                time.sleep(random.uniform(45, 90))
                browser = p.chromium.launch(headless=True, proxy={'server': 'socks5://127.0.0.1:1080'})
            else:
                time.sleep(random.uniform(SEARCH_DELAY_MIN, SEARCH_DELAY_MAX))

        browser.close()
    out_f.close()
    # Summary
    with open(OUT_CSV) as f:
        rdr = list(csv.DictReader(f))
    matched = sum(1 for r in rdr if r.get('matched') == 'True')
    print(f'\nTotal attempted: {len(rdr)}  matched: {matched}  success rate: {100*matched/max(1,len(rdr)):.1f}%', file=sys.stderr)


if __name__ == '__main__':
    main()
