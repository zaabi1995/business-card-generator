#!/usr/bin/env python3
"""
Post-persist bg redactor.

Cardify's PDF parser bakes any block it heuristically classifies as
"static" into the bg PNG at print DPI. The PHP-side AI classifier then
runs and sometimes reverses that decision (e.g. a specific employee
name "Vishal Dhume" was parser-classified as 'custom' -> promoted
static -> baked; AI later relabels it 'name_en' -> drawn fresh per
employee). The result is double-strike: baked Vishal under fresh Safyal.

This script takes the bg PNG plus a list of bboxes (in source canvas
pixel space, typically 300 DPI) that we now KNOW are dynamic, samples
the surrounding bg color per bbox, and fills the rect with that color.
Output rewrites the bg PNG in place.

Usage:
    redact_bg_post_persist.py <bg_png> <bboxes_json> [--canvas-dpi 300]

bboxes_json shape:
    [{"x": 271, "y": 592, "w": 532, "h": 37}, ...]

The script:
  1. Loads bg PNG, computes scale = bg_dpi / canvas_dpi from EXIF dpi
     metadata (falls back to 4.0 if missing, matches BG_DPI=1200 /
     300).
  2. For each bbox, samples a ring of pixels just outside the rect,
     takes the per-channel median to get the cleanest bg color (robust
     against tiny anti-alias halos around the text).
  3. Fills the bg-PNG rect with that color, slightly padded on every
     side to catch glyph descender ink that bleeds past the bbox.
  4. Saves back in place with the same DPI metadata.
"""
import json
import os
import statistics
import sys

try:
    from PIL import Image, ImageDraw
except ImportError:
    print(json.dumps({"error": "pillow_missing", "detail": "pip install pillow"}))
    sys.exit(1)


# Pad the bbox by this many CANVAS px on every side. Text glyph ink
# routinely overflows PyMuPDF/Fabric bboxes by a couple px (descenders,
# Arabic kashida, anti-alias halos). 4 canvas-px = 16 bg-px at 4x.
BBOX_PADDING_CANVAS_PX = 4

# Sample this many bg-PNG px outside each edge to gather the bg color.
SAMPLE_RING_PX = 8


def sample_bg_color(img: "Image.Image", x: int, y: int, w: int, h: int) -> tuple:
    """Sample the median color in a ring just outside (x,y,w,h)."""
    W, H = img.size
    samples = []
    # top edge
    for sx in range(max(0, x), min(W, x + w), max(1, w // 16 or 1)):
        sy = max(0, y - SAMPLE_RING_PX)
        samples.append(img.getpixel((sx, sy)))
    # bottom edge
    for sx in range(max(0, x), min(W, x + w), max(1, w // 16 or 1)):
        sy = min(H - 1, y + h + SAMPLE_RING_PX)
        samples.append(img.getpixel((sx, sy)))
    # left edge
    for sy in range(max(0, y), min(H, y + h), max(1, h // 8 or 1)):
        sx = max(0, x - SAMPLE_RING_PX)
        samples.append(img.getpixel((sx, sy)))
    # right edge
    for sy in range(max(0, y), min(H, y + h), max(1, h // 8 or 1)):
        sx = min(W - 1, x + w + SAMPLE_RING_PX)
        samples.append(img.getpixel((sx, sy)))

    if not samples:
        return (255, 255, 255)
    # Per-channel median
    channels = list(zip(*samples))
    return tuple(int(statistics.median(c)) for c in channels)


def main():
    if len(sys.argv) < 3:
        print(json.dumps({"error": "usage"}))
        sys.exit(2)

    bg_path = sys.argv[1]
    bboxes_json = sys.argv[2]
    canvas_dpi = 300.0
    if "--canvas-dpi" in sys.argv:
        i = sys.argv.index("--canvas-dpi")
        canvas_dpi = float(sys.argv[i + 1])

    if not os.path.isfile(bg_path):
        print(json.dumps({"error": "bg_not_found", "path": bg_path}))
        sys.exit(3)

    try:
        bboxes = json.loads(bboxes_json)
    except Exception as e:
        print(json.dumps({"error": "bad_json", "detail": str(e)}))
        sys.exit(4)
    if not isinstance(bboxes, list) or not bboxes:
        print(json.dumps({"ok": True, "noop": True, "redacted": 0}))
        return

    img = Image.open(bg_path).convert("RGB")
    dpi = img.info.get("dpi", (1200, 1200))
    bg_dpi = float(dpi[0] if isinstance(dpi, (tuple, list)) else dpi)
    if bg_dpi <= 0:
        bg_dpi = 1200.0
    scale = bg_dpi / canvas_dpi

    draw = ImageDraw.Draw(img)
    redacted = 0
    for bb in bboxes:
        try:
            cx = float(bb["x"]) - BBOX_PADDING_CANVAS_PX
            cy = float(bb["y"]) - BBOX_PADDING_CANVAS_PX
            cw = float(bb["w"]) + 2 * BBOX_PADDING_CANVAS_PX
            ch = float(bb["h"]) + 2 * BBOX_PADDING_CANVAS_PX
        except (KeyError, TypeError, ValueError):
            continue
        x = max(0, int(round(cx * scale)))
        y = max(0, int(round(cy * scale)))
        w = max(1, int(round(cw * scale)))
        h = max(1, int(round(ch * scale)))
        color = sample_bg_color(img, x, y, w, h)
        draw.rectangle([x, y, x + w, y + h], fill=color)
        redacted += 1

    img.save(bg_path, dpi=(int(bg_dpi), int(bg_dpi)))
    print(json.dumps({"ok": True, "redacted": redacted, "bg_dpi": bg_dpi, "scale": scale}))


if __name__ == "__main__":
    main()
