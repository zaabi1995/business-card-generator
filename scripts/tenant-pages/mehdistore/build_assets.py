#!/usr/bin/env python3
"""Build the public assets for https://mehdistore.cardify.om/main.

Writes into assets/tenant-pages/mehdistore/:
  logo.svg, skyline.svg      vector art lifted from Mehdi Store's own branch PDF
                              (src/mehdi-branches-page.pdf), re-based, no clip soup
  favicon.svg, favicon-32.png, apple-touch-icon.png
                              the Kufi meem from their logo + the logo's diamond
  icons-{light,solid,brands}.woff2
                              FontAwesome 7.2 Pro (design.bhd.om/fa) subset to the
                              glyphs main.php uses. Add an icon: put its codepoint
                              in ICONS below, re-run, add the ::before rule in main.php.
  og.jpg                      1200x630 link preview (WhatsApp, X, iMessage)

Needs: python3 + PyMuPDF (fitz), fontTools (pyftsubset), Pillow, playwright
(python) with chromium, rsvg-convert. Run from anywhere:
  python3 scripts/tenant-pages/mehdistore/build_assets.py
Then commit the changed assets and deploy with deploy-cardify.sh. main.php
cache-busts every asset URL with the file mtime, so no CDN purge is needed.
"""
import os, subprocess, sys, tempfile, urllib.request
import fitz

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, '..', '..', '..'))
OUT = os.path.join(REPO, 'assets', 'tenant-pages', 'mehdistore')
SRC = os.path.join(HERE, 'src', 'mehdi-branches-page.pdf')
BLUE = '#293688'
GOLD = '#A37A37'
PAGE_H = 595.276

# Regions in PDF page space (y down). Logo = the clip of their logo export.
LOGO = fitz.Rect(572.6759, PAGE_H - 549.9168, 797.74789, PAGE_H - 467.6595)
SKYLINE = fitz.Rect(0, PAGE_H - 139.0178, 841.89, PAGE_H)

FA = 'https://design.bhd.om/fa/v7.2.0/webfonts/'
ICONS = {
    'light': {  # fa-light
        'arrow-up-left': 0xE09D, 'arrow-up-right': 0xE09F, 'chevron-down': 0xF078,
        'globe': 0xF0AC, 'shirt': 0xF553, 'scissors': 0xF0C4,
    },
    'solid': {'location-dot': 0xF3C5},
    'brands': {'whatsapp': 0xF232, 'instagram': 0xF16D, 'x-twitter': 0xE61B},
}
FA_FILES = {'light': 'fa-light-300.woff2', 'solid': 'fa-solid-900.woff2', 'brands': 'fa-brands-400.woff2'}


def fmt(v):
    s = ('%.2f' % v).rstrip('0').rstrip('.')
    return '0' if s in ('-0', '') else s


def hexc(rgb):
    return '#%02x%02x%02x' % tuple(round(v * 255) for v in rgb)


def path_d(items, ox, oy):
    d, cur = [], None

    def P(pt):
        return f'{fmt(pt.x - ox)} {fmt(pt.y - oy)}'

    for it in items:
        op = it[0]
        if op in ('l', 'c'):
            a = it[1]
            if cur is None or abs(cur.x - a.x) > 1e-3 or abs(cur.y - a.y) > 1e-3:
                d.append('M' + P(a))
            if op == 'l':
                d.append('L' + P(it[2])); cur = it[2]
            else:
                d.append('C' + P(it[2]) + ' ' + P(it[3]) + ' ' + P(it[4])); cur = it[4]
        elif op == 're':
            r = it[1]
            d.append(f'M{fmt(r.x0-ox)} {fmt(r.y0-oy)}H{fmt(r.x1-ox)}V{fmt(r.y1-oy)}H{fmt(r.x0-ox)}Z'); cur = None
        elif op == 'qu':
            q = it[1]
            d.append('M' + P(q.ul) + 'L' + P(q.ur) + 'L' + P(q.lr) + 'L' + P(q.ll) + 'Z'); cur = None
    return ''.join(d)


def drawings_in(region):
    page = fitz.open(SRC)[0]
    keep = []
    for dr in page.get_drawings():
        r = dr['rect']
        if r.width > 800 and r.height > 500:
            continue  # page background
        if region.contains(r) or (region.intersects(r) and (r & region).get_area() > 0.5 * max(r.get_area(), 1e-6)):
            keep.append(dr)
    return keep


def svg_paths(drs, ox, oy, force_fill=None):
    out = []
    for dr in drs:
        d = path_d(dr['items'], ox, oy) + ('Z' if dr.get('closePath') else '')
        a = []
        if 'f' in dr['type'] and dr.get('fill') is not None:
            fc = hexc(dr['fill'])
            if fc in ('#2a3689', '#293689', '#2a3688'):
                fc = BLUE
            a.append(f'fill="{force_fill or fc}"')
            if dr.get('even_odd'):
                a.append('fill-rule="evenodd"')
        else:
            a.append('fill="none"')
        if 's' in dr['type'] and dr.get('color') is not None:
            a.append(f'stroke="{hexc(dr["color"])}" stroke-width="{fmt(dr.get("width") or 1)}"')
            lj = dr.get('lineJoin')
            if lj is not None:
                a.append('stroke-linejoin="%s"' % {0: 'miter', 1: 'round', 2: 'bevel'}.get(int(lj), 'miter'))
        out.append(f'<path d="{d}" {" ".join(a)}/>')
    return ''.join(out)


def write(name, text):
    with open(os.path.join(OUT, name), 'w') as fh:
        fh.write(text)
    print('wrote', name, len(text.encode()), 'bytes')


def build_vectors():
    logo = drawings_in(LOGO)
    write('logo.svg',
          f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {fmt(LOGO.width)} {fmt(LOGO.height)}" '
          f'role="img" aria-label="مخزن مهدي, Mehdi Store, Est. 1948"><title>مخزن مهدي, Mehdi Store, Est. 1948</title>'
          + svg_paths(logo, LOGO.x0, LOGO.y0) + '</svg>\n')
    sky = drawings_in(SKYLINE)
    write('skyline.svg',
          f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {fmt(SKYLINE.width)} {fmt(SKYLINE.height)}" '
          f'aria-hidden="true">' + svg_paths(sky, SKYLINE.x0, SKYLINE.y0) + '</svg>\n')

    # Favicon: the initial Kufi meem of "مخزن" (right end of the wordmark) and one
    # diamond from the logo's ornament rule, on royal blue.
    rel = lambda r: fitz.Rect(r.x0 - LOGO.x0, r.y0 - LOGO.y0, r.x1 - LOGO.x0, r.y1 - LOGO.y0)
    word = [d for d in logo if rel(d['rect']).x0 > 150 and rel(d['rect']).y1 < 35 and rel(d['rect']).width > 40]
    diamond = [d for d in logo if 44 <= rel(d['rect']).x0 <= 50 and rel(d['rect']).y0 >= 45 and rel(d['rect']).x1 <= 59]
    if not word or not diamond:
        sys.exit('favicon source paths not found')
    mx0, my0, mx1, my1 = 200.4, 11.0, 223.6, 34.2          # meem crop, logo space
    def icon(rx):
        s = 38.0 / (mx1 - mx0)                                # meem 38 units wide in a 64 box
        tx, ty = 13 - mx0 * s, 9 - my0 * s
        dr_ = fitz.Rect(min(rel(d['rect']).x0 for d in diamond), min(rel(d['rect']).y0 for d in diamond),
                        max(rel(d['rect']).x1 for d in diamond), max(rel(d['rect']).y1 for d in diamond))
        ds = 20.0 / dr_.width
        dtx, dty = 32 - (dr_.x0 + dr_.width / 2) * ds, 49 - (dr_.y0 + dr_.height / 2) * ds
        return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
                f'<defs><clipPath id="m"><rect x="{fmt(mx0)}" y="{fmt(my0)}" width="{fmt(mx1-mx0)}" height="{fmt(my1-my0)}"/></clipPath></defs>'
                f'<rect width="64" height="64" rx="{rx}" fill="{BLUE}"/>'
                f'<g transform="matrix({fmt(s)} 0 0 {fmt(s)} {fmt(tx)} {fmt(ty)})"><g clip-path="url(#m)">'
                + svg_paths(word, LOGO.x0, LOGO.y0, force_fill='#fff') +
                f'</g></g><g transform="matrix({fmt(ds)} 0 0 {fmt(ds)} {fmt(dtx)} {fmt(dty)})">'
                + svg_paths(diamond, LOGO.x0, LOGO.y0, force_fill=GOLD) + '</g></svg>\n')
    write('favicon.svg', icon(14))
    square = os.path.join(tempfile.gettempdir(), 'mehdi-touch.svg')
    with open(square, 'w') as fh:
        fh.write(icon(0))
    subprocess.run(['rsvg-convert', '-w', '32', '-h', '32', os.path.join(OUT, 'favicon.svg'),
                    '-o', os.path.join(OUT, 'favicon-32.png')], check=True)
    subprocess.run(['rsvg-convert', '-w', '180', '-h', '180', square,
                    '-o', os.path.join(OUT, 'apple-touch-icon.png')], check=True)
    print('wrote favicon-32.png, apple-touch-icon.png')


def build_icons():
    tmp = tempfile.mkdtemp()
    for style, glyphs in ICONS.items():
        src = os.path.join(tmp, FA_FILES[style])
        req = urllib.request.Request(FA + FA_FILES[style], headers={'User-Agent': 'Mozilla/5.0 (cardify build_assets)'})
        with urllib.request.urlopen(req, timeout=60) as r, open(src, 'wb') as fh:
            fh.write(r.read())
        uni = ','.join('U+%04X' % c for c in sorted(glyphs.values()))
        dst = os.path.join(OUT, f'icons-{style}.woff2')
        subprocess.run(['pyftsubset', src, f'--unicodes={uni}', '--flavor=woff2',
                        '--layout-features=', '--no-hinting', '--desubroutinize',
                        f'--output-file={dst}'], check=True)
        print('wrote', os.path.basename(dst), os.path.getsize(dst), 'bytes')


def build_og():
    from playwright.sync_api import sync_playwright
    from PIL import Image
    html = os.path.join(HERE, 'og-card.html')
    big = os.path.join(tempfile.gettempdir(), 'mehdi-og@2x.png')
    with sync_playwright() as p:
        try:
            b = p.chromium.launch()
        except Exception:
            # Playwright's pinned browser build is often missing; use any installed one.
            import glob
            found = sorted(glob.glob(os.path.expanduser(
                '~/Library/Caches/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-*/chrome-headless-shell')))
            if not found:
                raise
            b = p.chromium.launch(executable_path=found[-1])
        pg = b.new_page(viewport={'width': 1200, 'height': 630}, device_scale_factor=2)
        pg.goto('file://' + html)
        pg.wait_for_load_state('networkidle')
        pg.evaluate('document.fonts.ready')
        pg.wait_for_timeout(900)
        pg.screenshot(path=big)
        b.close()
    im = Image.open(big).convert('RGB').resize((1200, 630), Image.LANCZOS)
    dst = os.path.join(OUT, 'og.jpg')
    im.save(dst, 'JPEG', quality=88, optimize=True, progressive=True)
    print('wrote og.jpg', im.size, os.path.getsize(dst), 'bytes')


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    steps = sys.argv[1:] or ['vectors', 'icons', 'og']
    if 'vectors' in steps:
        build_vectors()
    if 'icons' in steps:
        build_icons()
    if 'og' in steps:
        build_og()
