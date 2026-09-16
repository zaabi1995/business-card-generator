#!/usr/bin/env python3
"""Stamp a one-click acceptance block onto a delivery note.

BHD's own signature and stamp are already on the document when the ERP renders
it. This adds the customer's side: who accepted it, from which address, when,
and from which IP. It is added as a block under the existing content on the last
page, never over it.

usage: stamp-signature.py <in.pdf> <out.pdf> <json-file>
  json: {"name": ..., "email": ..., "when": ..., "ip": ..., "ref": ..., "po": ...}
"""
import json
import sys

import fitz

MARGIN = 36
LINE = 13


def main(src, dst, meta_path):
    meta = json.load(open(meta_path))
    doc = fitz.open(src)
    page = doc[-1]

    lines = [
        "Received and accepted",
        f"By: {meta.get('name') or meta.get('email') or ''}",
        f"Email: {meta.get('email', '')}",
        f"Date: {meta.get('when', '')}",
    ]
    if meta.get("ref"):
        lines.append(f"Job: {meta['ref']}" + (f"   PO: {meta['po']}" if meta.get("po") else ""))
    lines.append(f"Signed with one click from Cardify" + (f", IP {meta['ip']}" if meta.get("ip") else ""))

    height = LINE * len(lines) + 18
    width = 280
    x0 = page.rect.width - MARGIN - width
    y0 = page.rect.height - MARGIN - height
    # Never write over the document: if the foot of the page is occupied, add a
    # fresh page and put the block there instead.
    if page.get_text("text", clip=fitz.Rect(x0 - 6, y0 - 6, x0 + width + 6, y0 + height + 6)).strip():
        page = doc.new_page(width=page.rect.width, height=page.rect.height)
        y0 = MARGIN
        x0 = page.rect.width - MARGIN - width

    rect = fitz.Rect(x0, y0, x0 + width, y0 + height)
    page.draw_rect(rect, color=(0.06, 0.30, 0.51), width=0.8)
    y = y0 + 15
    for i, text in enumerate(lines):
        page.insert_text((x0 + 10, y), text, fontsize=9 if i else 10,
                         fontname="helv" if i else "hebo", color=(0.1, 0.1, 0.1))
        y += LINE

    doc.save(dst, garbage=3, deflate=True)
    doc.close()
    print(dst)


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.stderr.write("usage: stamp-signature.py <in.pdf> <out.pdf> <json-file>\n")
        sys.exit(2)
    main(sys.argv[1], sys.argv[2], sys.argv[3])
