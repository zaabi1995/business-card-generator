#!/usr/bin/env python3
"""
Re-bake a template's background PNG + SVG from the source PDF using a
caller-supplied list of redaction rectangles.

Re-run when the admin promotes/demotes a field between Static-live and
Baked-into-bg (Tier 3 of the design-editor plan). Stays in sync with
parse_card_pdf.py:parse_pdf() but skips span detection, QR detection,
and field generation. Just: render at 1200 DPI, redact rects, save.

Args:
  --pdf           absolute path to source.pdf
  --page         1-based page number
  --out-png       absolute path of bg-page-N.png to overwrite
  --out-svg       absolute path of bg-page-N.svg to overwrite (optional)
  --redact-json   JSON array of {x_pt, y_pt, w_pt, h_pt} rects to redact

Backups: writes <out-png>.bak.<unix-ts>.png next to the original before
overwriting, and the same shape for the svg. Caller can roll back by
copying the .bak.<ts> file back over the original.

Stdout (success):
  { "ok": true, "png": "...", "svg": "...", "backup_png": "...", "backup_svg": "..." }

Stdout (failure):
  { "ok": false, "error": "..." }

BG_DPI matches parse_card_pdf.py exactly so the rebake is byte-equivalent
to the original importer output for the same redaction set.
"""
import argparse
import json
import os
import shutil
import sys
import time

try:
    import fitz  # PyMuPDF
except ImportError:
    print(json.dumps({"ok": False, "error": "PyMuPDF not installed"}))
    sys.exit(2)

BG_DPI = 1200
BG_SCALE = BG_DPI / 72.0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--page", type=int, default=1)
    ap.add_argument("--out-png", required=True)
    ap.add_argument("--out-svg", default="")
    ap.add_argument("--redact-json", required=True,
                    help='JSON array: [{"x_pt":..., "y_pt":..., "w_pt":..., "h_pt":...}, ...]')
    args = ap.parse_args()

    try:
        rects = json.loads(args.redact_json)
    except Exception as e:
        print(json.dumps({"ok": False, "error": "redact-json parse: " + str(e)}))
        sys.exit(1)

    if not isinstance(rects, list):
        print(json.dumps({"ok": False, "error": "redact-json must be an array"}))
        sys.exit(1)

    if not os.path.isfile(args.pdf):
        print(json.dumps({"ok": False, "error": "pdf not found: " + args.pdf}))
        sys.exit(1)

    out_png_dir = os.path.dirname(args.out_png) or "."
    if not os.path.isdir(out_png_dir):
        print(json.dumps({"ok": False, "error": "out-png dir not found: " + out_png_dir}))
        sys.exit(1)

    ts = int(time.time())
    backup_png = ""
    backup_svg = ""
    if os.path.isfile(args.out_png):
        backup_png = args.out_png + ".bak." + str(ts)
        try:
            shutil.copy2(args.out_png, backup_png)
        except Exception as e:
            print(json.dumps({"ok": False, "error": "backup png failed: " + str(e)}))
            sys.exit(1)
    if args.out_svg and os.path.isfile(args.out_svg):
        backup_svg = args.out_svg + ".bak." + str(ts)
        try:
            shutil.copy2(args.out_svg, backup_svg)
        except Exception:
            backup_svg = ""

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

    # Mirror parse_card_pdf.py:793-801 redaction. 0.3pt padding on each
    # side stops anti-aliased glyph fragments leaking past the rect.
    for r in rects:
        try:
            x0 = float(r["x_pt"])
            y0 = float(r["y_pt"])
            w  = float(r["w_pt"])
            h  = float(r["h_pt"])
        except (KeyError, ValueError, TypeError):
            continue
        if w <= 0 or h <= 0:
            continue
        rect = fitz.Rect(x0 - 0.3, y0 - 0.3, x0 + w + 0.3, y0 + h + 0.3)
        page.add_redact_annot(rect, fill=None)

    try:
        page.apply_redactions(images=0)
    except Exception as e:
        # Roll back if we can.
        if backup_png and os.path.isfile(backup_png):
            try: shutil.copy2(backup_png, args.out_png)
            except Exception: pass
        print(json.dumps({"ok": False, "error": "apply_redactions: " + str(e)}))
        sys.exit(1)

    # Render PNG at 1200 DPI (same as importer).
    try:
        pix = page.get_pixmap(matrix=fitz.Matrix(BG_SCALE, BG_SCALE), alpha=False)
        pix.save(args.out_png)
    except Exception as e:
        if backup_png and os.path.isfile(backup_png):
            try: shutil.copy2(backup_png, args.out_png)
            except Exception: pass
        print(json.dumps({"ok": False, "error": "save png: " + str(e)}))
        sys.exit(1)

    # SVG: optional, used by the vector PDF render path. text_as_path=False
    # keeps real glyphs so the PyMuPDF-side font picker can pick the right
    # script variant at render time.
    if args.out_svg:
        try:
            svg_str = page.get_svg_image(text_as_path=False)
            with open(args.out_svg, "w", encoding="utf-8") as fh:
                fh.write(svg_str)
        except Exception as e:
            # SVG failure is non-fatal: the raster path still works.
            sys.stderr.write("WARN: svg export failed: " + str(e) + "\n")

    out = {
        "ok": True,
        "png": args.out_png,
        "svg": args.out_svg,
        "backup_png": backup_png,
        "backup_svg": backup_svg,
        "rects_redacted": len(rects),
    }
    print(json.dumps(out))


if __name__ == "__main__":
    main()
