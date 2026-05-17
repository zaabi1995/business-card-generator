#!/usr/bin/env python3
"""Redact text regions out of a source.pdf so the vector PDF render path
serves a clean bg and the runtime overlay (PyMuPDF text writer) doesn't
double-strike on top of already-baked vector glyphs.

Usage:
    redact_source_pdf.py <pdf_path> <bboxes_json>

bboxes_json shape (canvas px space, 300 DPI by default):
    [{"page": 1, "x": 271, "y": 592, "w": 532, "h": 37}, ...]

Adds redaction annotations at the converted PDF-point bboxes, applies
them with images=0 (text-only wipe, leaves the gradient/dots in place),
and saves back in place with garbage collection + deflate.
"""
import json
import sys

import fitz  # PyMuPDF

CANVAS_DPI = 300.0
PT_PER_PX = 72.0 / CANVAS_DPI  # 0.24


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"error": "usage"}))
        sys.exit(2)

    pdf_path = sys.argv[1]
    bboxes = json.loads(sys.argv[2])

    if not bboxes:
        print(json.dumps({"ok": True, "noop": True}))
        return

    doc = fitz.open(pdf_path)
    by_page = {}
    for bb in bboxes:
        p = int(bb.get("page", 1))
        by_page.setdefault(p, []).append(bb)

    redacted = 0
    for page_num, page_bboxes in by_page.items():
        if page_num < 1 or page_num > len(doc):
            continue
        page = doc[page_num - 1]
        for bb in page_bboxes:
            x0 = float(bb["x"]) * PT_PER_PX
            y0 = float(bb["y"]) * PT_PER_PX
            x1 = (float(bb["x"]) + float(bb["w"])) * PT_PER_PX
            y1 = (float(bb["y"]) + float(bb["h"])) * PT_PER_PX
            # Pad 2pt around to catch glyph overflow (descenders, Arabic
            # kashida, anti-alias rings around the vector strokes).
            rect = fitz.Rect(x0 - 2, y0 - 2, x1 + 2, y1 + 2)
            page.add_redact_annot(rect, fill=None)
            redacted += 1
        # images=0 keeps embedded raster bg (the blue gradient + dot
        # pattern is a single big image on these Otech designs).
        page.apply_redactions(images=0)

    # PyMuPDF refuses save-to-original unless incremental=True, and
    # incremental keeps the original baked text accessible via prior xref.
    # Write to a sibling temp and atomic-rename for a clean replacement.
    tmp_path = pdf_path + ".tmp"
    doc.save(tmp_path, garbage=3, deflate=True)
    doc.close()
    import os
    os.replace(tmp_path, pdf_path)
    print(json.dumps({"ok": True, "redacted": redacted, "pages": list(by_page.keys())}))


if __name__ == "__main__":
    main()
