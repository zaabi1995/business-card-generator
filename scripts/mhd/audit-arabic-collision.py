#!/usr/bin/env python3
"""Does any dynamic Arabic span cross into the baked contact column?"""
import fitz, glob, os
PX=4.16667
LIMITS={'default':453.2}
bad=[]
for f in sorted(glob.glob('/www/wwwroot/cardify.om/tmp/pl-*.pdf')):
    base=os.path.basename(f)[3:-4]; tag,slug=base.split('-',1)
    d=fitz.open(f)
    if d.page_count<2: continue
    p=d[1]
    for b in p.get_text('dict')['blocks']:
        for l in b.get('lines',[]):
            for s in l['spans']:
                t=s['text'].strip()
                if not t or t[0].isascii(): continue
                x0=s['bbox'][0]*PX; y0=s['bbox'][1]*PX
                if y0 < 380 and x0 < LIMITS['default']:
                    bad.append((tag,slug,round(x0,1),round(y0,1),t[:26]))
if bad:
    for r in bad: print(f'{r[0]:5} {r[1]:19} x0={r[2]:7.1f} y={r[3]:6.1f} OVERLAPS contact column  {r[4]!r}')
else:
    print('no Arabic identity text crosses the baked contact column (limit x=453.2px)')
