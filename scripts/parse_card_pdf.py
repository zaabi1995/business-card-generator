#!/usr/bin/env python3
"""
Parse a 1-2 page business card PDF and extract a Cardify template definition.

Output JSON:
{
  "pages": [
    {
      "page_number": 1|2,
      "side": "front" | "back",
      "width_pt": float, "height_pt": float,
      "width_px": int (at 300 DPI), "height_px": int,
      "background_path": "<relative path>",
      "qr_area": {x, y, w, h, hint} | null,
      "fields": [
        {
          "field_key": "name_en|position_en|mobile|email|website|address|social|company_tagline|qr_code|custom",
          "detected_text": str,
          "is_static": bool,
          "x_pt": float, "y_pt": float, "w_pt": float, "h_pt": float,
          "x_px": int, "y_px": int, "w_px": int, "h_px": int,
          "font_family": str,
          "font_size_pt": float,
          "font_weight": int (400|700),
          "italic": bool,
          "color": "#rrggbb",
          "align": "left|center|right",
          "anchor": "tl|tc|tr|ml|mc|mr|bl|bc|br"
        }
      ]
    }
  ],
  "fonts_used": ["Sora-Regular", "Lato-Medium", ...],
  "missing_fonts": ["Sora-Regular"]   # not in installed list
}

Usage:
    python3 parse_card_pdf.py <pdf_path> <output_dir> [installed_fonts.txt]
"""

import sys
import os
import re
import json
import shutil
from pathlib import Path

import fitz  # PyMuPDF


# ────────── Field detection patterns and heuristics ──────────

POSITION_KEYWORDS = {
    'manager', 'engineer', 'director', 'officer', 'architect', 'specialist',
    'analyst', 'executive', 'consultant', 'head', 'president', 'ceo', 'cfo',
    'cto', 'coo', 'founder', 'lead', 'associate', 'assistant', 'designer',
    'developer', 'researcher', 'advisor', 'partner', 'principal', 'supervisor',
    'admin', 'clerk', 'accountant', 'lawyer', 'doctor', 'professor',
    'sales', 'marketing', 'product owner', 'representative',
}

STATIC_PHRASE_HINTS = [
    'an omantel company', 'a bhd group', 'powered by', 'made with',
    'all rights reserved', 'follow us', 'visit us', 'scan to',
]

PHONE_RE = re.compile(r'^[MTPF]\s+[+\d]')                     # "M +971..." "T +968..."
PHONE_PLAIN_RE = re.compile(r'^\+?\d[\d\s\-\(\)]{6,}$')
EMAIL_PFX_RE = re.compile(r'^E\s+\S+@\S+\.\S+', re.I)         # "E foo@bar.com"
EMAIL_PLAIN_RE = re.compile(r'^\S+@[\w.-]+\.\w{2,}$')
URL_RE = re.compile(r'^(https?://|www\.)[\w.\-/?=&%]+$', re.I)
SOCIAL_RE = re.compile(r'^@[\w.]{2,30}$')
PLUSCODE_RE = re.compile(r'^[A-Z0-9]{4,8}\+[A-Z0-9]{2,3}\b')


def classify_span(text, size_pt, color_hex, bbox, page_h, all_spans):
    """Return (field_key, is_static)."""
    t = text.strip()
    tl = t.lower()

    if PHONE_RE.match(t):
        return 'mobile', False
    if EMAIL_PFX_RE.match(t) or EMAIL_PLAIN_RE.match(t):
        return 'email', False
    if URL_RE.match(t):
        return 'website', False
    if SOCIAL_RE.match(t):
        return 'social', False
    if PLUSCODE_RE.match(t):
        return 'address', False
    if PHONE_PLAIN_RE.match(t):
        return 'mobile', False

    # Static phrase hints (locked, not a field)
    for phrase in STATIC_PHRASE_HINTS:
        if phrase in tl:
            return 'company_tagline', True

    # Position keywords
    for kw in POSITION_KEYWORDS:
        if kw in tl:
            return 'position_en', False

    # Name detection: largest text on page, top half, alphabetic, multi-word
    span_sizes = [s['size'] for s in all_spans]
    max_size = max(span_sizes) if span_sizes else 0
    if (size_pt >= max_size * 0.85
            and len(t.split()) >= 2
            and not any(c.isdigit() for c in t)
            and bbox[1] < page_h * 0.6):
        return 'name_en', False

    # If single word starts uppercase, mid-page, looks like a label
    if size_pt >= 8 and len(t) <= 40 and not any(c.isdigit() for c in t):
        return 'custom', False

    return 'custom', False


def color_int_to_hex(c):
    if c is None:
        return '#000000'
    return '#{:06x}'.format(c & 0xFFFFFF)


def font_to_family_and_weight(font_name):
    """Lato-Medium → (Lato, 500), Sora-Regular → (Sora, 400), Sora-Bold → (Sora, 700)."""
    if not font_name:
        return ('Arial', 400, False)
    base = font_name.split(',')[0].split('+')[-1]  # strip subset prefix
    italic = 'italic' in base.lower() or 'oblique' in base.lower()
    weight = 400
    weight_map = {
        'thin': 100, 'extralight': 200, 'light': 300, 'regular': 400,
        'medium': 500, 'semibold': 600, 'demibold': 600,
        'bold': 700, 'extrabold': 800, 'black': 900,
    }
    parts = re.split(r'[-_\s]', base)
    family = parts[0]
    for p in parts[1:]:
        pl = p.lower().replace('italic', '').replace('oblique', '')
        if pl in weight_map:
            weight = weight_map[pl]
    return (family, weight, italic)


def detect_qr_area(page, redacted_text_bboxes):
    """Find the largest white-ish rectangle on a coloured page that doesn't overlap text.
    Returns dict {x_pt, y_pt, w_pt, h_pt, hint} or None."""
    drawings = page.get_drawings()
    candidates = []
    for d in drawings:
        if d['type'] not in ('f', 'fs'):  # filled / stroked-fill
            continue
        fill = d.get('fill')
        if fill is None:
            continue
        # White-ish: all components > 0.9
        if isinstance(fill, (list, tuple)):
            f = list(fill)
            if len(f) >= 3:
                r, g, b = f[0], f[1], f[2]
            elif len(f) == 1:
                r = g = b = f[0]
            else:
                continue
            if r < 0.9 or g < 0.9 or b < 0.9:
                continue
        elif isinstance(fill, (int, float)):
            if fill < 0.9:
                continue
        rect = d.get('rect')
        if not rect:
            continue
        x0, y0, x1, y1 = rect
        w = x1 - x0
        h = y1 - y0
        if w < 30 or h < 30:
            continue
        # Aspect close to square (QR is square)
        aspect = max(w, h) / min(w, h) if min(w, h) > 0 else 99
        if aspect > 1.5:
            continue
        candidates.append({'x_pt': x0, 'y_pt': y0, 'w_pt': w, 'h_pt': h, 'area': w * h})

    if not candidates:
        return None
    best = max(candidates, key=lambda c: c['area'])
    return {
        'x_pt': best['x_pt'], 'y_pt': best['y_pt'],
        'w_pt': best['w_pt'], 'h_pt': best['h_pt'],
        'hint': 'detected white square, likely QR placeholder',
    }


def collect_fonts(installed_fonts_path):
    if not installed_fonts_path or not os.path.exists(installed_fonts_path):
        return set()
    fonts = set()
    with open(installed_fonts_path) as f:
        for line in f:
            line = line.strip()
            if line:
                fonts.add(line.lower())
    return fonts


def parse_pdf(pdf_path, output_dir, installed_fonts_path=None):
    os.makedirs(output_dir, exist_ok=True)
    doc = fitz.open(pdf_path)

    # Hard limit: business cards are 1-2 sided. Anything beyond 4 pages is
    # almost certainly the wrong file (a brochure, a catalog) and would only
    # consume CPU + disk for no useful template.
    MAX_PAGES = 4
    if len(doc) > MAX_PAGES:
        print(json.dumps({
            'error': 'too_many_pages',
            'page_count': len(doc),
            'max_pages': MAX_PAGES,
        }))
        sys.exit(0)

    DPI = 300
    SCALE = DPI / 72.0
    # Background images are rasterized at PRINT resolution (1200 DPI), the
    # same multiplier (4x) Fabric.js uses when exporting the final card. This
    # eliminates the upscale step that previously softened vector edges
    # (icons, dot patterns, brand marks) at print time. Field positions stay
    # on the 300 DPI editor canvas so existing templates + Fabric remain
    # backwards-compatible. See docs (cardify CLAUDE.md, "Importer", iter
    # ee8441d).
    BG_DPI = 1200
    BG_SCALE = BG_DPI / 72.0

    installed_fonts = collect_fonts(installed_fonts_path)
    fonts_used_raw = set()

    pages_out = []
    for page_num, page in enumerate(doc, 1):
        side = 'front' if page_num == 1 else ('back' if page_num == 2 else f'page{page_num}')
        width_pt, height_pt = page.rect.width, page.rect.height
        width_px = int(round(width_pt * SCALE))
        height_px = int(round(height_pt * SCALE))

        # ── 1. Extract all text spans ──
        spans = []
        for block in page.get_text('dict')['blocks']:
            if block['type'] != 0:
                continue
            for line in block['lines']:
                for span in line['spans']:
                    raw = span['text']
                    if not raw or raw.strip() == '':
                        continue
                    # Keep the original whitespace from PyMuPDF. The bbox
                    # PyMuPDF returns covers the full visual extent of the
                    # span including any trailing space. If we strip the
                    # text but keep the bbox, the rendered word ends short
                    # of the bbox edge, and adjacent same-line words like
                    # "An ", "Omantel ", "Company" end up visually touching
                    # because each renders only the stripped text starting
                    # at its bbox.left, with no trailing space to push the
                    # next word away. Preserving whitespace fixes the
                    # spacing without needing colour-aware merging.
                    spans.append({
                        'text': raw,
                        'bbox': span['bbox'],          # (x0, y0, x1, y1) in pt
                        'font': span['font'],
                        'size': span['size'],
                        'color': span['color'],
                    })

        # ── 2. Group adjacent spans on the same line ──
        # Only merge spans that are HORIZONTALLY ADJACENT (x-gap <= 8pt),
        # same y, same font, same size, same color. This preserves multi-column
        # layouts (e.g., contact info left + address right on the same row).
        spans.sort(key=lambda s: (round(s['bbox'][1]), s['bbox'][0]))
        grouped = []
        for sp in spans:
            placed = False
            for g in grouped:
                gy_mid = (g['bbox'][1] + g['bbox'][3]) / 2
                sy_mid = (sp['bbox'][1] + sp['bbox'][3]) / 2
                x_gap = sp['bbox'][0] - g['bbox'][2]   # span starts after group ends
                same_line = abs(gy_mid - sy_mid) < 3
                touching = -1 < x_gap < 8
                if (same_line and touching
                        and g['font'] == sp['font']
                        and abs(g['size'] - sp['size']) < 0.3
                        and g['color'] == sp['color']):
                    g['text'] = (g['text'] + ' ' + sp['text']).strip()
                    g['bbox'] = (
                        min(g['bbox'][0], sp['bbox'][0]),
                        min(g['bbox'][1], sp['bbox'][1]),
                        max(g['bbox'][2], sp['bbox'][2]),
                        max(g['bbox'][3], sp['bbox'][3]),
                    )
                    placed = True
                    break
            if not placed:
                grouped.append(dict(sp))

        # Render the page WITH text intact, used to empirically locate the
        # visible-glyph-top of each field. PyMuPDF's bbox.y0 is the cell-top
        # which sits well above the visible glyph (about 0.25 to 0.42 of
        # fontSize, depending on the font's ascender + leading). Fabric.js
        # IText positions textbox.top close to the visible glyph, so blindly
        # using bbox.y0 makes rendered text appear "up a bit" by 0.5 to 1.5 mm.
        # We pixel-scan the rendered page to find the actual visible top.
        try:
            from PIL import Image
            import io
            _page_render_pix = page.get_pixmap(matrix=fitz.Matrix(BG_SCALE, BG_SCALE), alpha=False)
            _page_render_img = Image.frombytes('RGB', (_page_render_pix.width, _page_render_pix.height), _page_render_pix.samples)
            # Sample bg color from a corner pixel
            _bg_color = _page_render_img.getpixel((4, 4))
        except Exception as _e:
            print(f'WARN: text-y empirical-scan disabled, {_e}', file=sys.stderr)
            _page_render_img = None
            _bg_color = None

        # Fabric.js IText with default settings actually puts the visible
        # ascender ~0.20 * fontSize BELOW textbox.top, not 0.08. Empirically
        # tuned (Apr 29 2026) after a v1 with 0.08 over-corrected by ~0.2 of
        # fontSize on the Otech import. Treat as a font/render-stack constant
        # until/unless we find a font where it differs.
        FABRIC_HALF_LEADING_FRAC = 0.20

        def _empirical_visible_top_pt(bbox_pt, color_hex):
            """Find the topmost row inside bbox that contains ink matching
            the field's text color, ignoring the bg pattern (which may also
            differ from the page-corner sample). Returns y in PDF points,
            or None if scan failed.
            """
            if _page_render_img is None:
                return None
            x0, y0, x1, y1 = bbox_pt
            rx0 = max(0, int(x0 * BG_SCALE))
            rx1 = min(_page_render_img.width,  int(x1 * BG_SCALE))
            ry0 = max(0, int(y0 * BG_SCALE))
            ry1 = min(_page_render_img.height, int(y1 * BG_SCALE))
            if rx1 <= rx0 or ry1 <= ry0:
                return None
            try:
                import numpy as _np
                # Decode the field's color
                ch = color_hex.lstrip('#')
                target = _np.array([int(ch[0:2], 16), int(ch[2:4], 16), int(ch[4:6], 16)], dtype=_np.int16)
                crop = _page_render_img.crop((rx0, ry0, rx1, ry1)).convert('RGB')
                arr = _np.asarray(crop).astype(_np.int16)
                # Pixel matches target if all 3 channels are close (within 60).
                close = (_np.abs(arr - target).max(axis=2) < 60)
                # Need at least N matching pixels in a row to filter dot-pattern noise
                row_counts = close.sum(axis=1)
                # Use 8% of crop width as the threshold (a real glyph row hits many pixels)
                min_row = max(2, int(close.shape[1] * 0.08))
                rows_with_text = _np.where(row_counts >= min_row)[0]
                if len(rows_with_text) == 0:
                    return None
                visible_top_in_crop_px = int(rows_with_text[0])
                return ry0 / BG_SCALE + visible_top_in_crop_px / BG_SCALE
            except Exception:
                return None

        # Field keys that map to per-employee data. Anything else (taglines,
        # social handles, decorative captions) is "static" and gets baked
        # into the bg image at print DPI for pixel-perfect source-PDF
        # alignment. This matches CardifyTemplateImporter::suggestBinding.
        TYPED_DYNAMIC_KEYS = {
            'name_en', 'name_ar', 'position_en', 'position_ar',
            'mobile', 'phone', 'fax', 'email', 'website',
            'address', 'address_en', 'address_ar',
            'company_en', 'company_ar',
        }

        fields = []
        text_bboxes_for_redaction = []  # only dynamic, redacted from bg
        all_text_bboxes = []             # all spans, used for QR detection
        for sp in grouped:
            fonts_used_raw.add(sp['font'])
            family, weight, italic = font_to_family_and_weight(sp['font'])
            field_key, is_static = classify_span(sp['text'], sp['size'],
                                                 color_int_to_hex(sp['color']),
                                                 sp['bbox'], height_pt, grouped)
            # Promote anything that doesn't map to a typed dynamic key into
            # static (mirrors the persist-time rule in CardifyTemplateImporter
            # ::suggestBinding). This is what makes "@otech" / "An Omantel
            # Company" / "Oman" classify as static so they bake into the bg.
            if not is_static and field_key not in TYPED_DYNAMIC_KEYS:
                is_static = True
            x0, y0, x1, y1 = sp['bbox']

            # Empirical correction: replace y0 (PyMuPDF cell-top) with the
            # actual rendered visible-glyph-top, then back off Fabric's
            # half-leading so the on-canvas textbox.top lands the visible
            # glyph at the same y as the source PDF.
            visible_top_pt = _empirical_visible_top_pt(
                (x0, y0, x1, y1), color_int_to_hex(sp['color'])
            )
            if visible_top_pt is not None:
                fabric_top_pt = visible_top_pt - FABRIC_HALF_LEADING_FRAC * sp['size']
                # Don't let the correction push us above the original cell-top
                # (would be wrong if the bbox was already tight to the glyph).
                y0_corrected = max(y0, fabric_top_pt)
            else:
                y0_corrected = y0

            fields.append({
                'field_key': field_key,
                'detected_text': sp['text'],
                'is_static': is_static,
                'x_pt': round(x0, 2), 'y_pt': round(y0_corrected, 2),
                'w_pt': round(x1 - x0, 2), 'h_pt': round(y1 - y0, 2),
                'x_px': int(round(x0 * SCALE)),
                'y_px': int(round(y0_corrected * SCALE)),
                'w_px': int(round((x1 - x0) * SCALE)),
                'h_px': int(round((y1 - y0) * SCALE)),
                'font_raw': sp['font'],
                'font_family': family,
                'font_weight': weight,
                'italic': italic,
                'font_size_pt': round(sp['size'], 2),
                'font_size_px': int(round(sp['size'] * SCALE)),
                'color': color_int_to_hex(sp['color']),
                'align': 'left',
                'y_correction_pt': round(y0_corrected - y0, 3),
                # Static decorations get rendered into the bg by PyMuPDF so
                # they're pixel-identical to the source PDF (no Fabric.js
                # rendering = no font-stack drift). Editor + saver skip them.
                'render_in_bg': bool(is_static),
            })
            all_text_bboxes.append(sp['bbox'])
            # Only redact DYNAMIC text from the bg, static text stays baked
            # in so it renders at the source PDF's exact metrics. (See
            # render_in_bg flag above + card-editor.js skipBakedFields.)
            if not is_static:
                text_bboxes_for_redaction.append(sp['bbox'])

        # ── 3. Detect QR placeholder area ──
        # Use ALL text bboxes (including static decorations) to avoid
        # placing the QR over baked-in text. Redaction set is dynamic-only
        # by design.
        qr_area = detect_qr_area(page, all_text_bboxes)

        # Mark fields whose centre is inside the QR placeholder as static.
        # These are decorative captions like "QR Code, to save the contact"
        # that the designer dropped on top of the QR square; they must not be
        # rendered as employee-data placeholders on the portal.
        if qr_area:
            qx0 = qr_area['x_pt']
            qy0 = qr_area['y_pt']
            qx1 = qx0 + qr_area['w_pt']
            qy1 = qy0 + qr_area['h_pt']
            for f in fields:
                cx = f['x_pt'] + f['w_pt'] / 2.0
                cy = f['y_pt'] + f['h_pt'] / 2.0
                if qx0 <= cx <= qx1 and qy0 <= cy <= qy1:
                    f['is_static'] = True
                    f['field_key'] = 'company_tagline'

        # ── 4. Render background WITHOUT the detected text ──
        # Render at PRINT DPI (BG_SCALE) so vector content (icons, paths,
        # patterns) survives without an upscale at export time. The editor
        # canvas size (width_px / height_px on the field metadata) stays
        # at 300 DPI so existing templates + Fabric coordinate math don't
        # shift. Fabric scales the high-res PNG to fit the canvas in the
        # editor, then samples it at full resolution when exporting at
        # multiplier=4.
        pix = page.get_pixmap(matrix=fitz.Matrix(BG_SCALE, BG_SCALE), alpha=False)
        bg_path = os.path.join(output_dir, f'bg-page-{page_num}.png')
        pix.save(bg_path)

        # Background WITH text: keep a copy for preview (also at print DPI)
        bg_with_text_path = os.path.join(output_dir, f'bg-page-{page_num}-with-text.png')
        shutil.copy(bg_path, bg_with_text_path)

        # Strip text by re-rendering the page with text spans redacted.
        # PyMuPDF: add_redact_annot then apply.
        try:
            page_for_redaction = doc[page_num - 1]
            for bb in text_bboxes_for_redaction:
                x0, y0, x1, y1 = bb
                rect = fitz.Rect(x0 - 0.3, y0 - 0.3, x1 + 0.3, y1 + 0.3)
                page_for_redaction.add_redact_annot(rect, fill=None)
            page_for_redaction.apply_redactions(images=0)
            pix2 = page_for_redaction.get_pixmap(matrix=fitz.Matrix(BG_SCALE, BG_SCALE), alpha=False)
            pix2.save(bg_path)
            # Also emit a true vector SVG of the redacted page. Surfaces that
            # render to PDF (card-pdf.php) or to print will eventually prefer
            # this over the raster bg, no quality loss at any output size.
            try:
                svg_path = os.path.join(output_dir, f'bg-page-{page_num}.svg')
                svg_str = page_for_redaction.get_svg_image(text_as_path=False)
                with open(svg_path, 'w', encoding='utf-8') as fh:
                    fh.write(svg_str)
            except Exception as e:
                print(f'WARN: svg export failed for page {page_num}: {e}', file=sys.stderr)
        except Exception as e:
            print(f'WARN: redaction failed for page {page_num}: {e}', file=sys.stderr)

        pages_out.append({
            'page_number': page_num,
            'side': side,
            'width_pt': round(width_pt, 2),
            'height_pt': round(height_pt, 2),
            'width_px': width_px,
            'height_px': height_px,
            'background_path': os.path.basename(bg_path),
            'background_with_text_path': os.path.basename(bg_with_text_path),
            'background_svg_path': f'bg-page-{page_num}.svg',
            'background_dpi': BG_DPI,
            'qr_area': qr_area,
            'fields': fields,
        })

    # ── 5. Compile fonts list and missing fonts ──
    # Group raw font names by family (Lato-Medium and Lato-Regular share family Lato)
    fonts_used = sorted(fonts_used_raw)
    missing = []
    for f in fonts_used:
        family, _, _ = font_to_family_and_weight(f)
        if installed_fonts and family.lower() not in installed_fonts:
            missing.append({'raw_name': f, 'family': family})

    # ── 6. Extract embedded fonts to <output_dir>/fonts/. CardPDFRenderer
    # picks them up via templates.fonts_dir at render time.
    try:
        import importlib, sys as _sys
        _scripts_dir = os.path.dirname(os.path.abspath(__file__))
        if _scripts_dir not in _sys.path:
            _sys.path.insert(0, _scripts_dir)
        extract_main = importlib.import_module('extract_template_fonts').main
    except Exception:
        extract_main = None
    fonts_dir_rel = None
    if extract_main is not None:
        try:
            rc = extract_main(output_dir)
            if rc == 0:
                fonts_dir_rel = os.path.join(os.path.basename(output_dir), 'fonts')
        except Exception as e:
            print(f'WARN: font extraction failed: {e}', file=sys.stderr)

    return {
        'pages':         pages_out,
        'fonts_used':    fonts_used,
        'missing_fonts': missing,
        'fonts_dir_rel': fonts_dir_rel,
    }


def main():
    if len(sys.argv) < 3:
        print(json.dumps({'error': 'usage', 'message': 'parse_card_pdf.py <pdf_path> <output_dir> [installed_fonts.txt]'}))
        sys.exit(2)
    pdf_path = sys.argv[1]
    output_dir = sys.argv[2]
    installed_fonts_path = sys.argv[3] if len(sys.argv) > 3 else None

    try:
        result = parse_pdf(pdf_path, output_dir, installed_fonts_path)
        print(json.dumps(result, indent=2))
    except fitz.FileDataError:
        print(json.dumps({'error': 'pdf_corrupt', 'message': 'Could not open PDF, file is corrupt or not a real PDF.'}))
        sys.exit(0)
    except FileNotFoundError as e:
        print(json.dumps({'error': 'file_not_found', 'message': str(e)}))
        sys.exit(0)
    except Exception as e:
        import traceback
        print(json.dumps({
            'error': 'parser_exception',
            'message': str(e),
            'type': type(e).__name__,
            'traceback': traceback.format_exc()[-2000:],
        }))
        sys.exit(0)


if __name__ == '__main__':
    main()
