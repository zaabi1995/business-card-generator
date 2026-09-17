#!/usr/bin/env python3
"""Put a card's pages on A4 for production.

BHD print from an A4 sheet, so the press-size PDF the renderer makes (one page
per side, card size) is laid out on a single A4 portrait page: front above,
back below, both at exact size, centred, with crop marks and a caption naming
the job. Nothing is scaled, so what is measured on the sheet is what prints.

usage: impose-a4.py <card.pdf> <out.pdf> [caption]
"""
import sys

import fitz

A4 = fitz.paper_rect("a4")          # 595 x 842 pt
MARK = 8                            # crop mark length, pt
GAP = 28                            # space between the two sides, pt
CAPTION_H = 26


def crop_marks(page, rect):
    """Four corner marks, set off the trim so they never print inside it."""
    for x in (rect.x0, rect.x1):
        for y in (rect.y0, rect.y1):
            dx = -MARK if x == rect.x0 else MARK
            dy = -MARK if y == rect.y0 else MARK
            page.draw_line((x + dx * 0.25, y), (x + dx, y), color=(0, 0, 0), width=0.3)
            page.draw_line((x, y + dy * 0.25), (x, y + dy), color=(0, 0, 0), width=0.3)


def main(src, dst, caption=""):
    card = fitz.open(src)
    if card.page_count == 0:
        raise SystemExit("the card pdf has no pages")

    out = fitz.open()
    sheet = out.new_page(width=A4.width, height=A4.height)

    sides = [card[i].rect for i in range(min(2, card.page_count))]
    total_h = sum(r.height for r in sides) + GAP * (len(sides) - 1)
    top = (A4.height - total_h - CAPTION_H) / 2

    y = top
    for i, r in enumerate(sides):
        x = (A4.width - r.width) / 2
        target = fitz.Rect(x, y, x + r.width, y + r.height)
        sheet.show_pdf_page(target, card, i)
        crop_marks(sheet, target)
        y += r.height + GAP

    if caption:
        sheet.insert_text((A4.width / 2 - 150, A4.height - 40), caption[:120],
                          fontsize=8, fontname="helv", color=(0.42, 0.45, 0.5))

    out.save(dst, garbage=3, deflate=True)
    out.close()
    card.close()
    print(dst)


if __name__ == "__main__":
    if len(sys.argv) < 3:
        sys.stderr.write("usage: impose-a4.py <card.pdf> <out.pdf> [caption]\n")
        sys.exit(2)
    main(sys.argv[1], sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else "")
