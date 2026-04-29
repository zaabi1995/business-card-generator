#!/usr/bin/env python3
"""
Extract embedded TTF fonts from a template's source.pdf.

Usage:
    python3 scripts/extract_template_fonts.py <import-dir>

<import-dir> is the directory containing source.pdf (e.g.
/www/wwwroot/cardify.om/uploads/templates/imports/<token>/).

Writes:
    <import-dir>/fonts/<FontFamily-Style>.ttf  (one per font)
    <import-dir>/fonts/manifest.json           (family -> filename map)

Returns 0 on success, prints the number of fonts written.
Returns 1 if source.pdf has no embedded fonts (caller should set
templates.has_vector_source=0).
"""
import sys
import json
import os
import fitz


def main(import_dir: str = None):
    if import_dir is None:
        if len(sys.argv) != 2:
            print("Usage: extract_template_fonts.py <import-dir>", file=sys.stderr)
            return 2
        import_dir = sys.argv[1]
    import_dir = import_dir.rstrip('/')
    src = os.path.join(import_dir, 'source.pdf')
    if not os.path.isfile(src):
        print(f"source.pdf not found in {import_dir}", file=sys.stderr)
        return 2

    fonts_dir = os.path.join(import_dir, 'fonts')
    os.makedirs(fonts_dir, exist_ok=True)

    doc = fitz.open(src)
    seen = {}
    for page in doc:
        for f in page.get_fonts(full=True):
            xref = f[0]
            if xref in seen:
                continue
            try:
                info = doc.extract_font(xref)
                buf = info[3]
                if not buf or info[1] not in ('ttf', 'otf'):
                    continue
                # name like "ABCDEF+Lato-Medium" -> "Lato-Medium"
                family = info[0].split('+', 1)[-1] or f'font-{xref}'
                fname = f'{family}.{info[1]}'
                path = os.path.join(fonts_dir, fname)
                with open(path, 'wb') as fh:
                    fh.write(buf)
                seen[xref] = {'family': family, 'file': fname, 'ext': info[1]}
            except Exception as e:
                print(f"WARN xref={xref}: {e}", file=sys.stderr)

    if not seen:
        print(f"No embedded TTF/OTF fonts found in {src}")
        return 1

    manifest_path = os.path.join(fonts_dir, 'manifest.json')
    with open(manifest_path, 'w') as fh:
        json.dump({str(k): v for k, v in seen.items()}, fh, indent=2)
    print(f"Extracted {len(seen)} fonts to {fonts_dir}")
    return 0


if __name__ == '__main__':
    sys.exit(main())
