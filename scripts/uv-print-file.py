#!/usr/bin/env python3
"""UV screen-print file: replicate the press's own file setup.

Reproduces the sheet format Otech's screen printer works from
("UV SCREEN PRINT- FILE SET UP.ai", 12 Aug 2026): a 225x320 mm sheet,
8-up (2 cols x 4 rows in two blocks), full card artwork per slot, the
spot-UV shapes in SOLID BLACK on top (their convention: black = UV),
red rounded die lines, grey crosshair registration targets, and the
"Paper Size" caption. Page 1 = fronts, page 2 = backs.

The UV shapes live on their own PDF optional-content layer named "UV"
(and the cards on "Artwork"), mirroring the printer's .ai layer setup so
they can output the UV screen by toggling layers in Acrobat/Illustrator.

The --uv-mask PDF is authored at TRIM size (its page box IS the trim),
so it lands 1:1 on each die rect. The --card artwork PDF may be larger
(bleed-inclusive, trim centred); it is placed so its centred trim
registers on the die rect and the bleed runs past the red line, exactly
like the printer's own sheet.
"""

import argparse
import json
import sys

import fitz

MM = 72 / 25.4

# Otech's printer sheet geometry, measured from their .ai (all mm).
DEFAULT_GRID = {
    'paper': [225.0, 320.0],
    'cols_x': [15.972, 119.028],
    'rows_y': [20.0, 83.965, 178.965, 242.93],
}
DIE_RED = (0.931, 0.121, 0.140)
MARK_GREY = (0.577, 0.585, 0.596)
# Crosshair target centres (pt), 3 cols x 4 rows on their 225x320 sheet.
MARK_XS = [40.6, 318.9, 597.2]
MARK_YS = [28.3, 422.3, 479.0, 872.9]


def draw_target(shape, cx, cy):
    """Concentric-circle crosshair, matching the printer's registration mark."""
    shape.draw_circle(fitz.Point(cx, cy), 4.8)
    shape.draw_circle(fitz.Point(cx, cy), 2.85)
    shape.draw_line(fitz.Point(cx - 4.8, cy), fitz.Point(cx + 4.8, cy))
    shape.draw_line(fitz.Point(cx, cy - 4.8), fitz.Point(cx, cy + 4.8))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--card', required=True, help='2-page artwork card PDF (front, back)')
    ap.add_argument('--uv-mask', required=True, help='2-page trim-sized UV mask PDF (black = UV)')
    ap.add_argument('--cut-radius-mm', type=float, default=4.259)
    ap.add_argument('--grid-json', default='', help='override {paper, cols_x, rows_y} in mm')
    ap.add_argument('--out', required=True)
    args = ap.parse_args()

    grid = dict(DEFAULT_GRID)
    if args.grid_json:
        try:
            grid.update(json.loads(args.grid_json))
        except Exception as e:
            print(f'WARN: bad --grid-json ignored: {e}', file=sys.stderr)

    card = fitz.open(args.card)
    mask = fitz.open(args.uv_mask)
    if card.page_count < 1 or mask.page_count < 1:
        raise SystemExit('card/mask PDF has no pages')

    # Trim size comes from the mask page box (authored at trim).
    trim_w = mask[0].rect.width
    trim_h = mask[0].rect.height
    paper_w = grid['paper'][0] * MM
    paper_h = grid['paper'][1] * MM
    radius_frac = max(0.0, min(0.5, (args.cut_radius_mm * MM) / min(trim_w, trim_h)))

    out = fitz.open()
    oc_art = out.add_ocg('Artwork', on=True)
    oc_uv = out.add_ocg('UV', on=True)

    pages = min(card.page_count, mask.page_count)
    for pno in range(pages):
        sheet = out.new_page(width=paper_w, height=paper_h)
        # Artwork bleed margins (trim centred in the card page).
        cw, ch = card[pno].rect.width, card[pno].rect.height
        mx = max(0.0, (cw - trim_w) / 2.0)
        my = max(0.0, (ch - trim_h) / 2.0)
        die_rects = []
        for y_mm in grid['rows_y']:
            for x_mm in grid['cols_x']:
                die = fitz.Rect(x_mm * MM, y_mm * MM,
                                x_mm * MM + trim_w, y_mm * MM + trim_h)
                die_rects.append(die)
                art = fitz.Rect(die.x0 - mx, die.y0 - my, die.x1 + mx, die.y1 + my)
                sheet.show_pdf_page(art, card, pno=pno, keep_proportion=False, oc=oc_art)
        # UV black on top of the artwork.
        for die in die_rects:
            sheet.show_pdf_page(die, mask, pno=pno, keep_proportion=False, oc=oc_uv)
        # Red rounded die lines, over everything (the printer's cut reference).
        dies = sheet.new_shape()
        for die in die_rects:
            dies.draw_rect(die, radius=radius_frac)
        dies.finish(color=DIE_RED, width=1.0)
        dies.commit()
        # Grey crosshair registration targets.
        marks = sheet.new_shape()
        for cx in MARK_XS:
            for cy in MARK_YS:
                draw_target(marks, cx * (paper_w / (DEFAULT_GRID['paper'][0] * MM)),
                            cy * (paper_h / (DEFAULT_GRID['paper'][1] * MM)))
        marks.finish(color=MARK_GREY, width=0.5)
        marks.commit()
        # Caption, as on the printer's sheet.
        label = f"Paper Size : {grid['paper'][0]:g}x{grid['paper'][1]:g} mm"
        sheet.insert_text(fitz.Point(137.8, 877.0 * (paper_h / (DEFAULT_GRID['paper'][1] * MM))),
                          label, fontsize=8.5, fontname='helv', color=(0, 0, 0))

    out.save(args.out, garbage=3, deflate=True)


if __name__ == '__main__':
    main()
