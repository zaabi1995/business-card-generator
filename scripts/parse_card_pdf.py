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
    DPI = 300
    SCALE = DPI / 72.0

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
                    text = span['text'].strip()
                    if not text:
                        continue
                    spans.append({
                        'text': text,
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

        fields = []
        text_bboxes_for_redaction = []
        for sp in grouped:
            fonts_used_raw.add(sp['font'])
            family, weight, italic = font_to_family_and_weight(sp['font'])
            field_key, is_static = classify_span(sp['text'], sp['size'],
                                                 color_int_to_hex(sp['color']),
                                                 sp['bbox'], height_pt, grouped)
            x0, y0, x1, y1 = sp['bbox']
            fields.append({
                'field_key': field_key,
                'detected_text': sp['text'],
                'is_static': is_static,
                'x_pt': round(x0, 2), 'y_pt': round(y0, 2),
                'w_pt': round(x1 - x0, 2), 'h_pt': round(y1 - y0, 2),
                'x_px': int(round(x0 * SCALE)),
                'y_px': int(round(y0 * SCALE)),
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
            })
            text_bboxes_for_redaction.append(sp['bbox'])

        # ── 3. Detect QR placeholder area ──
        qr_area = detect_qr_area(page, text_bboxes_for_redaction)

        # ── 4. Render background WITHOUT the detected text ──
        # Strategy: render full page at 300 DPI, then mask out text bboxes.
        # PyMuPDF redaction would alter the PDF; simpler: render full, then
        # in PIL, fill text bboxes with neighbouring pixel color.
        pix = page.get_pixmap(matrix=fitz.Matrix(SCALE, SCALE), alpha=False)
        bg_path = os.path.join(output_dir, f'bg-page-{page_num}.png')
        pix.save(bg_path)

        # Background WITH text: keep a copy for preview
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
            pix2 = page_for_redaction.get_pixmap(matrix=fitz.Matrix(SCALE, SCALE), alpha=False)
            pix2.save(bg_path)
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

    return {
        'pages': pages_out,
        'fonts_used': fonts_used,
        'missing_fonts': missing,
    }


def main():
    if len(sys.argv) < 3:
        print('Usage: parse_card_pdf.py <pdf_path> <output_dir> [installed_fonts.txt]', file=sys.stderr)
        sys.exit(2)
    pdf_path = sys.argv[1]
    output_dir = sys.argv[2]
    installed_fonts_path = sys.argv[3] if len(sys.argv) > 3 else None

    result = parse_pdf(pdf_path, output_dir, installed_fonts_path)
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
