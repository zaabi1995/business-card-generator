#!/usr/bin/env python3
"""
Sample one text field from a source PDF and return its detected metadata.

Used by admin/redetect-text-field.php as the OCR-equivalent for a single
field, the same way scripts/sample_qr_style_cli.py serves redetect-qr-style.
Reads the source PDF (templates.original_pdf_path) at the given page,
finds the text span(s) whose bbox overlaps the input rect (in PDF points),
and returns the merged text + dominant font + size + color as JSON on
stdout.

Args:
  --pdf       absolute path to source.pdf
  --page     1-based page number (matches templates.original_pdf_page)
  --x-pt    field bbox x in PDF points (top-left)
  --y-pt    field bbox y in PDF points (top-left)
  --w-pt    field bbox width in PDF points
  --h-pt    field bbox height in PDF points

Stdout (success):
  {
    "ok": true,
    "detected_text": "Founding Partner",
    "font_family": "Lato",
    "font_weight": 500,
    "italic": false,
    "font_size_pt": 9.5,
    "color": "#fdb62b",
    "bbox_pt": [x, y, w, h]
  }

Stdout (failure):
  { "ok": false, "error": "..." }

Stay in sync with parse_card_pdf.py:font_to_family_and_weight + the span
extraction logic in parse_pdf().
"""
import argparse
import json
import re
import sys

try:
    import fitz  # PyMuPDF
except ImportError:
    print(json.dumps({"ok": False, "error": "PyMuPDF not installed"}))
    sys.exit(2)


def font_to_family_and_weight(font_name):
    """Mirror of parse_card_pdf.py:font_to_family_and_weight."""
    if not font_name:
        return ("Arial", 400, False)
    base = font_name.split(",")[0].split("+")[-1]
    italic = "italic" in base.lower() or "oblique" in base.lower()
    weight = 400
    weight_map = {
        "thin": 100, "extralight": 200, "light": 300, "regular": 400,
        "medium": 500, "semibold": 600, "demibold": 600,
        "bold": 700, "extrabold": 800, "black": 900,
    }
    parts = re.split(r"[-_\s]", base)
    family = parts[0]
    for p in parts[1:]:
        pl = p.lower().replace("italic", "").replace("oblique", "")
        if pl in weight_map:
            weight = weight_map[pl]
    return (family, weight, italic)


def color_int_to_hex(c):
    if c is None:
        return "#000000"
    r = (c >> 16) & 0xFF
    g = (c >> 8) & 0xFF
    b = c & 0xFF
    return "#{:02x}{:02x}{:02x}".format(r, g, b)


def rect_overlap_area(a, b):
    """a, b = (x0, y0, x1, y1). Returns intersection area in pt^2."""
    ix0 = max(a[0], b[0])
    iy0 = max(a[1], b[1])
    ix1 = min(a[2], b[2])
    iy1 = min(a[3], b[3])
    if ix1 <= ix0 or iy1 <= iy0:
        return 0.0
    return (ix1 - ix0) * (iy1 - iy0)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--page", type=int, default=1)
    ap.add_argument("--x-pt", type=float, required=True)
    ap.add_argument("--y-pt", type=float, required=True)
    ap.add_argument("--w-pt", type=float, required=True)
    ap.add_argument("--h-pt", type=float, required=True)
    args = ap.parse_args()

    target = (
        args.x_pt,
        args.y_pt,
        args.x_pt + args.w_pt,
        args.y_pt + args.h_pt,
    )
    # 30% slop on each axis so a slightly off-target field still finds the
    # span. Importer rounds bboxes to 2 decimals in pt; small drift is normal.
    pad_x = max(args.w_pt * 0.3, 2.0)
    pad_y = max(args.h_pt * 0.3, 2.0)
    target_padded = (
        target[0] - pad_x, target[1] - pad_y,
        target[2] + pad_x, target[3] + pad_y,
    )

    try:
        doc = fitz.open(args.pdf)
    except Exception as e:
        print(json.dumps({"ok": False, "error": "open pdf: " + str(e)}))
        sys.exit(1)

    page_idx = max(0, args.page - 1)
    if page_idx >= doc.page_count:
        print(json.dumps({"ok": False, "error": "page out of range"}))
        sys.exit(1)
    page = doc[page_idx]

    # Walk every span on the page; collect those overlapping the padded
    # target rect. Then pick the cluster sharing the largest overlap with
    # the un-padded target so we don't accidentally pull in a neighbouring
    # field that just happens to graze the slop region.
    text_dict = page.get_text("dict")
    candidates = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                bbox = span.get("bbox")
                if not bbox or len(bbox) < 4:
                    continue
                if rect_overlap_area(bbox, target_padded) <= 0:
                    continue
                candidates.append({
                    "text": span.get("text", ""),
                    "bbox": tuple(bbox),
                    "font": span.get("font", ""),
                    "size": float(span.get("size", 0)),
                    "color": int(span.get("color", 0)) if span.get("color") is not None else 0,
                    "overlap": rect_overlap_area(bbox, target),
                })

    if not candidates:
        print(json.dumps({"ok": False, "error": "no spans overlap field bbox"}))
        sys.exit(0)

    # Sort by overlap (desc) so the dominant span is first; merge text in
    # reading order (left-to-right within a row, then top-to-bottom).
    candidates.sort(key=lambda c: c["overlap"], reverse=True)
    dominant = candidates[0]

    # Group spans on the same row (y0 within 1pt of dominant) and join in
    # x order, the way the importer would. Preserves "An Omantel Company"
    # split across multiple spans.
    same_row = [
        c for c in candidates
        if abs(c["bbox"][1] - dominant["bbox"][1]) <= 1.0
    ]
    same_row.sort(key=lambda c: c["bbox"][0])
    detected_text = "".join(c["text"] for c in same_row)

    family, weight, italic = font_to_family_and_weight(dominant["font"])

    # Reconstruct the merged bbox in pt.
    merged_bbox = (
        min(c["bbox"][0] for c in same_row),
        min(c["bbox"][1] for c in same_row),
        max(c["bbox"][2] for c in same_row),
        max(c["bbox"][3] for c in same_row),
    )

    out = {
        "ok": True,
        "detected_text": detected_text,
        "font_family": family,
        "font_weight": weight,
        "italic": italic,
        "font_size_pt": round(dominant["size"], 2),
        "color": color_int_to_hex(dominant["color"]),
        "bbox_pt": [
            round(merged_bbox[0], 2),
            round(merged_bbox[1], 2),
            round(merged_bbox[2] - merged_bbox[0], 2),
            round(merged_bbox[3] - merged_bbox[1], 2),
        ],
        "span_count": len(same_row),
    }
    print(json.dumps(out, ensure_ascii=False))


if __name__ == "__main__":
    main()
