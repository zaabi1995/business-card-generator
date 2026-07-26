#!/usr/bin/env python3
"""The Automotive card was clipped ~3.5mm too low.

MHD's own ACERE master (90x55mm, no bleed) puts the banner hard against the top
edge (T=0.00mm) and leaves 5.08mm under the last line. Our extraction has 3.55mm
of white ABOVE the banner and 0.25mm under the entity line, i.e. the whole design
sits too low and the bottom line runs into the trim.

Fix: slide each page's artwork up by exactly its own top white band, so the top
ink lands on the edge like the master, and the clearance it was stealing goes
back to the bottom. Field coordinates move by the same distance or the dynamic
text drifts off its baked labels.
"""
import os, json, subprocess
from PIL import Image, ImageChops

IMP = '/www/wwwroot/cardify.om/uploads/templates/imports/mhd-automotive-v1'
BACKUP = '/root/mhd-auto-fit-backup'
os.makedirs(BACKUP, exist_ok=True)

CARD_H_PT = 155.906          # 55mm, the automotive page box
shifts = {}

for page in (1, 2):
    src = f'{IMP}/bg-page-{page}.png'
    subprocess.run(['cp', src, f'{BACKUP}/bg-page-{page}.png'], check=True)
    im = Image.open(src).convert('RGB')
    bb = ImageChops.difference(im, Image.new('RGB', im.size, (255, 255, 255))).getbbox()
    top = bb[1]                                   # px of white above the first ink
    out = Image.new('RGB', im.size, (255, 255, 255))
    out.paste(im.crop((0, top, im.width, im.height)), (0, 0))
    out.save(src)
    shift_pt = top / im.height * CARD_H_PT
    shifts[page] = shift_pt
    print(f'page {page}: shifted up {top}px = {shift_pt:.2f}pt = {shift_pt*25.4/72:.2f}mm')

json.dump(shifts, open('/root/auto-shift.json', 'w'))
