#!/usr/bin/env python3
"""Cardify card-preset renderer.

Builds a branded 1050x600 front or back for one of the built-in presets,
parameterised by a brand context (logo, colours, bilingual name/title/org,
contacts). SVG -> rsvg-convert. Arabic shapes via Noto (pango/harfbuzz).

This is the single source of truth for both gallery thumbnails and the
design that gets baked when a company applies a preset. Colours are passed
through a WCAG safe-accent guard so white text never lands on a near-white
fill (mirrors includes/ColorContrast.php::safeAccent).

Usage:
  render-preset.py --brand brand.json --out front.png --preset corp_left --side front
  render-preset.py --brand brand.json --out /dir --preset all --side front   # all presets
"""
import base64, json, subprocess, tempfile, os, argparse, html

W, H = 1050, 600
AR_FONT = "Noto Sans Arabic"
EN_FONT = "DejaVu Sans"  # bake font; live Fabric cards use Inter via fonts.bhd.om


def _lum(hexs):
    h = hexs.lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    if len(h) != 6:
        return 0.0

    def ch(n):
        s = n / 255.0
        return s / 12.92 if s <= 0.03928 else ((s + 0.055) / 1.055) ** 2.4
    r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
    return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b)


def _ratio(a, b):
    la, lb = _lum(a), _lum(b)
    hi, lo = max(la, lb), min(la, lb)
    return (hi + 0.05) / (lo + 0.05)


def safe_accent(hexs, trigger=2.5, target=3.2):
    h = (hexs or '').lstrip('#')
    if len(h) == 3:
        h = ''.join(c * 2 for c in h)
    if len(h) != 6:
        return '#009bc1'
    if _ratio('#' + h, '#ffffff') >= trigger:
        return '#' + h
    r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
    if abs(r - g) < 8 and abs(g - b) < 8 and abs(r - b) < 8:
        return '#334155'
    for _ in range(40):
        cur = '#%02x%02x%02x' % (r, g, b)
        if _ratio(cur, '#ffffff') >= target:
            return cur
        r, g, b = int(r * 0.9), int(g * 0.9), int(b * 0.9)
        if r + g + b == 0:
            return '#1f2937'
    return '#%02x%02x%02x' % (r, g, b)


def logo_data_uri(path):
    """Shave any baked frame, knock out the near-white bg, return data URI."""
    if not path or not os.path.exists(path):
        return ''
    tmp = tempfile.NamedTemporaryFile(suffix='.png', delete=False).name
    subprocess.run(['convert', path, '-shave', '4x4', '+repage',
                    '-fuzz', '8%', '-transparent', '#f1f6f8',
                    '-fuzz', '6%', '-transparent', 'white', tmp],
                   check=False, capture_output=True)
    src = tmp if os.path.exists(tmp) and os.path.getsize(tmp) else path
    b64 = base64.b64encode(open(src, 'rb').read()).decode()
    try:
        os.unlink(tmp)
    except OSError:
        pass
    return "data:image/png;base64," + b64


def esc(s):
    return html.escape(str(s or ''), quote=True)


def T(x, y, size, fill, txt, *, bold=False, anchor='start', rtl=False, family=None, opacity=None):
    if not str(txt or '').strip():
        return ''
    fam = family or (AR_FONT if rtl else EN_FONT)
    w = ' font-weight="bold"' if bold else ''
    # SVG text-anchor is relative to the inline base direction. For RTL swap
    # start<->end so the requested VISUAL edge (right-align='end') lands right.
    if rtl:
        anchor = {'start': 'end', 'end': 'start', 'middle': 'middle'}.get(anchor, anchor)
    a = f' text-anchor="{anchor}"'
    d = ' direction="rtl"' if rtl else ''
    o = f' opacity="{opacity}"' if opacity is not None else ''
    return (f'<text x="{x}" y="{y}" font-family="{fam}" font-size="{size}"'
            f' fill="{fill}"{w}{a}{d}{o}>{esc(txt)}</text>')


def IMG(href, x, y, w, h, par='xMidYMid meet'):
    if not href:
        return ''
    return f'<image x="{x}" y="{y}" width="{w}" height="{h}" xlink:href="{href}" preserveAspectRatio="{par}"/>'


def chip(x, y, w, h, fill='#ffffff', r=16):
    return f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{r}" fill="{fill}"/>'


# ---- preset FRONT builders. Each returns inner SVG over a white base. ----
def p_corp_left(b, L, p, s, bil):
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         f'<rect x="0" y="0" width="16" height="{H}" fill="{p}"/>',
         IMG(L, 70, 56, 430, 158, 'xMinYMid meet'),
         f'<rect x="70" y="246" width="430" height="4" fill="{s}"/>']
    y = 308
    o.append(T(70, y, 46, p, b['name_en'], bold=True)); y += 42
    if bil:
        o.append(T(70, y, 30, p, b['name_ar'], bold=True, rtl=True)); y += 36
    o.append(T(70, y, 23, s, b['title_en'])); y += 34
    if bil:
        o.append(T(70, y, 21, s, b['title_ar'], rtl=True)); y += 30
    o.append(T(70, 470, 22, '#2b2b2b', b['phone']))
    o.append(T(70, 504, 22, '#2b2b2b', b['email']))
    o.append(f'<rect x="0" y="572" width="{W}" height="28" fill="{p}"/>')
    return ''.join(o)


def p_centered_min(b, L, p, s, bil):
    cx = W / 2
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>', IMG(L, cx - 180, 52, 360, 132)]
    o.append(T(cx, 300, 46, p, b['name_en'], bold=True, anchor='middle'))
    yy = 300
    if bil:
        o.append(T(cx, 338, 30, p, b['name_ar'], bold=True, anchor='middle', rtl=True)); yy = 338
    o.append(f'<rect x="{cx-90}" y="{yy+18}" width="180" height="3" fill="{s}"/>')
    o.append(T(cx, yy + 62, 23, s, b['title_en'], anchor='middle'))
    o.append(T(cx, yy + 118, 21, '#2b2b2b', b['phone'], anchor='middle'))
    o.append(T(cx, yy + 150, 21, '#2b2b2b', b['email'], anchor='middle'))
    return ''.join(o)


def p_bold_band(b, L, p, s, bil):
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         f'<rect x="0" y="0" width="{W}" height="210" fill="{p}"/>',
         chip(70, 46, 330, 118), IMG(L, 90, 62, 290, 86, 'xMinYMid meet'),
         f'<rect x="0" y="210" width="{W}" height="6" fill="{s}"/>']
    y = 292
    o.append(T(70, y, 44, p, b['name_en'], bold=True)); y += 40
    if bil:
        o.append(T(70, y, 28, p, b['name_ar'], bold=True, rtl=True)); y += 34
    o.append(T(70, y, 22, s, b['title_en'])); y += 46
    o.append(T(70, y, 21, '#2b2b2b', b['phone']))
    o.append(T(70, y + 32, 21, '#2b2b2b', b['email']))
    return ''.join(o)


def p_split_v(b, L, p, s, bil):
    pw = 400
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         f'<rect x="0" y="0" width="{pw}" height="{H}" fill="{p}"/>',
         chip(60, 210, 280, 180), IMG(L, 82, 238, 236, 124)]
    x = pw + 60; y = 250
    o.append(T(x, y, 44, p, b['name_en'], bold=True)); y += 40
    if bil:
        o.append(T(W - 60, y, 28, p, b['name_ar'], bold=True, anchor='end', rtl=True)); y += 36
    o.append(T(x, y, 22, s, b['title_en'])); y += 52
    o.append(T(x, y, 21, '#2b2b2b', b['phone']))
    o.append(T(x, y + 32, 21, '#2b2b2b', b['email']))
    return ''.join(o)


def p_monogram(b, L, p, s, bil):
    ini = ''.join([w[0] for w in str(b['name_en']).split()[:2]]).upper()
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         T(W - 40, 470, 420, p, ini, bold=True, anchor='end', opacity=0.06),
         IMG(L, 70, 56, 400, 148, 'xMinYMid meet'),
         f'<rect x="70" y="250" width="120" height="5" fill="{s}"/>']
    y = 312
    o.append(T(70, y, 46, p, b['name_en'], bold=True)); y += 40
    if bil:
        o.append(T(70, y, 28, p, b['name_ar'], bold=True, rtl=True)); y += 34
    o.append(T(70, y, 22, s, b['title_en'])); y += 50
    o.append(T(70, y, 21, '#2b2b2b', b['phone']))
    o.append(T(70, y + 32, 21, '#2b2b2b', b['email']))
    return ''.join(o)


def p_biling_stacked(b, L, p, s, bil):
    cx = W / 2
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>', IMG(L, cx - 170, 48, 340, 124),
         f'<rect x="{cx-1}" y="210" width="2" height="300" fill="{s}"/>']
    o += [T(70, 260, 38, p, b['name_en'], bold=True), T(70, 298, 21, s, b['title_en']),
          T(70, 360, 20, '#2b2b2b', b['phone']), T(70, 392, 20, '#2b2b2b', b['email'])]
    o += [T(W - 70, 260, 34, p, b['name_ar'], bold=True, anchor='end', rtl=True),
          T(W - 70, 298, 20, s, b['title_ar'], anchor='end', rtl=True),
          T(W - 70, 360, 20, '#2b2b2b', b['org_ar'], anchor='end', rtl=True)]
    return ''.join(o)


def p_biling_corp(b, L, p, s, bil):
    return p_corp_left(b, L, p, s, True)


def p_biling_band(b, L, p, s, bil):
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         f'<rect x="0" y="0" width="{W}" height="196" fill="{p}"/>',
         chip(70, 44, 300, 108), IMG(L, 88, 58, 264, 80, 'xMinYMid meet'),
         f'<rect x="0" y="196" width="{W}" height="6" fill="{s}"/>']
    o += [T(70, 270, 40, p, b['name_en'], bold=True),
          T(W - 70, 270, 32, p, b['name_ar'], bold=True, anchor='end', rtl=True),
          T(70, 308, 21, s, b['title_en']), T(W - 70, 308, 20, s, b['title_ar'], anchor='end', rtl=True),
          T(70, 396, 21, '#2b2b2b', b['phone']), T(70, 428, 21, '#2b2b2b', b['email'])]
    return ''.join(o)


def p_gov_formal(b, L, p, s, bil):
    cx = W / 2
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>', IMG(L, cx - 170, 44, 340, 124),
         f'<rect x="80" y="196" width="{W-160}" height="4" fill="{p}"/>',
         f'<rect x="80" y="206" width="{W-160}" height="2" fill="{s}"/>']
    o += [T(cx, 262, 40, p, b['name_en'], bold=True, anchor='middle'),
          T(cx, 300, 30, p, b['name_ar'], bold=True, anchor='middle', rtl=True),
          T(cx, 336, 21, s, b['title_en'], anchor='middle'),
          T(cx, 366, 20, s, b['title_ar'], anchor='middle', rtl=True),
          T(cx, 430, 21, '#2b2b2b', f"{b['phone']}   |   {b['email']}", anchor='middle'),
          f'<rect x="0" y="566" width="{W}" height="34" fill="{p}"/>']
    return ''.join(o)


def p_biling_split(b, L, p, s, bil):
    pw = 410
    o = [f'<rect width="{W}" height="{H}" fill="#ffffff"/>',
         f'<rect x="0" y="0" width="{pw}" height="{H}" fill="{p}"/>',
         chip(55, 150, 300, 170), IMG(L, 75, 176, 260, 118),
         T(pw / 2, 400, 26, '#ffffff', b['org_ar'], anchor='middle', rtl=True)]
    x = pw + 50; y = 250
    o.append(T(x, y, 42, p, b['name_en'], bold=True)); y += 38
    o.append(T(W - 50, y, 30, p, b['name_ar'], bold=True, anchor='end', rtl=True)); y += 40
    o.append(T(x, y, 21, s, b['title_en'])); y += 54
    o.append(T(x, y, 20, '#2b2b2b', b['phone']))
    o.append(T(x, y + 30, 20, '#2b2b2b', b['email']))
    return ''.join(o)


# ---- coordinating BACK (brand panel + logo + org). One per family. ----
def back_panel(b, L, p, s, bil):
    o = [f'<rect width="{W}" height="{H}" fill="{p}"/>',
         chip(280, 165, 490, 230), IMG(L, 320, 200, 410, 160, 'xMidYMid meet'),
         f'<rect x="0" y="558" width="{W}" height="6" fill="{s}"/>']
    if bil:
        o.append(T(W / 2, 452, 26, '#ffffff', b['org_ar'], anchor='middle', rtl=True))
        o.append(T(W / 2, 492, 20, '#ffffff', b['org_en'], anchor='middle', opacity=0.85))
    else:
        o.append(T(W / 2, 460, 24, '#ffffff', b['org_en'], anchor='middle'))
    return ''.join(o)


PRESETS = [
    ('corp_left',      'Corporate Left Bar',  False, p_corp_left),
    ('centered_min',   'Centered Minimal',    False, p_centered_min),
    ('bold_band',      'Bold Header Band',    False, p_bold_band),
    ('split_v',        'Split Vertical',      False, p_split_v),
    ('monogram',       'Monogram Modern',     False, p_monogram),
    ('biling_stacked', 'Bilingual Two-Column', True, p_biling_stacked),
    ('biling_corp',    'Bilingual Corporate', True,  p_biling_corp),
    ('biling_band',    'Bilingual Band',      True,  p_biling_band),
    ('gov_formal',     'Government Formal',   True,  p_gov_formal),
    ('biling_split',   'Bilingual Split',     True,  p_biling_split),
]
PMAP = {pid: (label, bil, fn) for pid, label, bil, fn in PRESETS}


def render(preset, brand, out, side='front', logo_uri=None):
    label, bil, fn = PMAP[preset]
    L = logo_uri if logo_uri is not None else logo_data_uri(brand.get('logo', ''))
    p = safe_accent(brand.get('primary', '#204080'))
    s = safe_accent(brand.get('secondary', '#00b060'))
    body = (back_panel if side == 'back' else fn)(brand, L, p, s, bil)
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" '
           f'width="{W}" height="{H}" viewBox="0 0 {W} {H}">{body}</svg>')
    sf = tempfile.NamedTemporaryFile(suffix='.svg', delete=False, mode='w')
    sf.write(svg); sf.close()
    subprocess.run(['rsvg-convert', '--unlimited', '-w', str(W), '-h', str(H), sf.name, '-o', out], check=True)
    os.unlink(sf.name)


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('--brand', required=True)
    ap.add_argument('--out', required=True)
    ap.add_argument('--preset', default='all')
    ap.add_argument('--side', default='front', choices=['front', 'back'])
    a = ap.parse_args()
    brand = json.load(open(a.brand))
    luri = logo_data_uri(brand.get('logo', ''))
    ids = [a.preset] if a.preset != 'all' else [p[0] for p in PRESETS]
    for pid in ids:
        out = a.out if a.preset != 'all' else os.path.join(a.out, f'{pid}_{a.side}.png')
        render(pid, brand, out, side=a.side, logo_uri=luri)
        print('rendered', pid, a.side, '->', out)
