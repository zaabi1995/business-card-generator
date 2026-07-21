#!/usr/bin/env python3
"""
Build a single-card, card-sized 2-page PDF (page 1 = front, page 2 = back)
from the saved Fabric render PNGs. Used as the raster fallback for the A4
cutting sheet (card-sheet.php) when a template has no vector source, so the
imposition step (imposition-vector.py, which just tiles whatever card PDF it
is given) works for raster-only tenants too.

Each page is sized to the card's physical trim (mm); the PNG fills it exactly
(keep_proportion=False) - bleed is added later by the imposition step as a
background fill, identical to the vector path.

Usage:
  python3 raster-card-pdf.py --front <front.png> [--back <back.png>] \
      --width-mm 92 --height-mm 57 --out <card.pdf>
"""
import argparse
import sys
import fitz  # PyMuPDF

MM_TO_PT = 72.0 / 25.4


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument('--front', required=True)
    ap.add_argument('--back', default='')
    ap.add_argument('--width-mm', type=float, required=True)
    ap.add_argument('--height-mm', type=float, required=True)
    ap.add_argument('--out', required=True)
    a = ap.parse_args()

    w = max(1.0, a.width_mm) * MM_TO_PT
    h = max(1.0, a.height_mm) * MM_TO_PT

    doc = fitz.open()
    for img in (a.front, a.back):
        if not img:
            continue
        page = doc.new_page(width=w, height=h)
        try:
            page.insert_image(fitz.Rect(0, 0, w, h), filename=img, keep_proportion=False)
        except Exception as e:  # noqa: BLE001
            sys.stderr.write('raster-card-pdf: insert_image failed for %s: %s\n' % (img, e))
            return 2

    if doc.page_count == 0:
        sys.stderr.write('raster-card-pdf: no input images\n')
        return 3

    doc.save(a.out, deflate=True, garbage=3)
    return 0


if __name__ == '__main__':
    sys.exit(main())
