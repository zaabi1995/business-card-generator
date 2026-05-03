#!/usr/bin/env python3
"""Sample QR style for a given bg image + rect.

Stand-alone wrapper around parse_card_pdf.sample_qr_style so the PHP
backfill (scripts/backfill-qr-styles.php) and ad-hoc one-off audits can
re-run the detector against an existing template's bg PNG without re-
importing the whole PDF.

Usage:
    python3 sample_qr_style_cli.py \\
        --bg /www/wwwroot/cardify.om/uploads/bgs/abc.png \\
        --x-pt 168.5 --y-pt 18.2 --w-pt 60.0 --h-pt 60.0 \\
        --bg-dpi 1200 --real-qr

Coords are in PDF points (the same form templates.settings_json.qr_area
uses). --real-qr flags real_qr=True so the detector tries the styled
classifier; omit for empty-placeholder boxes.

Stdout: JSON style dict (or null on sample failure).
Stderr: diagnostics.
"""
import argparse
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from parse_card_pdf import sample_qr_style  # noqa: E402

try:
    from PIL import Image
except ImportError:
    print('PIL not available', file=sys.stderr)
    sys.exit(2)


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--bg', required=True, help='path to bg PNG (rendered at --bg-dpi)')
    p.add_argument('--x-pt', type=float, required=True)
    p.add_argument('--y-pt', type=float, required=True)
    p.add_argument('--w-pt', type=float, required=True)
    p.add_argument('--h-pt', type=float, required=True)
    p.add_argument('--bg-dpi', type=float, default=1200.0)
    p.add_argument('--real-qr', action='store_true', help='hint that a real scannable QR was detected')
    args = p.parse_args()

    if not os.path.isfile(args.bg):
        print(f'bg not found: {args.bg}', file=sys.stderr)
        sys.exit(2)

    img = Image.open(args.bg)
    scale = args.bg_dpi / 72.0
    rect_px = (
        args.x_pt * scale,
        args.y_pt * scale,
        args.w_pt * scale,
        args.h_pt * scale,
    )
    print(f'bg size {img.size}, rect_px {rect_px}, real_qr={args.real_qr}', file=sys.stderr)

    style = sample_qr_style(img, rect_px, real_qr=args.real_qr)
    print(json.dumps(style, indent=2))


if __name__ == '__main__':
    main()
