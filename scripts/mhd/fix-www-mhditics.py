#!/usr/bin/env python3
"""Group A (ITICS family) prints www.mhditics.com, not www.mhdoman.com.

The www line is static artwork baked into the shared clean background, so it is
swapped in the PDF the backgrounds are rasterised from, then every derived PNG
is rebuilt. Front column is left-aligned (Fax:/Mob: share x0=169.01); the Arabic
back column is right-aligned (the نقال: label ends on the same x1=108.76), so
the replacement is anchored on the right there or it drifts off the margin.
"""
import fitz, shutil, os

WORK = '/root/mhd-www'
SRC  = '/www/wwwroot/cardify.om/docs/mhd/mhd-card-clean-bg.pdf'
FONT = '/www/wwwroot/cardify.om/uploads/templates/imports/mhd-clean-v1/fonts/FrutigerLTStd-Roman.ttf'
OLD, NEW = 'www.mhdoman.com', 'www.mhditics.com'
COLOR = (0x40/255, 0x72/255, 0xa6/255)

os.makedirs(WORK, exist_ok=True)
out = os.path.join(WORK, 'mhd-card-clean-bg.pdf')
shutil.copy(SRC, out)

font = fitz.Font(fontfile=FONT)
doc = fitz.open(out)
for pno in range(doc.page_count):
    page = doc[pno]
    hit = None
    for b in page.get_text('dict')['blocks']:
        for l in b.get('lines', []):
            for s in l['spans']:
                if OLD in s['text']:
                    hit = s
    if not hit:
        print(f'page {pno+1}: no {OLD} span'); continue
    x0, y0, x1, y1 = hit['bbox']
    size = hit['size']
    ox, oy = hit['origin']              # baseline start, the anchor to preserve
    # Nothing sits within 10pt above or below this line, so a full-bbox
    # redaction is safe here (the vertical-inset rule is for the stacked
    # contact lines, where 10.7pt boxes overlap on an 8.7pt pitch).
    page.add_redact_annot(fitz.Rect(x0 - 1, y0 - 0.5, x1 + 1, y1 + 0.5), fill=(1, 1, 1))
    page.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
    w = font.text_length(NEW, fontsize=size)
    # front: keep the left edge (column is left-aligned)
    # back:  keep the right edge (Arabic column is right-aligned on x1)
    nx = ox if pno == 0 else (x1 - w)
    page.insert_text((nx, oy), NEW, fontname='FrutR', fontfile=FONT,
                     fontsize=size, color=COLOR)
    print(f'page {pno+1}: {OLD} -> {NEW}  size={size:.2f} '
          f'old=[{x0:.2f},{x1:.2f}] new=[{nx:.2f},{nx+w:.2f}]')
doc.save(os.path.join(WORK, 'fixed.pdf'))
os.replace(os.path.join(WORK, 'fixed.pdf'), out)

chk = fitz.open(out)
for pno in range(chk.page_count):
    txt = chk[pno].get_text()
    print(f'page {pno+1}: has_new={NEW in txt} has_old={OLD in txt}')
