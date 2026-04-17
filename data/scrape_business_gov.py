#!/usr/bin/env python3
"""
Scrape business.gov.om (MoCIIP Invest Easy) for enrichment of om_companies.

- Searches each company by English name
- Uses Gemini 2.5 Flash to solve BotDetect CAPTCHA (retries up to 6x)
- Extracts: CR number, status, legal form, registration date, capital,
  activities, address, phone, email (EXCLUDES owner/partner names — PDPL)
- Appends to /tmp/om_enriched.csv on each successful row
- Resumable: skips slugs already in output CSV

Usage:
    GEMINI_API_KEY=xxx python3 scrape_business_gov.py [--limit N] [--offset N]
"""
import argparse, base64, csv, json, os, re, sys, time, urllib.request
from pathlib import Path
from playwright.sync_api import sync_playwright

PORTAL = 'https://www.business.gov.om/portal/searchEstablishments'
OUT_CSV = '/tmp/om_enriched.csv'
INPUT_TSV = '/tmp/om_companies_todo.tsv'
GEMINI_KEY = os.environ.get('GEMINI_API_KEY', '')
MAX_CAPTCHA_ATTEMPTS = 6
SEARCH_DELAY_SEC = 2.5

FIELDS = [
    'id', 'slug', 'name_en_input',
    'cr_number', 'cr_status', 'legal_form',
    'name_en_official', 'name_ar_official',
    'registration_date', 'expiry_date', 'capital',
    'activities', 'address', 'phone', 'email',
    'attempt_count', 'matched', 'scraped_at'
]


def solve_captcha(png_bytes: bytes) -> str:
    """Gemini Flash OCR — ~60% first-try accuracy on BotDetect."""
    b64 = base64.b64encode(png_bytes).decode()
    body = {
        "contents": [{"parts": [
            {"text": "This CAPTCHA has 5-6 alphanumeric characters (A-Z, 0-9 only). Read each character. Output ONLY the characters, uppercase, no spaces."},
            {"inlineData": {"mimeType": "image/png", "data": b64}}
        ]}],
        "generationConfig": {"temperature": 0, "maxOutputTokens": 150,
                             "thinkingConfig": {"thinkingBudget": 0}},
    }
    url = f'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={GEMINI_KEY}'
    req = urllib.request.Request(url, data=json.dumps(body).encode(),
                                  headers={'Content-Type': 'application/json'})
    try:
        resp = urllib.request.urlopen(req, timeout=30)
        data = json.loads(resp.read())
        text = ''.join(p.get('text', '') for p in data['candidates'][0]['content'].get('parts', []))
        return re.sub(r'[^A-Za-z0-9]', '', text).upper()
    except Exception as e:
        print(f'  [captcha error] {e}', file=sys.stderr)
        return ''


def try_search(page, company_name: str) -> dict | None:
    """Returns dict of scraped fields or None if failed."""
    for attempt in range(1, MAX_CAPTCHA_ATTEMPTS + 1):
        try:
            page.goto(PORTAL, wait_until='networkidle', timeout=45000)
            time.sleep(0.8)
            img = page.locator('#exampleCaptcha_CaptchaImage').screenshot()
            captcha = solve_captcha(img)
            if len(captcha) < 4:
                continue
            page.fill('input[name="criteria.companyName"]', company_name)
            page.fill('input[name="captchaCode"]', captcha)
            # Submit via the actual Search button (Spring Web Flow needs _eventId__search)
            page.click('button[name="_eventId__search"]')
            page.wait_for_load_state('networkidle', timeout=30000)
            time.sleep(0.6)
            html = page.content()

            # CAPTCHA wrong — Arabic/English error texts
            if ('incorrect' in html.lower() and 'captcha' in html.lower()) or 'كلمة التحقق غير صحيحة' in html:
                print(f'  attempt {attempt}: CAPTCHA rejected ({captcha!r})', file=sys.stderr)
                continue

            # Parse result rows — look for the results table
            rows = re.findall(
                r'<tr[^>]*class="[^"]*(?:search-result|results?-row|tableRow)[^"]*"[^>]*>([\s\S]*?)</tr>',
                html, re.I
            )
            if not rows:
                # Fallback: any <tr> in a results table
                table = re.search(r'<table[^>]*(?:searchResults|resultsTable|results-table)[^>]*>([\s\S]*?)</table>', html, re.I)
                if table:
                    rows = re.findall(r'<tr[^>]*>([\s\S]*?)</tr>', table.group(1), re.I)

            if not rows:
                # Save HTML for inspection
                Path('/tmp/scrape').mkdir(exist_ok=True)
                Path(f'/tmp/scrape/no_results_{company_name[:30].replace(" ","_")}.html').write_text(html)
                return {'matched': False, 'attempt_count': attempt, 'cr_number': '', 'error': 'no_results_in_html'}

            # Click the first result (or the one matching best)
            try:
                page.locator('a[href*="viewEstablishment"], a.result-link, tr.search-result a').first.click(timeout=5000)
                page.wait_for_load_state('networkidle', timeout=30000)
                time.sleep(0.6)
                detail = page.content()
                return parse_detail(detail, attempt)
            except Exception as e:
                print(f'  no clickable detail link: {e}', file=sys.stderr)
                return {'matched': False, 'attempt_count': attempt, 'cr_number': '', 'error': 'no_detail_link'}
        except Exception as e:
            print(f'  attempt {attempt} exception: {e}', file=sys.stderr)
            time.sleep(2)
    return None


def parse_detail(html: str, attempt: int) -> dict:
    """Extract CR fields from the detail HTML."""
    def grab(label_patterns):
        for pat in label_patterns:
            m = re.search(rf'{pat}\s*</[^>]+>\s*<[^>]+>\s*([^<]+)', html, re.I)
            if m:
                return m.group(1).strip()
        return ''

    # Generic label-value extractor
    r = {
        'matched': True,
        'attempt_count': attempt,
        'cr_number': grab([r'Commercial Registration No\.?', r'CR Number', r'رقم السجل التجاري']),
        'cr_status': grab([r'Registration Status', r'Status', r'حالة السجل']),
        'legal_form': grab([r'Legal Form', r'Legal Type', r'الشكل القانوني']),
        'name_en_official': grab([r'Commercial Name\s*\(English\)', r'Name in English']),
        'name_ar_official': grab([r'Commercial Name\s*\(Arabic\)', r'الاسم التجاري بالعربي']),
        'registration_date': grab([r'Registration Date', r'تاريخ التسجيل']),
        'expiry_date': grab([r'Expiry Date', r'تاريخ الانتهاء']),
        'capital': grab([r'Capital', r'رأس المال']),
        'activities': grab([r'Activities', r'الأنشطة', r'Activity']),
        'address': grab([r'Address', r'العنوان']),
        'phone': grab([r'Telephone', r'Phone', r'Mobile', r'الهاتف']),
        'email': grab([r'Email', r'البريد الإلكتروني']),
    }
    return r


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--limit', type=int, default=5)
    ap.add_argument('--offset', type=int, default=0)
    ap.add_argument('--headful', action='store_true')
    args = ap.parse_args()

    if not GEMINI_KEY:
        sys.exit('ERR: GEMINI_API_KEY env var required')

    todo_path = Path(INPUT_TSV)
    if not todo_path.exists():
        sys.exit(f'ERR: expected todo list at {INPUT_TSV}')

    # Load todo
    with open(todo_path) as f:
        rows = [line.rstrip('\n').split('\t') for line in f if line.strip()]
    rows = rows[args.offset:args.offset + args.limit]

    # Resume: skip slugs already in output
    done = set()
    if Path(OUT_CSV).exists():
        with open(OUT_CSV) as f:
            done = {r['slug'] for r in csv.DictReader(f) if r.get('slug')}
    print(f'{len(rows)} to process, {len(done)} already done', file=sys.stderr)

    # Open writer
    write_header = not Path(OUT_CSV).exists()
    out_f = open(OUT_CSV, 'a', newline='')
    writer = csv.DictWriter(out_f, fieldnames=FIELDS, extrasaction='ignore')
    if write_header:
        writer.writeheader()
        out_f.flush()

    with sync_playwright() as p:
        browser = p.chromium.launch(headless=not args.headful)
        ctx = browser.new_context(
            user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0.0.0 Safari/537.36',
            locale='en-US',
            viewport={'width': 1280, 'height': 900},
        )
        page = ctx.new_page()

        for i, row in enumerate(rows):
            id_, slug, name_en = row[0], row[1], row[2]
            if slug in done:
                continue
            print(f'[{i+1}/{len(rows)}] {slug}: {name_en[:60]}', file=sys.stderr)
            result = try_search(page, name_en)
            out = {'id': id_, 'slug': slug, 'name_en_input': name_en, 'scraped_at': time.strftime('%Y-%m-%d %H:%M:%S')}
            if result:
                out.update(result)
            else:
                out.update({'matched': False, 'attempt_count': MAX_CAPTCHA_ATTEMPTS, 'cr_number': ''})
            writer.writerow(out)
            out_f.flush()
            time.sleep(SEARCH_DELAY_SEC)

        browser.close()
    out_f.close()
    print(f'Done. Output: {OUT_CSV}', file=sys.stderr)


if __name__ == '__main__':
    main()
