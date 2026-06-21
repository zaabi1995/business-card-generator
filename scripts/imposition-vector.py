#!/usr/bin/env python3
"""
Compose a print-ready imposition sheet from a per-employee vector card PDF.
Lays rows*cols copies on the requested paper, with corner crop marks per card.

Default (back-compatible): imposes the FIRST source page only, one output page.

A4 cutting sheet (--all-pages --cut-radius-mm R --reg-marks [--sheet-bg auto]):
imposes EVERY source page (front -> sheet 1, back -> sheet 2) and adds, per the
BHD press reference:
  - a continuous BACKGROUND fill behind the whole card grid (sampled from the
    card, or an explicit colour) so every card bleeds and the gaps/margin are
    filled (no white slivers when cut),
  - corner crop marks + registration targets + a print-margin border,
  - a magenta rounded CUT line per card on its OWN PDF LAYER ("CutContour"),
    drawn in a real Separation spot colour so a cutter/RIP picks it up.

Usage:
    python3 scripts/imposition-vector.py --card <card.pdf> --paper A4 \
        --rows 4 --cols 2 --out <sheet.pdf> \
        --all-pages --cut-radius-mm 1.5 --reg-marks --sheet-bg auto
"""
import argparse
import fitz

PAPER_PT = {'A4': (595.28, 841.89), 'A3': (841.89, 1190.55)}
MM = 72.0 / 25.4
CUT_PREVIEW = (1, 0, 1)   # magenta preview for the CutContour spot
REG = (0, 0, 0)


def _rounded_rect_ops(x0, y0, x1, y1, r):
    """PDF path operators (user space, y-up) for a rounded rectangle."""
    r = max(0.0, min(r, (x1 - x0) / 2.0, (y1 - y0) / 2.0))
    k = 0.5523 * r
    f = lambda v: '%.3f' % v
    if r <= 0:
        return f'{f(x0)} {f(y0)} {f(x1 - x0)} {f(y1 - y0)} re'
    return ' '.join([
        f'{f(x0 + r)} {f(y0)} m', f'{f(x1 - r)} {f(y0)} l',
        f'{f(x1 - r + k)} {f(y0)} {f(x1)} {f(y0 + r - k)} {f(x1)} {f(y0 + r)} c',
        f'{f(x1)} {f(y1 - r)} l',
        f'{f(x1)} {f(y1 - r + k)} {f(x1 - r + k)} {f(y1)} {f(x1 - r)} {f(y1)} c',
        f'{f(x0 + r)} {f(y1)} l',
        f'{f(x0 + r - k)} {f(y1)} {f(x0)} {f(y1 - r + k)} {f(x0)} {f(y1 - r)} c',
        f'{f(x0)} {f(y0 + r)} l',
        f'{f(x0)} {f(y0 + r - k)} {f(x0 + r - k)} {f(y0)} {f(x0 + r)} {f(y0)} c', 'h',
    ])


def _ensure_subdict(doc, owner, key):
    t, v = doc.xref_get_key(owner, key)
    if t == 'xref':
        return int(v.split()[0])
    if t == 'dict':
        nx = doc.get_new_xref(); doc.update_object(nx, v)
        doc.xref_set_key(owner, key, f'{nx} 0 R'); return nx
    nx = doc.get_new_xref(); doc.update_object(nx, '<< >>')
    doc.xref_set_key(owner, key, f'{nx} 0 R'); return nx


def _inject_cut_layer(doc, cut_by_page, radius_pt, line_w=0.5):
    """Add a 'CutContour' OCG layer carrying the per-card rounded cut paths in a
    real Separation spot colour (alternate = magenta preview)."""
    ocg = doc.add_ocg('CutContour', on=True)
    fn = doc.get_new_xref()
    doc.update_object(fn, '<< /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] '
                          '/C1 [0 1 0 0] /N 1 >>')
    cs = doc.get_new_xref()
    doc.update_object(cs, f'[ /Separation /CutContour /DeviceCMYK {fn} 0 R ]')
    for pno, rects in cut_by_page.items():
        if not rects:
            continue
        page = doc[pno]
        page.clean_contents()
        res = _ensure_subdict(doc, page.xref, 'Resources')
        csd = _ensure_subdict(doc, res, 'ColorSpace')
        doc.xref_set_key(csd, 'CutCS', f'{cs} 0 R')
        prp = _ensure_subdict(doc, res, 'Properties')
        doc.xref_set_key(prp, 'OCcut', f'{ocg} 0 R')
        paths = ' '.join(_rounded_rect_ops(*r, radius_pt) for r in rects)
        cut = ('q /OC /OCcut BDC /CutCS CS 1 SC %.3f w %s S EMC Q'
               % (line_w, paths)).encode()
        cx = page.get_contents()[0]
        doc.update_stream(cx, doc.xref_stream(cx) + b'\n' + cut)


def _reg_target(shape, cx, cy, size=6.0):
    shape.draw_circle(fitz.Point(cx, cy), size)
    shape.draw_line(fitz.Point(cx - size * 1.6, cy), fitz.Point(cx + size * 1.6, cy))
    shape.draw_line(fitz.Point(cx, cy - size * 1.6), fitz.Point(cx, cy + size * 1.6))


def _sample_card_bg(src):
    """Sample the card's edge colour (top-left, in the bleed/background) as a
    0-1 RGB tuple, for the continuous sheet background."""
    try:
        pm = src[0].get_pixmap(matrix=fitz.Matrix(0.08, 0.08))
        x = max(1, int(pm.width * 0.02)); y = max(1, int(pm.height * 0.02))
        px = pm.pixel(x, y)
        return (px[0] / 255.0, px[1] / 255.0, px[2] / 255.0)
    except Exception:
        return None


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--card',  required=True)
    ap.add_argument('--paper', default='A4', choices=list(PAPER_PT.keys()))
    ap.add_argument('--rows',  type=int, default=5)
    ap.add_argument('--cols',  type=int, default=2)
    ap.add_argument('--bleed-mm', type=float, default=3.0)
    ap.add_argument('--margin-mm', type=float, default=10.0)
    ap.add_argument('--gutter-mm', type=float, default=3.0,
                    help='Gap between cards (so cut marks/lines are visible).')
    ap.add_argument('--out',  required=True)
    ap.add_argument('--all-pages', action='store_true')
    ap.add_argument('--cut-radius-mm', type=float, default=0.0)
    ap.add_argument('--reg-marks', action='store_true')
    ap.add_argument('--sheet-bg', default='none',
                    help="'auto' (sample the card), 'none', or 'R,G,B' (0-255).")
    ap.add_argument('--watermark', default='')
    args = ap.parse_args()

    paper_w, paper_h = PAPER_PT[args.paper]
    margin = args.margin_mm * MM
    bleed  = args.bleed_mm  * MM
    gutter = args.gutter_mm * MM
    cut_r  = args.cut_radius_mm * MM

    src = fitz.open(args.card)
    pages = list(range(src.page_count)) if args.all_pages else [0]
    card_w = src[0].rect.width
    card_h = src[0].rect.height

    # Pack cards tightly (card + gutter) and centre the block on the sheet,
    # instead of spreading them into big slots. This leaves room around the
    # grid for the background bleed + marks.
    block_w = args.cols * card_w + (args.cols - 1) * gutter
    block_h = args.rows * card_h + (args.rows - 1) * gutter
    if block_w > paper_w - 2 * margin or block_h > paper_h - 2 * margin:
        raise SystemExit(
            f"grid {block_w:.1f}x{block_h:.1f} doesn't fit inside the margins")
    ox = (paper_w - block_w) / 2.0
    oy = (paper_h - block_h) / 2.0

    # Background fill colour (continuous bleed behind the whole grid).
    bg = None
    if args.sheet_bg == 'auto':
        bg = _sample_card_bg(src)
    elif args.sheet_bg not in ('none', ''):
        try:
            parts = [float(x) for x in args.sheet_bg.split(',')]
            if len(parts) == 3:
                bg = tuple(min(1.0, max(0.0, p / 255.0)) for p in parts)
        except Exception:
            bg = None

    out = fitz.open()
    cut_by_page = {}
    for sheet_idx, pno in enumerate(pages):
        sheet = out.new_page(width=paper_w, height=paper_h)
        cut_rects = []

        # 1. Background bleed: fill the grid area + bleed margin with the card
        #    colour so every card bleeds and the gaps are filled.
        if bg is not None:
            bgshape = sheet.new_shape()
            bgshape.draw_rect(fitz.Rect(ox - bleed, oy - bleed,
                                        ox + block_w + bleed, oy + block_h + bleed))
            bgshape.finish(color=None, fill=bg)
            bgshape.commit()

        # 2. Cards.
        for r in range(args.rows):
            for c in range(args.cols):
                x0 = ox + c * (card_w + gutter)
                y0 = oy + r * (card_h + gutter)
                target = fitz.Rect(x0, y0, x0 + card_w, y0 + card_h)
                sheet.show_pdf_page(target, src, pno=pno, keep_proportion=False)
                # Cut at the card edge (the card PDF is trim-sized); the
                # background fill below provides the bleed past the edge.
                cut_rects.append((x0, paper_h - (y0 + card_h),
                                  x0 + card_w, paper_h - y0))
                # corner crop marks (5mm, just outside the card)
                mark_len = 5 * MM; gap = 1 * MM
                cm = sheet.new_shape()
                for (mx, my, dx, dy) in [
                    (x0, y0, -1, -1), (x0 + card_w, y0, +1, -1),
                    (x0, y0 + card_h, -1, +1), (x0 + card_w, y0 + card_h, +1, +1),
                ]:
                    cm.draw_line(fitz.Point(mx + dx * gap, my), fitz.Point(mx + dx * (gap + mark_len), my))
                    cm.draw_line(fitz.Point(mx, my + dy * gap), fitz.Point(mx, my + dy * (gap + mark_len)))
                cm.finish(color=REG, width=0.25)
                cm.commit()

        # 3. Registration targets + print-margin border.
        if args.reg_marks:
            ms = sheet.new_shape()
            ms.draw_rect(fitz.Rect(margin, margin, paper_w - margin, paper_h - margin))
            ms.finish(color=(0.55, 0.55, 0.55), width=0.25, dashes='[3 3] 0')
            ms.commit()
            rs = sheet.new_shape()
            for cx, cy in [(paper_w / 2, margin / 2), (paper_w / 2, paper_h - margin / 2),
                           (margin / 2, paper_h / 2), (paper_w - margin / 2, paper_h / 2)]:
                _reg_target(rs, cx, cy)
            rs.finish(color=REG, width=0.4)
            rs.commit()

        if args.watermark:
            try:
                fs = max(48.0, paper_w * 0.18)
                cx, cy = paper_w / 2.0, paper_h / 2.0
                font = fitz.Font('Helvetica-Bold')
                tw_w = fitz.get_text_length(args.watermark, fontname='Helvetica-Bold', fontsize=fs)
                tw = fitz.TextWriter(sheet.rect, color=(0.7, 0.7, 0.7), opacity=0.18)
                tw.append(fitz.Point(cx - tw_w / 2, cy + fs / 3), args.watermark, fontsize=fs, font=font)
                tw.write_text(sheet, morph=(fitz.Point(cx, cy), fitz.Matrix(-30)))
            except Exception as e:
                import sys as _sys
                print(f'WARN: watermark failed: {e}', file=_sys.stderr)

        if cut_r > 0:
            cut_by_page[sheet_idx] = cut_rects

    # 4. Cut lines on their own CutContour spot-colour OCG layer.
    if cut_by_page:
        _inject_cut_layer(out, cut_by_page, cut_r)

    out.save(args.out, garbage=3, deflate=True)


if __name__ == '__main__':
    main()
