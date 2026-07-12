#!/usr/bin/env python3
"""Build the MHD Automotive (ACERE) card template from the Imran Safdar Khan
print file. 2-sided EN/AR, 90x55mm. Redacts person data + the personal
"(Direct)" Tel line (per-person, not a division line - never bake), keeps the
+968 / +968-AR mobile prefix baked via digits-only redaction, closes the Tel
gap by shifting the contact block up one line. Dynamic: name/position/subtitle
(EN+AR) + mobile(+AR) + email."""
import fitz, json, subprocess
from PIL import Image, ImageDraw

SRC = '/tmp/mhdgb/eep_print_imran.pdf'
P = 300/72.0      # fields px per pt
D = 1200/72.0     # bg px per pt
SHIFT = 9         # pt, close the removed-Tel-line gap

src = fitz.open(SRC)
clips = {'front': (0, fitz.Rect(22.2, 10.9, 277.3, 166.8)),
         'back':  (1, fitz.Rect(27.0, 10.9, 282.1, 166.8))}
out = fitz.open()
for side, (pno, r) in clips.items():
    pg = out.new_page(width=r.width, height=r.height)
    pg.show_pdf_page(fitz.Rect(0, 0, r.width, r.height), src, pno, clip=r)
out.save('auto-1up.pdf')

doc = fitz.open('auto-1up.pdf')
REDACT = {
    0: ['Imran Safdar Khan', 'Sr. Sales Executive', 'Construction Equipment',
        'imran.k@mhd.co.om', 'Tel:', '+968 26841087 (Direct)'],
    1: ['ﻋﻤﺮان ﺳﺎﻓﺪار ﺧﺎن', 'ﺗﻨﻔﻴﺬي ﻣﺒﻴﻌﺎت أول', 'ﻣﻌﺪات اﻟﺒﻨﺎء',
        'imran.k@mhd.co.om', 'ﻫﺎﺗﻒ:', '+٧٨٠١٤٨٦٢ ٨٦٩', 'ﻣﺒﺎﺷﺮ'],
}
spans = {}   # keep pre-redaction geometry
for pno in (0, 1):
    pg = doc[pno]
    spans[pno] = []
    for b in pg.get_text('dict')['blocks']:
        for l in b.get('lines', []):
            for s in l.get('spans', []):
                spans[pno].append(s)
                if any(t in s['text'] for t in REDACT[pno]):
                    rr = fitz.Rect(s['bbox'])
                    ins = min(3.0, rr.height/2 - 0.5)
                    pg.add_redact_annot(rr + (0, ins, 0, -ins), fill=(1, 1, 1))
    # mobile: redact ONLY the digits so the +968 prefix stays baked
    if pno == 0:
        # LTR: digits sit AFTER the "+968 " prefix; search is reliable here
        hits = pg.search_for('71557173')
        if not hits:
            raise SystemExit('front digits not found')
        mrect = hits[0]
        pg.add_redact_annot(mrect + (-0.5, -0.8, 1.0, 0.8), fill=(1, 1, 1))
    else:
        # RTL: search_for is unreliable on reversed runs. Geometric split:
        # digits occupy span start .. span end minus the measured prefix.
        msp = next(x for x in spans[pno] if '٣٧١٧٥٥١٧' in x['text'])
        mb = fitz.Rect(msp['bbox'])
        pw = fitz.Font(fontfile='/tmp/mhdgb/FrutigerLTArabic-45Light.ttf')\
                 .text_length('+٩٦٨ ', fontsize=msp['size'])
        # visual order: [+٩٦٨][digits][gap][نقال] -> digits are the RIGHT part
        mrect = fitz.Rect(mb.x0 + pw, mb.y0, mb.x1, mb.y1)
        pg.add_redact_annot(mrect + (-1.0, 2.5, 1.0, -2.5), fill=(1, 1, 1))
    spans[pno].append({'text': '__MOBILE__', 'bbox': list(mrect),
                       'size': 7.0, 'font': 'Light', 'color': 0x3c7dca})
    pg.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
doc.save('auto-clean.pdf')
subprocess.run(['pdftoppm', '-png', '-r', '1200', 'auto-clean.pdf', 'auto-clean'], check=True)

def find(pno, needle):
    for s in spans[pno]:
        if needle in s['text']:
            return s
    raise SystemExit(f'span {needle!r} missing p{pno}')

def fld(s, ff, wt, fill, dy=0.0, width=None, rtl_w=None):
    l, t, r, b = s['bbox']
    if rtl_w:
        width = rtl_w
        l = r - rtl_w
    return {'enabled': True, 'is_static': False, 'render_in_bg': False,
            'label': None, 'detected_text': '',
            'x': round(l*P), 'y': round((t+dy)*P),
            'width': round((width if width else max((r-l), 30))*P),
            'height': round((b-t)*P), 'fontSize': round(s['size']*P),
            'fontFamily': ff, 'fontWeight': wt, 'italic': False,
            'fontStyle': 'normal', 'fill': fill, 'color': fill,
            'textAlign': ('right' if rtl_w else 'left'),
            'originX': 'left', 'originY': 'top'}

BLUE = '#3c7dca'; NAVY = '#0f1f5c'
front = {
    'name_en':       fld(find(0, 'Imran Safdar Khan'), 'FrutigerLTStd-Black', 900, NAVY, width=110),
    'position_en':   fld(find(0, 'Sr. Sales Executive'), 'FrutigerLTStd', 400, BLUE, width=110),
    'position_en_2': fld(find(0, 'Construction Equipment'), 'FrutigerLTStd-Black', 900, BLUE, width=110),
    'mobile':        fld(find(0, '__MOBILE__'), 'FrutigerLTStd', 300, BLUE, dy=-SHIFT, width=45),
    'email':         fld(find(0, 'imran.k@mhd.co.om'), 'FrutigerLTStd', 300, BLUE, dy=-SHIFT, width=90),
    'qr_code':       {'enabled': False, 'x': round(19.6*P), 'y': round(116*P), 'size': round(30*P)},
}
back = {
    'name_ar':       fld(find(1, 'ﻋﻤﺮان ﺳﺎﻓﺪار ﺧﺎن'), 'FrutigerLTArabic', 900, NAVY, rtl_w=140),
    'position_ar':   fld(find(1, 'ﺗﻨﻔﻴﺬي ﻣﺒﻴﻌﺎت أول'), 'FrutigerLTArabic', 400, BLUE, rtl_w=120),
    'position_ar_2': fld(find(1, 'ﻣﻌﺪات اﻟﺒﻨﺎء'), 'FrutigerLTArabic', 900, BLUE, rtl_w=100),
    'mobile_ar':     fld(find(1, '__MOBILE__'), 'FrutigerLTArabic', 300, BLUE, dy=-SHIFT+2, rtl_w=45),
    'email':         fld(find(1, 'imran.k@mhd.co.om'), 'FrutigerLTStd', 300, BLUE, dy=-SHIFT+2, width=90),
    'qr_code':       {'enabled': False, 'x': round(208*P), 'y': round(112*P), 'size': round(30*P)},
}
json.dump(front, open('auto-front.json', 'w'), ensure_ascii=False)
json.dump(back, open('auto-back.json', 'w'), ensure_ascii=False)
print('fields:', list(front), list(back))

# bg post-work: white the black-bar remnant (front top-right) + shift the
# contact block up SHIFT pt to close the removed-Tel-line gap.
for i, (side, xr) in enumerate([('front', (120, 260)), ('back', (8, 125))], 1):
    im = Image.open(f'auto-clean-{i}.png').convert('RGB')
    dr = ImageDraw.Draw(im)
    if side == 'front':
        dr.rectangle((int(195*D), 0, im.width, int(10.2*D)), fill=(255, 255, 255))
    # shift band: everything from the first post-Tel line down to below www
    y0, y1 = int(119*D), int(151*D)
    x0, x1 = int(xr[0]*D), int(xr[1]*D)
    band = im.crop((x0, y0, x1, y1))
    dr.rectangle((x0, y0, x1, y1), fill=(255, 255, 255))
    im.paste(band, (x0, y0 - int(SHIFT*D)))
    if side == 'front':
        dr.rectangle((int(114*D), int(128*D), int(127*D), int(146*D)), fill=(255, 255, 255))
    im.save(f'auto-clean-{i}.png')
print('bg cleaned + shifted')
