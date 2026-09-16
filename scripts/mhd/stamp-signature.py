#!/usr/bin/env python3
"""Stamp a one-click acceptance onto a delivery note.

BHD's signature and stamp are already on the document when the ERP renders it,
and the note leaves a box headed "RECEIVED AND SIGNED BY" for the customer. The
acceptance is written in that box, which is where a reader looks for it. If the
box cannot be found, the block goes bottom right instead, and if the foot of the
page is occupied, on a fresh page: the document itself is never written over.

usage: stamp-signature.py <in.pdf> <out.pdf> <json-file>
  json: {"name": ..., "email": ..., "when": ..., "ip": ..., "ref": ..., "po": ...}
"""
import json
import sys

import fitz

MARKER = "RECEIVED AND SIGNED BY"
MARGIN = 36
LINE = 11


def acceptance_lines(meta):
    lines = [
        f"Accepted by: {meta.get('name') or meta.get('email') or ''}",
        f"{meta.get('email', '')}",
        f"{meta.get('when', '')}",
    ]
    tail = "Signed with one click from Cardify"
    if meta.get("ref"):
        tail += f", job {meta['ref']}"
    if meta.get("po"):
        tail += f", PO {meta['po']}"
    lines.append(tail)
    if meta.get("ip"):
        lines.append(f"IP {meta['ip']}")
    return lines


def in_customer_box(page, lines):
    """Write inside the note's own 'received and signed by' box."""
    hits = page.search_for(MARKER)
    if not hits:
        return False
    label = hits[0]

    # The box is the drawn rectangle that contains the label. Without it the
    # text could run past the divider into BHD's own confirmation box.
    box = None
    for d in page.get_drawings():
        r = d.get("rect")
        if r and r.contains(label) and r.width > label.width and r.height > 30:
            if box is None or r.get_area() < box.get_area():
                box = r
    if box is None:
        box = fitz.Rect(label.x0 - 6, label.y0 - 4, label.x0 + 250, label.y1 + 70)

    y = label.y1 + 14
    x = box.x0 + 10
    width = box.width - 20
    for i, text in enumerate(lines):
        if y > box.y1 - 4:
            break
        size = 8.5 if i == 0 else 7.5
        # Keep every line inside the box, however long the value is.
        while size > 5 and fitz.get_text_length(text, fontname="helv", fontsize=size) > width:
            size -= 0.5
        page.insert_text((x, y), text, fontsize=size,
                         fontname="hebo" if i == 0 else "helv", color=(0.1, 0.1, 0.1))
        y += LINE
    return True


def in_corner_block(page, doc, lines):
    width = 300
    height = LINE * len(lines) + 20
    x0 = page.rect.width - MARGIN - width
    y0 = page.rect.height - MARGIN - height
    probe = fitz.Rect(x0 - 6, y0 - 6, x0 + width + 6, y0 + height + 6)
    if page.get_text("text", clip=probe).strip():
        page = doc.new_page(width=page.rect.width, height=page.rect.height)
        y0, x0 = MARGIN, page.rect.width - MARGIN - width

    rect = fitz.Rect(x0, y0, x0 + width, y0 + height)
    page.draw_rect(rect, color=(0.06, 0.30, 0.51), width=0.8)
    y = y0 + 15
    for i, text in enumerate(lines):
        size = 9 if i == 0 else 7.5
        while size > 5 and fitz.get_text_length(text, fontname="helv", fontsize=size) > width - 20:
            size -= 0.5
        page.insert_text((x0 + 10, y), text, fontsize=size,
                         fontname="hebo" if i == 0 else "helv", color=(0.1, 0.1, 0.1))
        y += LINE


def main(src, dst, meta_path):
    meta = json.load(open(meta_path))
    lines = acceptance_lines(meta)
    doc = fitz.open(src)
    page = doc[0]
    if not in_customer_box(page, lines):
        in_corner_block(doc[-1], doc, lines)
    doc.save(dst, garbage=3, deflate=True)
    doc.close()
    print(dst)


if __name__ == "__main__":
    if len(sys.argv) != 4:
        sys.stderr.write("usage: stamp-signature.py <in.pdf> <out.pdf> <json-file>\n")
        sys.exit(2)
    main(sys.argv[1], sys.argv[2], sys.argv[3])
