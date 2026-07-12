#!/usr/bin/env python3
"""Rebuild the Group A shared backgrounds from mhd-card-clean-bg.pdf.
- bg-page-1/2: straight 1200dpi raster + top-left crop-tick white-out.
- bg-1tel-page-1/2: redact the 2nd Tel/HATEF line (VERTICAL-INSET rects: AR
  line boxes are 10.6pt tall on 8.7pt pitch, full-bbox redaction eats the
  neighbouring line's glyphs), then shift the block below up one line pitch
  with band edges computed FROM THE SPANS (the old hand-picked band sheared
  the Tel label bottoms and ate the AR labels).
Deploy: scp outputs to uploads/templates/imports/mhd-clean-v1/ + bump versions."""
import fitz, subprocess
from PIL import Image, ImageDraw
D = 1200/72.0
PITCH = 8.7  # pt, ITICS contact-line pitch (from spans)

subprocess.run(['pdftoppm','-png','-r','1200','mhd-card-clean-bg.pdf','ga-bg'], check=True)

d = fitz.open('mhd-card-clean-bg.pdf')
pg = d[0]  # front: 2nd "Tel: +968" box 106.5-114.5
for b in pg.get_text('dict')['blocks']:
    for l in b.get('lines', []):
        for s in l.get('spans', []):
            x0,y0,x1,y1 = s['bbox']
            if s['text'].strip().startswith('Tel:') and 106.0 < y0 < 107.0:
                pg.add_redact_annot(fitz.Rect(x0-1,y0+2.5,x1+1,y1-2.5), fill=(1,1,1))
pg.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
pg = d[1]  # back: 2nd HATEF line pieces share box 105.2-115.7
for b in pg.get_text('dict')['blocks']:
    for l in b.get('lines', []):
        for s in l.get('spans', []):
            x0,y0,x1,y1 = s['bbox']
            if 104.8 < y0 < 105.6 and y1 < 116.2:
                pg.add_redact_annot(fitz.Rect(x0-1,y0+3.2,x1+1,y1-3.2), fill=(1,1,1))
pg.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE)
d.save('ga-1tel.pdf')
subprocess.run(['pdftoppm','-png','-r','1200','ga-1tel.pdf','ga-1tel'], check=True)

im = Image.open('ga-1tel-1.png').convert('RGB'); dr = ImageDraw.Draw(im)
x0,y0,x1,y1 = int(165*D), int(114.8*D), im.width, int(150.5*D)
band = im.crop((x0,y0,x1,y1)); dr.rectangle((x0,y0,x1,y1), fill=(255,255,255))
im.paste(band, (x0, y0-int(PITCH*D)))
dr.rectangle((0,0,140,60), fill=(255,255,255))
im.save('ga-1tel-front.png')
im = Image.open('ga-1tel-2.png').convert('RGB'); dr = ImageDraw.Draw(im)
x0,y0,x1,y1 = int(20*D), int(115.2*D), int(140*D), int(150.5*D)
band = im.crop((x0,y0,x1,y1)); dr.rectangle((x0,y0,x1,y1), fill=(255,255,255))
im.paste(band, (x0, y0-int(PITCH*D)))
dr.rectangle((0,0,140,60), fill=(255,255,255))
im.save('ga-1tel-back.png')
for src,out in [('ga-bg-1.png','ga-bg-front.png'),('ga-bg-2.png','ga-bg-back.png')]:
    im = Image.open(src).convert('RGB')
    ImageDraw.Draw(im).rectangle((0,0,140,60), fill=(255,255,255))
    im.save(out)
print('done')
