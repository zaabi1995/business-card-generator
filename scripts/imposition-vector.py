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
    ap.add_argument('--watermark', default='',
                    help='Stamp the given text diagonally across the page (e.g. "SAMPLE").'
                         ' Empty = no watermark.')
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
            # Cutting marks, 5mm long, 0.25pt, OUTSIDE the card edge with a
            # 1mm gap so the marks never touch the design (printers / press
            # operators dislike marks crossing live artwork).
            mark_len = 5 * 72 / 25.4
            gap      = 1 * 72 / 25.4
            for (mx, my, dx, dy) in [
                # corner, then which side(s) the marks extend toward
                (x0,          y0,          -1, -1),
                (x0 + card_w, y0,          +1, -1),
                (x0,          y0 + card_h, -1, +1),
                (x0 + card_w, y0 + card_h, +1, +1),
            ]:
                # Horizontal tick: outward only, starting `gap` away from corner
                hx_inner = mx + dx * gap
                hx_outer = mx + dx * (gap + mark_len)
                sheet.draw_line(
                    fitz.Point(hx_inner, my), fitz.Point(hx_outer, my),
                    color=(0, 0, 0), width=0.25
                )
                # Vertical tick: outward only, starting `gap` away from corner
                vy_inner = my + dy * gap
                vy_outer = my + dy * (gap + mark_len)
                sheet.draw_line(
                    fitz.Point(mx, vy_inner), fitz.Point(mx, vy_outer),
                    color=(0, 0, 0), width=0.25
                )

    # Optional sheet-wide watermark for tenant-admin previews. Print shop
    # downloads omit this so the press file is clean.
    if args.watermark:
        try:
            font_size = max(48.0, paper_w * 0.18)
            cx, cy = paper_w / 2.0, paper_h / 2.0
            font = fitz.Font('Helvetica-Bold')
            text_w = fitz.get_text_length(args.watermark, fontname='Helvetica-Bold',
                                          fontsize=font_size)
            tw = fitz.TextWriter(sheet.rect, color=(0.7, 0.7, 0.7), opacity=0.18)
            tw.append(fitz.Point(cx - text_w / 2, cy + font_size / 3),
                      args.watermark, fontsize=font_size, font=font)
            tw.write_text(sheet, morph=(fitz.Point(cx, cy), fitz.Matrix(-30)))
        except Exception as e:
            import sys as _sys
            print(f'WARN: watermark failed: {e}', file=_sys.stderr)

    out.save(args.out, garbage=4, deflate=True)


if __name__ == '__main__':
    main()
