#!/usr/bin/env python3
"""Build the MHD Logistics card template: redact the sample person's data from
the clipped 1-up -> clean bg; construct front+back fields_json (dynamic person
fields, everything else baked). Logistics is its own brand (no ITICS banner,
mhdlogistics.com, no Tel/Fax - mobile+email only)."""
import fitz, json, subprocess, os

SRC = 'logistics-1up.pdf'
PT = 300/72.0  # px per pt (300-DPI canvas)

# person spans to redact from the bg (become dynamic fields)
REDACT = ['Mohammad Kamran Usmani','Senior Finance Manager','92302410',
          'Kamran.usmani@mhdlogistics.com','ﻣﺤﻤﺪ ﻛﺎﻣﺮان ﻋﺜﻤﺎﻧﻲ','ﻣﺪﻳﺮ ﻣﺎﻟﻲ أول','٠١٤٢٠٣٢٩']

def find(pg, needle):
    for b in pg.get_text('dict')['blocks']:
        for l in b.get('lines',[]):
            for s in l.get('spans',[]):
                if needle in s['text']:
                    return s['bbox']
    return None

doc = fitz.open(SRC)
# redact person text -> clean bg
for pno in (0,1):
    pg = doc[pno]
    for b in pg.get_text('dict')['blocks']:
        for l in b.get('lines',[]):
            for s in l.get('spans',[]):
                if any(r in s['text'] for r in REDACT):
                    pg.add_redact_annot(fitz.Rect(s['bbox']), fill=(1,1,1))
    pg.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
doc.save('logistics-clean.pdf')
subprocess.run(['pdftoppm','-png','-r','1200','logistics-clean.pdf','logi-clean'],check=True)
print('clean bg rendered')

def fld(bbox, key, ff, wt, fill, static=False):
    l,t,r,b = bbox
    return key, {
        'enabled': True, 'is_static': static, 'render_in_bg': static,
        'label': None, 'detected_text': '',
        'x': round(l*PT), 'y': round(t*PT), 'width': round((r-l)*PT), 'height': round((b-t)*PT),
        'fontSize': round(s_size(bbox)*PT) if False else round((b-t)*PT*0.78),
        'fontFamily': ff, 'fontWeight': wt, 'italic': False, 'fontStyle': 'normal',
        'fill': fill, 'color': fill, 'textAlign': 'left', 'originX': 'left', 'originY': 'top',
    }

# re-read spans to get precise bbox + size
doc2 = fitz.open(SRC)
def span(pno, needle):
    for b in doc2[pno].get_text('dict')['blocks']:
        for l in b.get('lines',[]):
            for s in l.get('spans',[]):
                if needle in s['text']:
                    return s
    return None

def mkfield(pno, needle, key, ff, wt, fill):
    s = span(pno, needle); l,t,r,b = s['bbox']
    return key, {
        'enabled': True, 'is_static': False, 'render_in_bg': False,
        'label': None, 'detected_text': '',
        'x': round(l*PT), 'y': round(t*PT), 'width': round(max((r-l)*PT, 120)), 'height': round((b-t)*PT),
        'fontSize': round(s['size']*PT), 'fontFamily': ff, 'fontWeight': wt,
        'italic': False, 'fontStyle': 'normal',
        'fill': fill, 'color': fill, 'textAlign': 'left', 'originX': 'left', 'originY': 'top',
    }

front = dict([
    mkfield(0,'Mohammad Kamran Usmani','name_en','FrutigerLTStd-Black',900,'#0f1f5c'),
    mkfield(0,'Senior Finance Manager','position_en','FrutigerLTStd',700,'#0662ae'),
    mkfield(0,'92302410','mobile','FrutigerLTStd',400,'#0a68b3'),
    mkfield(0,'Kamran.usmani@mhdlogistics.com','email','FrutigerLTStd',400,'#0a68b3'),
])
back = dict([
    mkfield(1,'ﻣﺤﻤﺪ ﻛﺎﻣﺮان ﻋﺜﻤﺎﻧﻲ','name_ar','FrutigerLTArabic',900,'#0f1f5c'),
    mkfield(1,'ﻣﺪﻳﺮ ﻣﺎﻟﻲ أول','position_ar','FrutigerLTArabic',700,'#0662ae'),
    mkfield(1,'٠١٤٢٠٣٢٩','mobile_ar','FrutigerLTArabic',400,'#0a68b3'),
    mkfield(1,'Kamran.usmani@mhdlogistics.com','email','FrutigerLTStd',400,'#0a68b3'),
])
json.dump(front, open('logi-front.json','w'), ensure_ascii=False)
json.dump(back, open('logi-back.json','w'), ensure_ascii=False)
print('front fields:', list(front.keys()))
print('back fields:', list(back.keys()))
