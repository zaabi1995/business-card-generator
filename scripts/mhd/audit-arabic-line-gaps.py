#!/usr/bin/env python3
"""Real glyph ink gaps between the three identity lines, Arabic back.
Font bboxes lie for Arabic (inflated ascent/descent), so measure the raster."""
import fitz, glob, os, numpy as np
from PIL import Image
PX=4.16667
print(f"{'division':20} {'gap name->title':>16} {'gap title->sub':>15}")
for f in sorted(glob.glob('/www/wwwroot/cardify.om/tmp/gap-*.pdf')):
    slug=os.path.basename(f)[4:-4]
    d=fitz.open(f)
    if d.page_count<2: print(f'{slug:20} (no back)'); continue
    p=d[1]
    pix=p.get_pixmap(dpi=300)
    im=Image.frombytes('RGB',(pix.width,pix.height),pix.samples).convert('L')
    a=np.array(im); H,W=a.shape
    S=H/(p.rect.height*PX)
    col=a[:, int(W*0.45):]                      # Arabic identity column
    band=col[int(200*S):int(430*S), :]
    rows=np.where(band.min(axis=1)<200)[0]
    if not len(rows): print(f'{slug:20} no ink'); continue
    # split into runs of consecutive ink rows
    runs=[]; start=rows[0]; prev=rows[0]
    for r in rows[1:]:
        if r-prev>2: runs.append((start,prev)); start=r
        prev=r
    runs.append((start,prev))
    runs=[(200+a0/S, 200+a1/S) for a0,a1 in runs if (a1-a0)/S > 6]
    gaps=[runs[i+1][0]-runs[i][1] for i in range(len(runs)-1)]
    g1=f'{gaps[0]:.1f}px' if len(gaps)>0 else '-'
    g2=f'{gaps[1]:.1f}px' if len(gaps)>1 else '-'
    print(f'{slug:20} {g1:>16} {g2:>15}   lines={len(runs)}')
