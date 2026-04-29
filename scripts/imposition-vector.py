#!/usr/bin/env python3
"""
Compose a print-ready imposition sheet from N copies of a per-employee
vector card PDF. Layout is rows*cols on the requested paper size, with
3mm bleed, 0.25pt cutting marks at the corners of each card slot.

Usage:
    python3 scripts/imposition-vector.py \
        --card <front-or-back.pdf> \
        --paper A4 \
        --rows 5 --cols 2 \
        --bleed-mm 3 \
        --out <imposition.pdf>
"""
import argparse
import fitz

PAPER_PT = {
    'A4': (595.28, 841.89),
    'A3': (841.89, 1190.55),
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--card',  required=True)
    ap.add_argument('--paper', default='A4', choices=list(PAPER_PT.keys()))
    ap.add_argument('--rows',  type=int, default=5)
    ap.add_argument('--cols',  type=int, default=2)
    ap.add_argument('--bleed-mm', type=float, default=3.0)
    ap.add_argument('--margin-mm', type=float, default=10.0)
    ap.add_argument('--out',  required=True)
    args = ap.parse_args()

    paper_w, paper_h = PAPER_PT[args.paper]
    margin = args.margin_mm * 72 / 25.4
    bleed  = args.bleed_mm  * 72 / 25.4

    src = fitz.open(args.card)
    card_w = src[0].rect.width
    card_h = src[0].rect.height

    grid_w = paper_w - 2 * margin
    grid_h = paper_h - 2 * margin
    slot_w = grid_w / args.cols
    slot_h = grid_h / args.rows
    if slot_w < card_w or slot_h < card_h:
        raise SystemExit(
            f"card {card_w:.1f}x{card_h:.1f} doesn't fit in slot {slot_w:.1f}x{slot_h:.1f}"
        )

    out = fitz.open()
    sheet = out.new_page(width=paper_w, height=paper_h)
    for r in range(args.rows):
        for c in range(args.cols):
            x0 = margin + c * slot_w + (slot_w - card_w) / 2
            y0 = margin + r * slot_h + (slot_h - card_h) / 2
            target = fitz.Rect(x0, y0, x0 + card_w, y0 + card_h)
            sheet.show_pdf_page(target, src, pno=0, keep_proportion=False)
            # Cutting marks, 5mm long at each corner, 0.25pt
            mm = 5 * 72 / 25.4
            for (mx, my) in [
                (x0, y0),
                (x0 + card_w, y0),
                (x0, y0 + card_h),
                (x0 + card_w, y0 + card_h),
            ]:
                sheet.draw_line(
                    fitz.Point(mx - mm, my), fitz.Point(mx + mm, my),
                    color=(0, 0, 0), width=0.25
                )
                sheet.draw_line(
                    fitz.Point(mx, my - mm), fitz.Point(mx, my + mm),
                    color=(0, 0, 0), width=0.25
                )

    out.save(args.out, garbage=4, deflate=True)


if __name__ == '__main__':
    main()
