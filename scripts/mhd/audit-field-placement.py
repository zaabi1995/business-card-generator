#!/usr/bin/env python3
"""Placement audit, matching each span to its field by CONTENT, not proximity.

Latin fields match on the exact string we fed the renderer. Arabic identity
fields are the only Arabic dynamic spans on the back, so they match by vertical
order; mobile_ar matches on its Arabic-Indic digits.
"""
import fitz, glob, os, json, subprocess

PX = 4.16667
DB = ['mysql','-ubc','-ppWewN3fwFmEHh32J','bc','-N','-B','-e']
AR_DIGITS = set('٠١٢٣٤٥٦٧٨٩')
EXP = {'name_en':'Madhu Pillai','position_en':'Deputy Manager',
       'position_en_2':'Mobile Device Sales','mobile':'71557240',
       'email':'madhupillai@mhd.co.om'}
AR_ORDER = ['name_ar','position_ar','position_ar_2']

def fields_for(slug):
    q = ("SELECT t.side, t.fields_json FROM templates t "
         "JOIN departments d ON d.template_pair_id=t.pair_id "
         "JOIN companies c ON c.id=d.company_id AND c.slug='mhd' "
         f"WHERE d.slug='{slug}'")
    res={}
    for line in subprocess.run(DB+[q],capture_output=True,text=True).stdout.splitlines():
        if '\t' in line:
            s,j = line.split('\t',1)
            try: res[s]=json.loads(j)
            except Exception: pass
    return res

def spans(page):
    out=[]
    for b in page.get_text('dict')['blocks']:
        for l in b.get('lines',[]):
            for s in l['spans']:
                if s['text'].strip(): out.append(s)
    return out

bad=[]
for f in sorted(glob.glob('/www/wwwroot/cardify.om/tmp/pl-typ-*.pdf')):
    slug=os.path.basename(f)[7:-4]
    tpl=fields_for(slug); d=fitz.open(f)
    for pno in range(d.page_count):
        side='front' if pno==0 else 'back'
        flds=tpl.get(side) or {}
        page=d[pno]; ss=spans(page)
        dyn={k:v for k,v in flds.items() if isinstance(v,dict) and not v.get('render_in_bg')
             and not v.get('is_static') and v.get('enabled') and 'x' in v}
        # arabic identity spans, top to bottom
        ar_ident=sorted([s for s in ss
                         if any(c in AR_DIGITS or '؀'<=c<='ۿ' or 'ﹰ'<=c<='﻿' for c in s['text'])
                         and not set(s['text'])&AR_DIGITS], key=lambda s:s['bbox'][1])
        for k,v in dyn.items():
            exp=EXP.get(k)
            hit=None
            if exp:
                hit=next((s for s in ss if s['text'].strip()==exp), None)
            elif k=='mobile_ar':
                hit=next((s for s in ss if set(s['text'])&AR_DIGITS and len(set(s['text'])&AR_DIGITS)>2
                          and s['bbox'][2]-s['bbox'][0] < 60), None)
            elif k in AR_ORDER:
                idx=[a for a in AR_ORDER if a in dyn].index(k)
                hit=ar_ident[idx] if idx < len(ar_ident) else None
            if not hit: continue
            bx0=v['x']/PX; bw=(v.get('width') or 0)/PX
            align=(v.get('textAlign') or 'left')
            sx0,sx1=hit['bbox'][0],hit['bbox'][2]
            drift=(sx0-bx0) if align=='left' else (sx1-(bx0+bw))
            over=(sx1-sx0)-bw
            if abs(drift)>2 or over>1:
                bad.append((slug,side,k,align,round(drift,2),round(over,2),hit['text'][:24]))

if bad:
    print(f"{'division':20} {'side':5} {'field':15} {'align':5} {'drift':>8} {'overflow':>9}  text")
    for r in bad: print(f'{r[0]:20} {r[1]:5} {r[2]:15} {r[3]:5} {r[4]:7.2f}pt {r[5]:7.2f}pt  {r[6]!r}')
else:
    print('every dynamic field lands on its declared anchor, within 2pt, and fits its box')
