#!/usr/bin/env python3
"""
Render a per-employee 2-page vector PDF for one Cardify card.

Usage:
    python3 scripts/render-card-pdf.py \
        --template <path/to/template.json> \
        --employee <path/to/employee.json> \
        --out      <path/to/output.pdf> \
        [--vcard   <path/to/contact.vcf>]

template.json shape:
    {
      "import_dir":    "<absolute path holding source.pdf + bg-page-N.svg>",
      "fonts_dir":     "<absolute path holding extracted .ttf fonts>",
      "company_name":  "Otech",
      "company_slug":  "otech",
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
import re
import sys
import fitz

# Arabic / Hebrew script ranges -- triggers preference for the
# "<family>-Arabic" / "<family>-Arabic-Antiqua" font variant in
# _pick_font when the text contains these codepoints. Without this,
# fields stored as fontFamily="Arsenica" (the Latin family name) +
# Arabic content would match the Latin face, and PyMuPDF would
# silently substitute an internal fallback font (Cairo / system
# Naskh) for the unrenderable Arabic glyphs.
_RTL_CHAR_RE = re.compile(
    '[֐-ࣿݐ-ݿﭐ-﷿ﹰ-﻿]'
)


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


def _font_weight_of(name: str) -> int:
    """Infer a CSS weight from a font filename stem (e.g. 'Lato-Bold' -> 700)."""
    m = {
        'thin': 100, 'extralight': 200, 'light': 300,
        'regular': 400, 'book': 400,
        'medium': 500, 'semibold': 600, 'demibold': 600,
        'bold': 700, 'extrabold': 800, 'black': 900,
    }
    name_lc = name.lower()
    for tok, w in m.items():
        if tok in name_lc:
            return w
    return 400  # default if unparseable


def _pick_font(family: str, weight: int, font_buffers: dict, text: str = '') -> tuple:
    """
    Return (font_name, font_buffer) for the best match of (family, weight).

    Lookup strategy:
      1. If `text` contains Arabic glyphs: prefer keys that include
         "arabic" in their name (and the family substring). Picks the
         bare "<family>-Arabic-*" variant first, falling back to
         "<family>-Arabic-Antiqua-*" -- this matches the foundry
         catalogue we ship for Hosn (Arsenica-Arabic + Arsenica-Arabic-
         Antiqua live alongside the Latin Arsenica-Antiqua faces).
      2. Exact: <Family>-<WeightName>  (e.g. Lato-Medium for weight 500)
      3. Family prefix match, ranked by absolute weight distance then name

    Returns (None, None) when no match is found.

    Without the Arabic-aware step, fields stored as fontFamily="Arsenica"
    + Arabic content matched the Latin face and PyMuPDF silently
    substituted an internal fallback (Cairo / system Naskh) for the
    unrenderable glyphs -- visible to the user as a font swap on
    downloaded PDFs.
    """
    weight_names = {
        100: 'Thin', 200: 'ExtraLight', 300: 'Light', 400: 'Regular',
        500: 'Medium', 600: 'SemiBold', 700: 'Bold', 800: 'ExtraBold',
        900: 'Black',
    }
    family_lc = family.lower()

    # 1. Arabic-script preference. Skip the Antiqua sub-pool first so
    # the bare "Arsenica-Arabic" variant wins over "Arsenica-Arabic-
    # Antiqua" (matches the user's stated preference + reads cleaner
    # at body sizes). Falls back to *any* font containing "arabic" if
    # the family doesn't have an Arabic variant on disk.
    if text and _RTL_CHAR_RE.search(text):
        def _rank(pool):
            return sorted(pool.keys(),
                          key=lambda k: (abs(_font_weight_of(k) - weight), k))
        family_arabic = {k: v for k, v in font_buffers.items()
                         if 'arabic' in k.lower()
                         and family_lc in k.lower()
                         and 'antiqua' not in k.lower()}
        if family_arabic:
            best = _rank(family_arabic)[0]
            return best, family_arabic[best]
        family_arabic_antiqua = {k: v for k, v in font_buffers.items()
                                 if 'arabic' in k.lower()
                                 and family_lc in k.lower()}
        if family_arabic_antiqua:
            best = _rank(family_arabic_antiqua)[0]
            return best, family_arabic_antiqua[best]
        any_arabic = {k: v for k, v in font_buffers.items()
                      if 'arabic' in k.lower()}
        if any_arabic:
            best = _rank(any_arabic)[0]
            return best, any_arabic[best]

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

    # Fall back: closest weight distance, then alphabetical for ties
    ranked = sorted(candidates.keys(),
                    key=lambda k: (abs(_font_weight_of(k) - weight), k))
    best_key = ranked[0]
    return best_key, candidates[best_key]


def _resolve_employee_value(field_key: str, employee: dict) -> str:
    """Return the employee's value for a template field_key, trying:
    1. Exact key (e.g. 'name_en')
    2. Locale-stripped key (e.g. 'name' for 'name_en' / 'name_ar')
    3. The other locale's variant (e.g. 'name_en' for 'name_ar' if missing)
    """
    val = (employee.get(field_key) or '').strip()
    if val:
        return val
    # If field_key has a locale suffix, try without it
    if field_key.endswith('_en'):
        bare = field_key[:-3]
        val = (employee.get(bare) or '').strip()
        if val:
            return val
        # Cross-locale fallback to _ar
        val = (employee.get(bare + '_ar') or '').strip()
        if val:
            return val
    elif field_key.endswith('_ar'):
        bare = field_key[:-3]
        val = (employee.get(bare) or '').strip()
        if val:
            return val
        # Cross-locale fallback to _en
        val = (employee.get(bare + '_en') or '').strip()
        if val:
            return val
    else:
        # Bare key like 'address': try _en then _ar
        val = (employee.get(field_key + '_en') or '').strip()
        if val:
            return val
        val = (employee.get(field_key + '_ar') or '').strip()
        if val:
            return val
    return ''


def _hex_to_rgb(hex_color) -> tuple:
    """Convert '#rrggbb' or '#rgb' to (r, g, b) floats in [0, 1].

    Handles: None, empty string, 3-char shorthand, garbage input.
    Always returns a safe (0, 0, 0) on any parse failure.
    """
    s = (hex_color or '').lstrip('#')
    if len(s) == 3:
        s = ''.join(c * 2 for c in s)
    if len(s) != 6:
        return (0, 0, 0)
    try:
        return tuple(int(s[i:i+2], 16) / 255.0 for i in (0, 2, 4))
    except ValueError:
        return (0, 0, 0)


def _draw_crop_marks(page, card_rect, mark_len_pt=14.17, bleed_pt=8.5, line_width=0.71):
    """Draw 5mm crop marks at the four corners of card_rect.

    card_rect is the original card area before bleed expansion.
    Marks are drawn just outside the bleed zone (i.e. outside card_rect
    by bleed_pt) and extend inward for mark_len_pt (~5mm at 72dpi).

    Parameters
    ----------
    page       : fitz.Page  (already expanded by bleed_pt on all sides)
    card_rect  : fitz.Rect  original card boundary in the new page coords
                            (= fitz.Rect(bleed_pt, bleed_pt,
                                         orig_w + bleed_pt, orig_h + bleed_pt))
    mark_len_pt: float      length of each mark stroke (default 14.17pt = 5mm)
    bleed_pt   : float      bleed extension used; marks start at bleed_pt - 2pt
                            gap outside the card edge
    line_width : float      stroke width in pt (0.25pt = 0.71px at 72dpi)
    """
    # Gap between card edge and start of crop mark (2pt breathing room inside bleed).
    gap = 2.0
    x0, y0, x1, y1 = card_rect.x0, card_rect.y0, card_rect.x1, card_rect.y1

    # Corners: (start_h, end_h, start_v, end_v) for horizontal + vertical strokes.
    corners = [
        # top-left
        (x0 - bleed_pt + gap, x0 - gap,   y0 - bleed_pt + gap, y0 - gap,   x0, y0),
        # top-right
        (x1 + gap, x1 + bleed_pt - gap,   y0 - bleed_pt + gap, y0 - gap,   x1, y0),
        # bottom-left
        (x0 - bleed_pt + gap, x0 - gap,   y1 + gap, y1 + bleed_pt - gap,   x0, y1),
        # bottom-right
        (x1 + gap, x1 + bleed_pt - gap,   y1 + gap, y1 + bleed_pt - gap,   x1, y1),
    ]

    shape = page.new_shape()
    for (hx0, hx1, vy0, vy1, cx, cy) in corners:
        # Horizontal stroke
        shape.draw_line(fitz.Point(hx0, cy), fitz.Point(hx1, cy))
        shape.finish(color=(0, 0, 0), width=line_width, fill=None, closePath=False)
        # Vertical stroke
        shape.draw_line(fitz.Point(cx, vy0), fitz.Point(cx, vy1))
        shape.finish(color=(0, 0, 0), width=line_width, fill=None, closePath=False)
    shape.commit()


def _draw_watermark(page, text: str) -> None:
    """Stamp a diagonal SAMPLE-style watermark across the centre of a page.

    Used for tenant-admin downloads so the artwork is visibly marked as
    not-for-press until a print shop generates the un-watermarked version.
    Uses Helvetica (built-in, no font loading needed) at ~18% page width
    point size, light grey, ~25% alpha approximated via tint, rotated -30deg.
    """
    if not text:
        return
    try:
        rect = page.rect
        # Size proportional to page width
        font_size = max(28.0, rect.width * 0.18)
        cx, cy = rect.width / 2.0, rect.height / 2.0
        # PyMuPDF's TextWriter supports rotation around a point; insert_text
        # is faster but doesn't rotate. Use a Shape with rotated text instead.
        # Fallback: insert_textbox at center if rotation fails.
        # Simpler: stamp the text twice without rotation when rotation isn't
        # available. We try the rotated path first.
        try:
            tw = fitz.TextWriter(rect, color=(0.6, 0.6, 0.6))
            tw.append(fitz.Point(0, 0), text, fontsize=font_size,
                      font=fitz.Font('Helvetica-Bold'))
            morph = (fitz.Point(cx, cy), fitz.Matrix(-30).prerotate(0))
            # Translate baseline so the text is roughly centred on (cx, cy)
            text_w = fitz.get_text_length(text, fontname='Helvetica-Bold', fontsize=font_size)
            tw2 = fitz.TextWriter(rect, color=(0.6, 0.6, 0.6), opacity=0.25)
            tw2.append(fitz.Point(cx - text_w / 2, cy + font_size / 3),
                       text, fontsize=font_size, font=fitz.Font('Helvetica-Bold'))
            tw2.write_text(page, morph=(fitz.Point(cx, cy), fitz.Matrix(-30)))
        except Exception:
            page.insert_textbox(
                fitz.Rect(rect.x0, cy - font_size, rect.x1, cy + font_size),
                text,
                fontsize=font_size,
                fontname='Helvetica-Bold',
                color=(0.7, 0.7, 0.7),
                align=fitz.TEXT_ALIGN_CENTER,
            )
    except Exception as e:
        print(f'WARN: watermark draw failed: {e}', file=sys.stderr)


def render(template_path: str, employee_path: str, out_path: str,
           vcard_path: str = '', profile: str = 'web', for_print: bool = False,
           watermark: str = '') -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    import_dir = template['import_dir']
    fonts_dir  = template.get('fonts_dir', os.path.join(import_dir, 'fonts'))

    # Load all font buffers once.
    font_buffers = _load_font_buffers(fonts_dir)

    # Read each font's actual ascender (em units) once and cache it.
    # Lato-Medium = 0.987, Sora-Regular = 0.970 (from fitz.Font.ascender).
    # baseline_y = y_pt + ascender * font_size gives sub-0.1pt drift vs
    # the source PDF (measured: name_en ~0.05pt, position_en ~0.04pt).
    # A magic constant like 0.97 would accumulate error across font sizes.
    font_ascenders = {}
    for fname, buf in font_buffers.items():
        try:
            f = fitz.Font(fontbuffer=buf)
            font_ascenders[fname] = float(f.ascender)
        except Exception:
            font_ascenders[fname] = 0.97  # safe default

    out_doc = fitz.open()

    # Phase 12: bleed expansion (3mm = 8.5pt on each side) when --for-print.
    BLEED_PT = 8.504  # 3mm * (72 / 25.4)

    # Phase 11: font embedding mode.
    # print profile: set_simple=False embeds the full font (no subset).
    # web profile (default): set_simple=True uses WinAnsiEncoding subset.
    # Note: full PDF/X-4 compliance requires post-processing via Ghostscript
    # --dPDFA=2 --dPDFACompatibilityPolicy=1. PyMuPDF does not expose an
    # output-intent ICC dictionary or explicit PDF version control in 1.27.
    use_simple_encoding = (profile != 'print')

    for page_spec in template['pages']:
        orig_w = page_spec['width_pt']
        orig_h = page_spec['height_pt']

        if for_print:
            page_w = orig_w + 2 * BLEED_PT
            page_h = orig_h + 2 * BLEED_PT
        else:
            page_w = orig_w
            page_h = orig_h

        page = out_doc.new_page(width=page_w, height=page_h)

        # card_rect: where the original card sits on the (possibly expanded) page.
        if for_print:
            card_rect = fitz.Rect(BLEED_PT, BLEED_PT,
                                   BLEED_PT + orig_w, BLEED_PT + orig_h)
        else:
            card_rect = fitz.Rect(0, 0, orig_w, orig_h)

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
                    card_rect,
                    pdf_doc,
                    pno=0,
                    keep_proportion=False,
                )
                pdf_doc.close()

        # Phase 12: draw crop marks outside the card boundary when --for-print.
        if for_print:
            _draw_crop_marks(page, card_rect, bleed_pt=BLEED_PT)

        # Layer 2: dynamic text fields.
        for field in page_spec.get('fields', []):
            field_key = field.get('field_key', '')
            text = _resolve_employee_value(field_key, employee)
            if not text:
                continue

            family = field.get('font_family', 'Lato')
            weight = int(field.get('font_weight', 400))
            font_size = float(field.get('font_size_pt', 10))
            color = _hex_to_rgb(field.get('color', '#000000'))
            x_pt = float(field['x_pt'])
            y_pt = float(field['y_pt'])

            font_name, font_buf = _pick_font(family, weight, font_buffers, text=text)
            if font_name is None:
                continue  # skip field if no matching font found

            # Register font on this page.
            # web profile (default): set_simple=True uses WinAnsiEncoding so spaces
            # round-trip cleanly from get_text() (CID Identity-encoded fonts return NBSP).
            # print profile: set_simple=False embeds the full font without subsetting,
            # which is required for PDF/X-4 compliance at the print shop.
            page.insert_font(fontname=font_name, fontbuffer=font_buf,
                             set_simple=use_simple_encoding)

            # baseline_y = y_pt (top of em-square) + ascender * font_size.
            # Offset by bleed margin when for_print so text stays in the card area.
            ascender = font_ascenders.get(font_name, 0.97)
            text_x = x_pt + (BLEED_PT if for_print else 0)
            text_y = y_pt + (BLEED_PT if for_print else 0)
            baseline_y = text_y + ascender * font_size

            page.insert_text(
                fitz.Point(text_x, baseline_y),
                text,
                fontname=font_name,
                fontsize=font_size,
                color=color,
            )

        # Layer 3: optional watermark stamp (last, so it overlays everything).
        if watermark:
            _draw_watermark(page, watermark)

    # Phase 5: PDF metadata (title/author/subject/keywords in Acrobat Properties).
    company_name = template.get('company_name', '')
    company_slug = template.get('company_slug', '')
    emp_name = (employee.get('name_en') or employee.get('name') or 'Business').strip()
    subject = 'Print-ready business card' if profile == 'print' else 'Digital business card'
    out_doc.set_metadata({
        'title':    f'{emp_name} business card',
        'author':   company_name,
        'subject':  subject,
        'keywords': f'business card, contact, vCard, {company_slug}',
        'creator':  'Cardify (cardify.om)',
        'producer': f'PyMuPDF {fitz.__version__} profile={profile}',
    })

    # Phase 6: PDF/UA accessibility tags.
    # PyMuPDF 1.27 does not expose insert_text(tag=...) or a tag-tree API,
    # so full PDF/UA-1 tagging is not achievable without a C-level extension.
    # Deferred: PyMuPDF 1.27 lacks the high-level tag-insertion API needed.

    # Phase 9: linearization (linear=True) skipped.
    # PyMuPDF 1.27 removed linearization support entirely:
    # FzErrorArgument: Linearisation is no longer supported.
    # The flag is gone from the MuPDF build used by PyMuPDF >= 1.24.
    out_doc.save(out_path, garbage=4, deflate=True)
    out_doc.close()

    # Phase 7: embed vCard as a PDF attachment (Acrobat / Apple Mail "Add to Contacts").
    # Re-open the saved file, add the attachment, save to a sibling temp path,
    # then atomically replace the original (PyMuPDF cannot overwrite in-place
    # without incremental=True, which inflates size; write-then-replace is cleaner).
    if vcard_path and os.path.isfile(vcard_path):
        vcf_doc = fitz.open(out_path)
        with open(vcard_path, 'rb') as fh:
            vcard_bytes = fh.read()
        vcf_doc.embfile_add(
            'contact.vcf',
            vcard_bytes,
            filename='contact.vcf',
            ufilename='contact.vcf',
            desc='Add this contact to your address book',
        )
        tmp_vcf_out = out_path + '.vcf_tmp.pdf'
        vcf_doc.save(tmp_vcf_out, garbage=4, deflate=True)
        vcf_doc.close()
        os.replace(tmp_vcf_out, out_path)

    return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--template',  required=True)
    ap.add_argument('--employee',  required=True)
    ap.add_argument('--out',       required=True)
    ap.add_argument('--vcard',     default='', help='Optional path to a .vcf file to embed')
    ap.add_argument('--profile',   default='web', choices=['web', 'print'],
                    help='web (default): subset fonts, no bleed. '
                         'print: full font embed, use with --for-print for bleed+crop marks.')
    ap.add_argument('--for-print', action='store_true',
                    help='Expand page by 3mm bleed and draw corner crop marks.')
    ap.add_argument('--watermark', default='',
                    help='Stamp the given text diagonally across the centre of every '
                         'page (e.g. "SAMPLE - NOT FOR PRINT"). Empty = no watermark.')
    args = ap.parse_args()
    sys.exit(render(args.template, args.employee, args.out, args.vcard,
                    profile=args.profile, for_print=args.for_print,
                    watermark=args.watermark))


if __name__ == '__main__':
    main()
