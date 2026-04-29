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


def _load_font_buffers(fonts_dir: str) -> dict:
    """Return {filename_stem: bytes} for all .ttf files in fonts_dir."""
    buffers = {}
    if not os.path.isdir(fonts_dir):
        return buffers
    for fname in os.listdir(fonts_dir):
        if fname.lower().endswith('.ttf'):
            with open(os.path.join(fonts_dir, fname), 'rb') as fh:
                buffers[os.path.splitext(fname)[0]] = fh.read()
    return buffers


def _pick_font(family: str, weight: int, font_buffers: dict) -> tuple:
    """
    Return (font_name, font_buffer) for the best match of (family, weight).

    Lookup strategy:
      1. Exact: <Family>-<WeightName>  (e.g. Lato-Medium for weight 500)
      2. Family prefix match, prefer closer weight
      3. First buffer whose key contains the family name

    Returns (None, None) when no match is found.
    """
    weight_names = {
        100: 'Thin', 200: 'ExtraLight', 300: 'Light', 400: 'Regular',
        500: 'Medium', 600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold',
        900: 'Black',
    }
    family_lc = family.lower()
    # Build candidate list: keys whose name contains the family
    candidates = {k: v for k, v in font_buffers.items()
                  if family_lc in k.lower()}
    if not candidates:
        return None, None

    # Exact weight match
    weight_name = weight_names.get(weight, '')
    exact_key = f"{family}-{weight_name}"
    if exact_key in candidates:
        return exact_key, candidates[exact_key]

    # Fall back to any candidate (first alphabetically, deterministic)
    first_key = sorted(candidates.keys())[0]
    return first_key, candidates[first_key]


def _hex_to_rgb(hex_color: str) -> tuple:
    """Convert '#rrggbb' to (r, g, b) floats in [0, 1]."""
    h = hex_color.lstrip('#')
    r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
    return r / 255.0, g / 255.0, b / 255.0


def render(template_path: str, employee_path: str, out_path: str) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    import_dir = template['import_dir']
    fonts_dir  = template.get('fonts_dir', os.path.join(import_dir, 'fonts'))

    # Load all font buffers once.
    font_buffers = _load_font_buffers(fonts_dir)

    # Read each font's actual ascender (em units, e.g. 0.987 for Lato-Medium).
    # Used to place the text baseline accurately relative to the field's y_pt (top).
    font_ascenders = {}
    for fname, buf in font_buffers.items():
        try:
            f = fitz.Font(fontbuffer=buf)
            font_ascenders[fname] = float(f.ascender)
        except Exception:
            font_ascenders[fname] = 0.97  # safe default

    out_doc = fitz.open()

    for page_spec in template['pages']:
        page = out_doc.new_page(
            width=page_spec['width_pt'],
            height=page_spec['height_pt'],
        )

        # Layer 1: SVG background as vector underlay.
        svg_rel = page_spec.get('background_svg_path')
        if svg_rel:
            svg_path = os.path.join(import_dir, svg_rel)
            if os.path.isfile(svg_path):
                with open(svg_path, 'rb') as fh:
                    svg_bytes = fh.read()
                # PyMuPDF 1.27 opens SVG natively via fitz.open(stream, filetype='svg'),
                # but show_pdf_page requires a PDF source. We convert the SVG doc to PDF
                # in-memory via convert_to_pdf(), then use show_pdf_page to copy all
                # vector paths onto our card page without rasterising.
                svg_doc = fitz.open(stream=svg_bytes, filetype='svg')
                pdf_bytes = svg_doc.convert_to_pdf()
                svg_doc.close()
                pdf_doc = fitz.open(stream=pdf_bytes, filetype='pdf')
                page.show_pdf_page(
                    page.rect,
                    pdf_doc,
                    pno=0,
                    keep_proportion=False,
                )
                pdf_doc.close()

        # Layer 2: dynamic text fields.
        for field in page_spec.get('fields', []):
            field_key = field.get('field_key', '')
            # Support 'address' as alias for 'address_en'.
            text = employee.get(field_key) or employee.get(field_key + '_en', '')
            if not text:
                continue

            family = field.get('font_family', 'Lato')
            weight = int(field.get('font_weight', 400))
            font_size = float(field.get('font_size_pt', 10))
            color = _hex_to_rgb(field.get('color', '#000000'))
            x_pt = float(field['x_pt'])
            y_pt = float(field['y_pt'])

            font_name, font_buf = _pick_font(family, weight, font_buffers)
            if font_name is None:
                continue  # skip field if no matching font found

            # Register font on this page using set_simple=True (WinAnsiEncoding).
            # Without set_simple, PyMuPDF creates a CID/Identity-encoded font whose
            # space glyph (0x20) round-trips back as U+00A0 (NBSP) in get_text().
            # set_simple forces the same WinAnsiEncoding that the source PDF uses,
            # so spaces extract as regular spaces and get_text() comparisons work.
            page.insert_font(fontname=font_name, fontbuffer=font_buf, set_simple=True)

            # baseline_y = y_pt (top of em-square) + ascender * font_size.
            ascender = font_ascenders.get(font_name, 0.97)
            baseline_y = y_pt + ascender * font_size

            page.insert_text(
                fitz.Point(x_pt, baseline_y),
                text,
                fontname=font_name,
                fontsize=font_size,
                color=color,
            )

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
