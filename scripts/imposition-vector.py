#!/usr/bin/env python3
"""
Compose a print-ready imposition sheet from a per-employee vector card PDF.
Lays rows*cols copies on the requested paper, with 3mm bleed and corner crop
marks per card.

Default (back-compatible): imposes the FIRST source page only, one output page.

A4 cutting sheet (--all-pages --cut-radius-mm R --reg-marks): imposes EVERY
source page (front -> sheet 1, back -> sheet 2), and adds, per the print
reference: a magenta rounded-rect CUT line per card (radius R, "CutContour"),
registration targets at the sheet edges, and a print-margin border.

Usage:
    python3 scripts/imposition-vector.py --card <front-or-back.pdf> --paper A4 \
        --rows 5 --cols 2 --bleed-mm 3 --out <imposition.pdf> \
        [--all-pages] [--cut-radius-mm 1.5] [--reg-marks]
"""
import argparse
import fitz

PAPER_PT = {
    'A4': (595.28, 841.89),
    'A3': (841.89, 1190.55),
}
MM = 72.0 / 25.4
CUT = (1, 0, 1)       # magenta = the cut line (CutContour convention)
REG = (0, 0, 0)       # registration marks in black


def _rounded_rect(shape, r, radius):
    """Draw a rounded rectangle path on a fitz Shape for rect r."""
    radius = max(0.0, min(radius, r.width / 2.0, r.height / 2.0))
    if radius <= 0:
        shape.draw_rect(r)
        return
    k = 0.5523 * radius
    x0, y0, x1, y1 = r.x0, r.y0, r.x1, r.y1
    P = fitz.Point
    shape.draw_line(P(x0 + radius, y0), P(x1 - radius, y0))
    shape.draw_bezier(P(x1 - radius, y0), P(x1 - radius + k, y0), P(x1, y0 + radius - k), P(x1, y0 + radius))
    shape.draw_line(P(x1, y0 + radius), P(x1, y1 - radius))
    shape.draw_bezier(P(x1, y1 - radius), P(x1, y1 - radius + k), P(x1 - radius + k, y1), P(x1 - radius, y1))
    shape.draw_line(P(x1 - radius, y1), P(x0 + radius, y1))
    shape.draw_bezier(P(x0 + radius, y1), P(x0 + radius - k, y1), P(x0, y1 - radius + k), P(x0, y1 - radius))
    shape.draw_line(P(x0, y1 - radius), P(x0, y0 + radius))
    shape.draw_bezier(P(x0, y0 + radius), P(x0, y0 + radius - k), P(x0 + radius - k, y0), P(x0 + radius, y0))


def _reg_target(shape, cx, cy, size=6.0):
    """A registration crosshair (circle + cross) centred at (cx, cy)."""
    shape.draw_circle(fitz.Point(cx, cy), size)
    shape.draw_line(fitz.Point(cx - size * 1.6, cy), fitz.Point(cx + size * 1.6, cy))
    shape.draw_line(fitz.Point(cx, cy - size * 1.6), fitz.Point(cx, cy + size * 1.6))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--card',  required=True)
    ap.add_argument('--paper', default='A4', choices=list(PAPER_PT.keys()))
    ap.add_argument('--rows',  type=int, default=5)
    ap.add_argument('--cols',  type=int, default=2)
    ap.add_argument('--bleed-mm', type=float, default=3.0)
    ap.add_argument('--margin-mm', type=float, default=10.0)
    ap.add_argument('--out',  required=True)
    ap.add_argument('--all-pages', action='store_true',
                    help='Impose every source page (front + back) instead of page 0 only.')
    ap.add_argument('--cut-radius-mm', type=float, default=0.0,
                    help='Draw a magenta rounded-rect cut line per card at this corner radius.')
    ap.add_argument('--reg-marks', action='store_true',
                    help='Add registration targets + print-margin border (cutting sheet).')
    ap.add_argument('--watermark', default='',
                    help='Stamp the given text diagonally across the page (e.g. "SAMPLE").')
    args = ap.parse_args()

    paper_w, paper_h = PAPER_PT[args.paper]
    margin = args.margin_mm * MM
    bleed  = args.bleed_mm  * MM
    cut_r  = args.cut_radius_mm * MM

    src = fitz.open(args.card)
    pages = range(src.page_count) if args.all_pages else [0]
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
    for pno in pages:
        sheet = out.new_page(width=paper_w, height=paper_h)

        # Print-margin border (live-area frame) + registration targets.
        if args.reg_marks:
            mshape = sheet.new_shape()
            mshape.draw_rect(fitz.Rect(margin, margin, paper_w - margin, paper_h - margin))
            mshape.finish(color=(0.6, 0.6, 0.6), width=0.25, dashes='[3 3] 0')
            mshape.commit()
            rshape = sheet.new_shape()
            for cx, cy in [(paper_w / 2, margin / 2), (paper_w / 2, paper_h - margin / 2),
                           (margin / 2, paper_h / 2), (paper_w - margin / 2, paper_h / 2)]:
                _reg_target(rshape, cx, cy)
            rshape.finish(color=REG, width=0.4)
            rshape.commit()

        for r in range(args.rows):
            for c in range(args.cols):
                x0 = margin + c * slot_w + (slot_w - card_w) / 2
                y0 = margin + r * slot_h + (slot_h - card_h) / 2
                target = fitz.Rect(x0, y0, x0 + card_w, y0 + card_h)
                sheet.show_pdf_page(target, src, pno=pno, keep_proportion=False)

                # Corner crop marks, 5mm, 0.25pt, OUTSIDE the card edge, 1mm gap.
                mark_len = 5 * MM
                gap = 1 * MM
                cm = sheet.new_shape()
                for (mx, my, dx, dy) in [
                    (x0, y0, -1, -1), (x0 + card_w, y0, +1, -1),
                    (x0, y0 + card_h, -1, +1), (x0 + card_w, y0 + card_h, +1, +1),
                ]:
                    cm.draw_line(fitz.Point(mx + dx * gap, my), fitz.Point(mx + dx * (gap + mark_len), my))
                    cm.draw_line(fitz.Point(mx, my + dy * gap), fitz.Point(mx, my + dy * (gap + mark_len)))
                cm.finish(color=REG, width=0.25)
                cm.commit()

                # Magenta rounded cut line at the trim (CutContour convention).
                # Trim = card rect inset by the 3mm bleed the card PDF carries.
                if args.cut_radius_mm > 0 or args.reg_marks:
                    trim = fitz.Rect(x0 + bleed, y0 + bleed, x0 + card_w - bleed, y0 + card_h - bleed)
                    cs = sheet.new_shape()
                    _rounded_rect(cs, trim, cut_r)
                    cs.finish(color=CUT, width=0.5, closePath=True)
                    cs.commit()

        if args.watermark:
            try:
                font_size = max(48.0, paper_w * 0.18)
                cx, cy = paper_w / 2.0, paper_h / 2.0
                font = fitz.Font('Helvetica-Bold')
                text_w = fitz.get_text_length(args.watermark, fontname='Helvetica-Bold', fontsize=font_size)
                tw = fitz.TextWriter(sheet.rect, color=(0.7, 0.7, 0.7), opacity=0.18)
                tw.append(fitz.Point(cx - text_w / 2, cy + font_size / 3), args.watermark, fontsize=font_size, font=font)
                tw.write_text(sheet, morph=(fitz.Point(cx, cy), fitz.Matrix(-30)))
            except Exception as e:
                import sys as _sys
                print(f'WARN: watermark failed: {e}', file=_sys.stderr)

    out.save(args.out, garbage=4, deflate=True)


if __name__ == '__main__':
    main()
