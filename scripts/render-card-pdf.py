#!/usr/bin/env python3
"""
Render a per-employee 2-page vector PDF for one Cardify card.

Usage:
    python3 scripts/render-card-pdf.py \
        --template <path/to/template.json> \
        --employee <path/to/employee.json> \
        --out      <path/to/output.pdf>

template.json shape:
    {
      "import_dir": "<absolute path holding source.pdf + bg-page-N.svg>",
      "fonts_dir":  "<absolute path holding extracted .ttf fonts>",
      "pages": [
        {"side": "front", "width_pt": 262.55, "height_pt": 169.89,
         "background_svg_path": "bg-page-1.svg",
         "fields": [...]},
        {"side": "back",  "width_pt": ..., "height_pt": ...,
         "background_svg_path": "bg-page-2.svg",
         "fields": [
            {"field_key": "name_en", "x_pt": 31.8, "y_pt": 48.4,
             "font_family": "Lato", "font_weight": 500,
             "font_size_pt": 13.1, "color": "#ffffff"},
            ...
         ]}
      ]
    }

employee.json shape: flat dict mapping field_key -> string value.

Output: 2-page A4-trimmed-to-card-size PDF; SVG bg as vector underlay,
employee text drawn as PDF text with embedded font subsets.
"""
import argparse
import json
import os
import sys
import fitz


def render(template_path: str, employee_path: str, out_path: str) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    out_doc = fitz.open()  # empty
    for page_spec in template['pages']:
        page = out_doc.new_page(
            width=page_spec['width_pt'],
            height=page_spec['height_pt'],
        )
        # Stub: bg + fields wired in Task 4 + 5
        _ = page_spec, page

    out_doc.save(out_path, garbage=4, deflate=True)
    out_doc.close()
    return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--template', required=True)
    ap.add_argument('--employee', required=True)
    ap.add_argument('--out',      required=True)
    args = ap.parse_args()
    sys.exit(render(args.template, args.employee, args.out))


if __name__ == '__main__':
    main()
