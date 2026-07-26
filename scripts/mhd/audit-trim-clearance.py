#!/usr/bin/env python3
"""Placement audit of the DYNAMIC text on every MHD card.

The baked artwork is raster, the employee's own text is real vector text, so
every span PyMuPDF returns from a rendered card is a dynamic field. Check each
against the card edges and against the column its siblings sit in.
"""
import fitz, glob, os, json, collections

PT_MM = 25.4/72
SAFE  = 2.0          # mm, minimum clearance to the trim for TEXT

def spans(page):
    out = []
    for b in page.get_text('dict')['blocks']:
        for l in b.get('lines', []):
            for s in l['spans']:
                t = s['text'].strip()
                if t:
                    out.append((s['bbox'], t, s['size'], s['font']))
    return out

rows = collections.defaultdict(list)
for f in sorted(glob.glob('/www/wwwroot/cardify.om/tmp/pl-*.pdf')):
    base = os.path.basename(f)[3:-4]          # tag-slug
    tag, slug = base.split('-', 1)
    d = fitz.open(f)
    for pno in range(d.page_count):
        p = d[pno]
        W, H = p.rect.width, p.rect.height
        side = 'front' if pno == 0 else 'back'
        for bbox, txt, size, font in spans(p):
            x0, y0, x1, y1 = bbox
            gl = x0*PT_MM
            gr = (W-x1)*PT_MM
            gt = y0*PT_MM
            gb = (H-y1)*PT_MM
            worst = min(gl, gr, gt, gb)
            if worst < SAFE:
                rows[(tag, slug, side)].append(
                    (round(worst,2), txt[:34], round(gl,2), round(gr,2), round(gt,2), round(gb,2)))

for key in sorted(rows):
    tag, slug, side = key
    for r in sorted(rows[key]):
        flag = 'OFF-CARD' if r[0] <= 0 else 'tight'
        print(f'{tag:5} {slug:19} {side:5} {flag:9} clr={r[0]:6.2f}mm  L{r[2]:6.2f} R{r[3]:6.2f} T{r[4]:6.2f} B{r[5]:6.2f}  {r[1]!r}')
if not rows:
    print('no dynamic text within %.1fmm of any trim edge' % SAFE)
