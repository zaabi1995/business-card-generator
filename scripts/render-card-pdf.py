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
import io
import json
import os
import re
import sys
import unicodedata
import fitz

try:
    from fontTools.subset import Subsetter
    from fontTools.ttLib import TTFont
    _HAS_FONTTOOLS = True
except ImportError:
    _HAS_FONTTOOLS = False


def _subset_font_buffer(font_buffer: bytes, used_text: str) -> bytes:
    # PyMuPDF's set_simple=True flips the encoding to WinAnsi but still embeds
    # the full TTF for dynamic-field text. Trim it to the glyphs we actually
    # draw. Note: SVG-background text goes through fitz.convert_to_pdf, which
    # pulls fonts via fontconfig and is unaffected by this subsetter, so the
    # background page typically dominates the PDF size budget.
    if not _HAS_FONTTOOLS or not used_text:
        return font_buffer
    try:
        font = TTFont(io.BytesIO(font_buffer))
        subsetter = Subsetter()
        # Always include the space + a couple of common punctuation
        # codepoints PyMuPDF may insert when measuring text width.
        subsetter.populate(text=used_text + ' ()')
        subsetter.subset(font)
        out = io.BytesIO()
        font.save(out)
        return out.getvalue()
    except Exception as e:
        print(f'WARN: font subset failed: {e}', file=sys.stderr)
        return font_buffer

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


def _load_font_buffers(fonts_dir: str, company_fonts_dir: str = None) -> dict:
    """Return {filename_stem: bytes} for all .ttf/.otf files in fonts_dir.
    If company_fonts_dir is provided, ALSO load files from there; those take
    precedence so user-uploaded full fonts override parser-extracted subsets
    (which lack glyphs outside the source PDF's character set).
    """
    buffers = {}
    extensions = ('.ttf', '.otf')
    if os.path.isdir(fonts_dir):
        for fname in os.listdir(fonts_dir):
            if fname.lower().endswith(extensions):
                with open(os.path.join(fonts_dir, fname), 'rb') as fh:
                    buffers[os.path.splitext(fname)[0]] = fh.read()
    # Company-uploaded fonts override extracted subsets.
    if company_fonts_dir and os.path.isdir(company_fonts_dir):
        for fname in os.listdir(company_fonts_dir):
            if fname.lower().endswith(extensions):
                with open(os.path.join(company_fonts_dir, fname), 'rb') as fh:
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


def _resolve_employee_value(field_key: str, employee: dict, template_default: str = '') -> str:
    """Return the employee's value for a template field_key, trying:
    1. Exact key (e.g. 'name_en')
    2. Locale-stripped key (e.g. 'name' for 'name_en' / 'name_ar')
    3. Fall back to the template's detected_text (source PDF sample) for
       any field when the employee has no value. Per user direction:
       always use the source PDF data as the default.
    """
    val = (employee.get(field_key) or '').strip()
    if val:
        return val
    if field_key.endswith('_en'):
        bare = field_key[:-3]
        val = (employee.get(bare) or '').strip()
        if val:
            return val
    elif field_key.endswith('_ar'):
        bare = field_key[:-3]
        val = (employee.get(bare) or '').strip()
        if val:
            return val
    else:
        val = (employee.get(field_key + '_en') or '').strip()
        if val:
            return val
        val = (employee.get(field_key + '_ar') or '').strip()
        if val:
            return val
    # Preserve leading / trailing whitespace from the source PDF's detected
    # text, the bbox x assumes the space character is rendered. Stripping
    # it shifts the visible glyph left by the space-width and collides
    # with the preceding field (e.g. ' www.otech.om' next to '|').
    return template_default or ''


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


# ---------------------------------------------------------------------------
# CMYK + cut-line support (press profile). See CLAUDE.md "CMYK print pipeline".
# When a --cmyk config is supplied, the press render emits DeviceCMYK with the
# tenant's exact brand values (e.g. Otech blue -> C100 M90 Y0 K2, orange ->
# C0 M70 Y100 K0), registration-black crop marks, and a "CutContour" spot-color
# rounded-rect on its own OCG layer at the trim, matching the BHD reference.
# ---------------------------------------------------------------------------

def _load_cmyk_cfg(path):
    if not path:
        return None
    try:
        with open(path) as fh:
            cfg = json.load(fh)
        return cfg if cfg and cfg.get('enabled', True) else None
    except Exception as e:
        print(f'WARN: cmyk cfg load failed: {e}', file=sys.stderr)
        return None


def _cmyk_for_rgb(r, g, b, cfg):
    """Map one RGB (0-255) to (c,m,y,k) floats 0-1. Brand colours within tol
    snap to their EXACT spec CMYK; white -> paper; everything else converts via
    the standard GCR formula (pure neutrals land K-only)."""
    if cfg:
        for col in cfg.get('colors', []):
            cr, cg, cb = col['rgb']
            tol = col.get('tol', 28)
            if abs(r - cr) <= tol and abs(g - cg) <= tol and abs(b - cb) <= tol:
                c, m, y, k = col['cmyk']
                return (c / 100.0, m / 100.0, y / 100.0, k / 100.0)
    if r > 250 and g > 250 and b > 250:
        return (0.0, 0.0, 0.0, 0.0)
    rf, gf, bf = r / 255.0, g / 255.0, b / 255.0
    k = 1.0 - max(rf, gf, bf)
    if k >= 0.999:
        return (0.0, 0.0, 0.0, 1.0)
    return ((1 - rf - k) / (1 - k), (1 - gf - k) / (1 - k), (1 - bf - k) / (1 - k), k)


def _rgb01_to_cmyk(color, cfg):
    """Convert a PyMuPDF RGB colour tuple (floats 0-1) to a CMYK tuple."""
    r, g, b = [int(round(max(0.0, min(1.0, c)) * 255)) for c in color]
    return _cmyk_for_rgb(r, g, b, cfg)


def _pil_to_cmyk_pixmap(im, cfg):
    """Vectorised RGB(A) PIL image -> DeviceCMYK fitz.Pixmap with brand keying.
    Alpha is flattened onto white first (CMYK pixmaps carry no alpha)."""
    import numpy as np
    from PIL import Image
    if im.mode in ('RGBA', 'LA') or (im.mode == 'P' and 'transparency' in im.info):
        rgba = im.convert('RGBA')
        bg = Image.new('RGB', im.size, (255, 255, 255))
        bg.paste(rgba, mask=rgba.split()[-1])
        im = bg
    else:
        im = im.convert('RGB')
    arr = np.asarray(im).astype(np.float32)
    R, G, B = arr[..., 0], arr[..., 1], arr[..., 2]
    Rf, Gf, Bf = R / 255.0, G / 255.0, B / 255.0
    K = 1.0 - np.maximum(np.maximum(Rf, Gf), Bf)
    den = np.clip(1.0 - K, 1e-6, None)
    C = (1 - Rf - K) / den
    M = (1 - Gf - K) / den
    Y = (1 - Bf - K) / den
    white = (R > 250) & (G > 250) & (B > 250)
    C[white] = M[white] = Y[white] = K[white] = 0.0

    maxc = np.maximum(np.maximum(R, G), B)
    minc = np.minimum(np.minimum(R, G), B)
    chroma = maxc - minc

    # Tint-family colours: map the WHOLE hue family (the dot-mesh + gradients,
    # not just the flat field) to TINTS of the brand colour. Naive RGB->CMYK
    # pushes light blues to M>C, i.e. magenta/pink, so without this the solid
    # field is right but the texture renders as a pink mesh. A tint of the brand
    # CMYK keeps M<C, so every shade stays in-hue (matches the press reference).
    for col in (cfg.get('colors') or []):
        if not col.get('tint_family'):
            continue
        cr, cg, cb = col['rgb']
        cc, cm, cy, ck = col['cmyk']
        dom = int(np.argmax([cr, cg, cb]))                 # brand dominant channel
        chroma_brand = float(max(cr, cg, cb) - min(cr, cg, cb))
        if chroma_brand <= 0:
            continue
        chan = (R, G, B)[dom]
        # In-family = brand's dominant channel is also this pixel's max channel,
        # and the pixel is colourful enough (skip near-grey / white-text edges).
        fam = (chan >= maxc) & (chroma > 18)
        f = np.clip(chroma / chroma_brand, 0.0, 1.0)
        C[fam] = (cc / 100.0) * f[fam]
        M[fam] = (cm / 100.0) * f[fam]
        Y[fam] = (cy / 100.0) * f[fam]
        K[fam] = (ck / 100.0) * f[fam]

    # Exact brand flats pinned LAST so the solid field hits spec precisely.
    for col in (cfg.get('colors') or []):
        cr, cg, cb = col['rgb']
        tol = col.get('tol', 28)
        cc, cm, cy, ck = col['cmyk']
        mask = (np.abs(R - cr) <= tol) & (np.abs(G - cg) <= tol) & (np.abs(B - cb) <= tol)
        C[mask], M[mask], Y[mask], K[mask] = cc / 100.0, cm / 100.0, cy / 100.0, ck / 100.0
    cmyk8 = np.clip(np.stack([C, M, Y, K], -1) * 255.0, 0, 255).astype('uint8')
    return fitz.Pixmap(fitz.csCMYK, im.width, im.height, cmyk8.tobytes(), False)


def _png_to_cmyk_pixmap(png_path, cfg, bleed_pt=0.0, card_w_pt=0.0):
    """Build the CMYK bg pixmap. When bleed_pt/card_w_pt are given, the image is
    edge-extended by the bleed amount (replicate edge pixels) so a full-bleed
    design actually bleeds past the trim. The padding is sized so the trim
    content still lands exactly on card_rect when the result is placed over the
    full (bleed-expanded) page."""
    from PIL import Image
    im = Image.open(png_path).convert('RGB')
    if bleed_pt > 0 and card_w_pt > 0:
        import numpy as np
        pad = int(round(bleed_pt * im.width / card_w_pt))
        if pad > 0:
            arr = np.pad(np.asarray(im), ((pad, pad), (pad, pad), (0, 0)), mode='edge')
            im = Image.fromarray(arr)
    return _pil_to_cmyk_pixmap(im, cfg)


def _ensure_subdict(doc, owner, key):
    """Resolve owner/key to a concrete (indirect) dict xref, creating it if
    missing. Page Resources are usually indirect, so deep-path xref_set_key
    fails ('path has indirects'); operate on a resolved xref instead."""
    t, v = doc.xref_get_key(owner, key)
    if t == 'xref':
        return int(v.split()[0])
    if t == 'dict':
        nx = doc.get_new_xref()
        doc.update_object(nx, v)
        doc.xref_set_key(owner, key, f'{nx} 0 R')
        return nx
    nx = doc.get_new_xref()
    doc.update_object(nx, '<< >>')
    doc.xref_set_key(owner, key, f'{nx} 0 R')
    return nx


def _rounded_rect_ops(x0, y0, x1, y1, r):
    """PDF path operators (user space, y-up) for a rounded rectangle."""
    r = max(0.0, min(r, (x1 - x0) / 2.0, (y1 - y0) / 2.0))
    k = 0.5523 * r
    f = lambda v: '%.3f' % v
    return ' '.join([
        f'{f(x0 + r)} {f(y0)} m', f'{f(x1 - r)} {f(y0)} l',
        f'{f(x1 - r + k)} {f(y0)} {f(x1)} {f(y0 + r - k)} {f(x1)} {f(y0 + r)} c',
        f'{f(x1)} {f(y1 - r)} l',
        f'{f(x1)} {f(y1 - r + k)} {f(x1 - r + k)} {f(y1)} {f(x1 - r)} {f(y1)} c',
        f'{f(x0 + r)} {f(y1)} l',
        f'{f(x0 + r - k)} {f(y1)} {f(x0)} {f(y1 - r + k)} {f(x0)} {f(y1 - r)} c',
        f'{f(x0)} {f(y0 + r)} l',
        f'{f(x0)} {f(y0 + r - k)} {f(x0 + r - k)} {f(y0)} {f(x0 + r)} {f(y0)} c', 'h',
    ])


def _finalize_press(out_path, cfg, bleed_pt):
    """Two-step press finalisation on the already-saved PDF:
      1. Ghostscript converts any residual RGB (the Arabic htmlbox text) to
         DeviceCMYK, leaving the already-CMYK artwork untouched.
      2. Inject a 'CutContour' spot-colour rounded-rect on its own OCG layer
         at the trim boundary of every page (radius from cfg)."""
    import subprocess
    import os as _os
    # --- 1. Ghostscript RGB -> CMYK (preserve existing CMYK + image res) ---
    try:
        gtmp = out_path + '.gs.pdf'
        gs = subprocess.run([
            'gs', '-dBATCH', '-dNOPAUSE', '-dSAFER', '-sDEVICE=pdfwrite',
            '-dProcessColorModel=/DeviceCMYK', '-sColorConversionStrategy=CMYK',
            '-dOverrideICC=false', '-dAutoRotatePages=/None',
            '-dDownsampleColorImages=false', '-dDownsampleGrayImages=false',
            '-dAutoFilterColorImages=false', '-dColorImageFilter=/FlateEncode',
            '-dDownsampleMonoImages=false', f'-sOutputFile={gtmp}', out_path,
        ], capture_output=True, timeout=90)
        if gs.returncode == 0 and _os.path.isfile(gtmp) and _os.path.getsize(gtmp) > 1024:
            _os.replace(gtmp, out_path)
        else:
            if _os.path.isfile(gtmp):
                _os.remove(gtmp)
            print(f'WARN: gs cmyk pass skipped rc={gs.returncode} {gs.stderr[:200]!r}',
                  file=sys.stderr)
    except Exception as e:
        print(f'WARN: gs cmyk pass failed: {e}', file=sys.stderr)

    # --- 2. Inject CutContour spot + OCG rounded-rect at trim, per page ---
    try:
        radius_pt = float(cfg.get('corner_radius_mm', 1.5)) * 72.0 / 25.4
        line_w = float(cfg.get('cut_line_width_pt', 0.5))
        doc = fitz.open(out_path)
        ocg = doc.add_ocg('CutContour', on=True)
        fn = doc.get_new_xref()
        doc.update_object(fn, '<< /FunctionType 2 /Domain [0 1] /C0 [0 0 0 0] '
                              '/C1 [0 1 0 0] /N 1 >>')
        cs = doc.get_new_xref()
        doc.update_object(cs, f'[ /Separation /CutContour /DeviceCMYK {fn} 0 R ]')
        for page in doc:
            pw, ph = page.rect.width, page.rect.height
            x0, y0 = bleed_pt, bleed_pt
            x1, y1 = pw - bleed_pt, ph - bleed_pt
            # clean_contents FIRST: it rebuilds Resources and drops anything the
            # current content doesn't reference, so wiring CutCS/OCcut before it
            # would strip them (MuPDF then errors 'cannot find CutCS').
            page.clean_contents()
            res = _ensure_subdict(doc, page.xref, 'Resources')
            csd = _ensure_subdict(doc, res, 'ColorSpace')
            doc.xref_set_key(csd, 'CutCS', f'{cs} 0 R')
            prp = _ensure_subdict(doc, res, 'Properties')
            doc.xref_set_key(prp, 'OCcut', f'{ocg} 0 R')
            path = _rounded_rect_ops(x0, y0, x1, y1, radius_pt)
            cut = ('q /OC /OCcut BDC /CutCS CS 1 SC %.3f w %s S EMC Q'
                   % (line_w, path)).encode()
            cx = page.get_contents()[0]
            doc.update_stream(cx, doc.xref_stream(cx) + b'\n' + cut)
        tmp = out_path + '.cut.pdf'
        doc.save(tmp, garbage=3, deflate=True)
        doc.close()
        _os.replace(tmp, out_path)
    except Exception as e:
        print(f'WARN: cut-layer injection failed: {e}', file=sys.stderr)


def _draw_crop_marks(page, card_rect, mark_len_pt=14.17, bleed_pt=8.5,
                     line_width=0.71, color=(0, 0, 0)):
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
        shape.finish(color=color, width=line_width, fill=None, closePath=False)
        # Vertical stroke
        shape.draw_line(fitz.Point(cx, vy0), fitz.Point(cx, vy1))
        shape.finish(color=color, width=line_width, fill=None, closePath=False)
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


# Arabic shaping: PyMuPDF's insert_text draws glyphs in logical order
# without applying contextual forms (init/medi/fina/isol) or bidi
# reordering. For Arabic text we pre-shape with arabic_reshaper +
# python-bidi so insert_text receives the visual-order presentation
# forms ready to draw left-to-right.
try:
    import arabic_reshaper
    from bidi.algorithm import get_display
    # arabic_reshaper can resolve to an empty *namespace package* (no
    # reshape attr) when its files are present but unreadable by the
    # running user (e.g. pip installed root-only 0640, PHP-FPM runs as
    # www). That state applies get_display WITHOUT reshape -> isolated,
    # reversed glyphs. Require the real callable so we fail loud instead.
    _HAS_BIDI = hasattr(arabic_reshaper, 'reshape')
    if not _HAS_BIDI:
        print('WARN: arabic_reshaper imported without reshape() '
              '(unreadable install? check perms on dist-packages/arabic_reshaper)',
              file=sys.stderr)
except ImportError:
    _HAS_BIDI = False


def _shape_arabic(text: str) -> str:
    """Reshape Arabic text into its visual-order presentation forms.
    No-op when bidi libs aren't installed or the text has no Arabic."""
    if not _HAS_BIDI:
        return text
    if not any('؀' <= c <= 'ۿ' or 'ﭐ' <= c <= '﻿' for c in text):
        return text
    reshaped = arabic_reshaper.reshape(text)
    # Revert ISOLATED presentation forms (U+FBxx/U+FExx) back to their base
    # codepoint so PyMuPDF draws the font's nominal glyph, which is what
    # HarfBuzz/browsers use for an isolated letter. A font's dedicated
    # *.isol glyph can carry decorative flourishes that differ from the
    # nominal form (e.g. DIN Next LT Arabic gives isolated heh U+FEE9 a
    # tail -> "سارهـ"). We keep initial/medial/final forms as-is since
    # those match HarfBuzz for connected letters.
    out = []
    for ch in reshaped:
        decomp = unicodedata.decomposition(ch)
        if decomp.startswith('<isolated>'):
            try:
                out.append(chr(int(decomp.split()[1], 16)))
                continue
            except (ValueError, IndexError):
                pass
        out.append(ch)
    return get_display(''.join(out))


# ── HarfBuzz path for GSUB-only Arabic fonts ───────────────────────────────
# The reshape+insert_text path above only works when the font's cmap carries
# Arabic PRESENTATION forms (U+FB50.., U+FE70..) — true for PyMuPDF-extracted
# PDF subsets, NOT for modern foundry fonts that shape via GSUB from base
# codepoints. Drawing reshaped presentation forms with a GSUB-only font yields
# tofu/dots. For that case we render through MuPDF's HTML/Story engine
# (insert_htmlbox), which shapes Arabic correctly via HarfBuzz from the base
# codepoints + the font's GSUB. Gated to Arabic-on-GSUB-only-font so every
# existing (presentation-form) card keeps its exact insert_text rendering.
_PRESFORM_CACHE = {}
_HTMLBOX_FONT_DIR = None
_ARABIC_RANGES = ((0x0600, 0x06FF), (0x0750, 0x077F), (0x08A0, 0x08FF),
                  (0xFB50, 0xFDFF), (0xFE70, 0xFEFF))


def _is_arabic(text: str) -> bool:
    return any(any(lo <= ord(c) <= hi for lo, hi in _ARABIC_RANGES) for c in text)


def _font_has_presforms(font_name: str, font_buf: bytes) -> bool:
    """True if the font's cmap maps Arabic presentation forms (i.e. the legacy
    reshape+insert_text path will render correctly). Cached per font."""
    if font_name in _PRESFORM_CACHE:
        return _PRESFORM_CACHE[font_name]
    has = False
    try:
        fo = fitz.Font(fontbuffer=font_buf)
        for cp in (0xFE8D, 0xFEE3, 0xFEDF, 0xFB50, 0xFEFB, 0xFEA1):
            if fo.has_glyph(cp):
                has = True
                break
    except Exception:
        has = False
    _PRESFORM_CACHE[font_name] = has
    return has


_HB_ARABIC_CACHE = {}


def _font_can_harfbuzz_arabic(font_name: str, font_buf: bytes) -> bool:
    """True if the font has BASE Arabic codepoints (U+06xx) + GSUB shaping
    features (init/medi/fina). When so, MuPDF's HarfBuzz (insert_htmlbox) shapes
    Arabic from base codepoints correctly, INCLUDING ligatures (lam-alef, Allah).
    This is far more reliable than reshape+insert_text, which drops ligatures even
    when the glyphs exist (the cause of dropped letters on the print PDF). Covers
    modern foundry fonts (DialogueME) AND PDF-extracted subsets that keep base
    glyphs + GSUB (DINNextLTArabic). Cached per font."""
    if font_name in _HB_ARABIC_CACHE:
        return _HB_ARABIC_CACHE[font_name]
    ok = False
    try:
        # Use PyMuPDF (always available to PHP-FPM's www user), NOT fontTools,
        # which is root-only on the VPS and made this check silently throw ->
        # False -> the broken reshaper path. If the font maps the common base
        # Arabic letters, MuPDF's HarfBuzz can shape it (joining + ligatures).
        fo = fitz.Font(fontbuffer=font_buf)
        hits = sum(1 for cp in (0x0628, 0x062A, 0x0644, 0x0645,
                                0x0647, 0x064A, 0x0631, 0x0633)
                   if fo.has_glyph(cp))
        ok = hits >= 4
    except Exception:
        ok = False
    _HB_ARABIC_CACHE[font_name] = ok
    return ok


_HB_FONT_CACHE = {}


def _shaped_width_pt(font_buf, text, font_size):
    """Exact shaped advance width (pt) via HarfBuzz, the same engine MuPDF
    uses, so Arabic can be positioned precisely. None if HB is unavailable."""
    try:
        import uharfbuzz as hb
        key = id(font_buf)
        ent = _HB_FONT_CACHE.get(key)
        if ent is None:
            face = hb.Face(font_buf)
            ent = (hb.Font(face), face.upem or 1000)
            _HB_FONT_CACHE[key] = ent
        font, upem = ent
        buf = hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        hb.shape(font, buf)
        return sum(g.x_advance for g in buf.glyph_positions) / upem * font_size
    except Exception:
        return None


def _draw_arabic_htmlbox(page, text, font_name, font_buf, x_pt, y_pt, w_pt,
                         font_size, color_hex, align, bleed_pt, left_x=None):
    """Draw a single line of Arabic via MuPDF HarfBuzz shaping. Position is
    matched to the insert_text path: visible glyph-top ≈ y_pt (em-square top)."""
    import os as _os
    import html as _html
    import tempfile as _tf
    global _HTMLBOX_FONT_DIR
    if _HTMLBOX_FONT_DIR is None:
        _HTMLBOX_FONT_DIR = _tf.mkdtemp(prefix='cardify_hb_')
    safe = ''.join(c for c in font_name if c.isalnum() or c in '-_') or 'CF'
    fp = _os.path.join(_HTMLBOX_FONT_DIR, safe + '.ttf')
    if not _os.path.isfile(fp):
        with open(fp, 'wb') as fh:
            fh.write(font_buf)
    arch = fitz.Archive(_HTMLBOX_FONT_DIR)
    bx = x_pt + bleed_pt
    by = y_pt + bleed_pt
    # Exact shaped width (HarfBuzz, same engine MuPDF uses) so the visible edge
    # lands precisely; fall back to a rough estimate only if HB is unavailable.
    meas = _shaped_width_pt(font_buf, text, font_size)
    if meas is None:
        try:
            meas = fitz.Font(fontbuffer=font_buf).text_length(text, fontsize=font_size)
        except Exception:
            meas = font_size * len(text) * 0.55
    line_h = font_size * 1.7
    top = by - font_size * 0.16   # nudge so glyph-top lands at the em-square top
    css = ("@font-face{font-family:CF;src:url('%s');}"
           "*{font-family:CF;margin:0;padding:0;line-height:1.05;}" % (safe + '.ttf'))
    # insert_htmlbox right-aligns RTL text to the box's RIGHT edge (it ignores
    # text-align:left for dir=rtl). Using the exact width, place the box so the
    # VISIBLE edge matches the field intent:
    #   'right'  -> text RIGHT edge at field right edge (x_pt + w_pt)
    #   'center' -> text centred in the field box
    #   'left'   -> text LEFT edge at the left margin: the English counterpart's
    #               x when known (left_x) else x_pt, so AR aligns exactly with EN
    if align == 'right' and w_pt:
        text_right = bx + float(w_pt)
    elif align == 'center' and w_pt:
        text_right = bx + float(w_pt) / 2.0 + meas / 2.0
    else:
        target_left = (float(left_x) + bleed_pt) if left_x is not None else bx
        text_right = target_left + meas
    box_right = text_right + font_size * 0.50   # +0.44fs corrects rightmost-glyph side bearing (HB advance vs MuPDF ink edge); 0.06 keeps last glyph from clipping
    left = box_right - (meas + font_size * 0.6)  # extra room is empty space on the left
    div = ('<div dir="rtl" style="font-size:%.2fpt;color:%s;text-align:right;'
           'white-space:nowrap;">%s</div>' % (font_size, color_hex, _html.escape(text)))
    rect = fitz.Rect(left, top, box_right, top + line_h)
    try:
        page.insert_htmlbox(rect, div, css=css, archive=arch, scale_low=1.0)
    except Exception:
        page.insert_htmlbox(rect, div, css=css, archive=arch)


def _draw_qr_code(page, qr_spec: dict, employee: dict, template: dict,
                   card_rect, for_print: bool, cmyk_cfg=None) -> None:
    """Render a styled QR code as PNG and insert it as an image onto the
    page. Honors qr_style.color (modules), bg_color (panel), eye_color
    (3 finder eyes), panel_radius_pct, panel_padding_px.
    """
    import qrcode
    from qrcode.image.styledpil import StyledPilImage
    from qrcode.image.styles.colormasks import SolidFillColorMask
    from PIL import Image, ImageDraw
    import io

    style = qr_spec.get('qr_style', {}) or {}
    mode = style.get('mode', 'empty_placeholder')
    # Draw a QR even when the parser only detected a placeholder area:
    # every employee card should ship with a working QR that points to the
    # tenant profile (qr.php tracker → profile redirect). Style defaults to
    # plain black-on-white when the parser didn't sample brand colours.
    if mode == 'empty_placeholder':
        style = {
            'color': style.get('color') or '#000000',
            'bg_color': style.get('bg_color') or '#ffffff',
            'eye_color': style.get('eye_color'),
            'panel_padding_px': style.get('panel_padding_px', 0),
            'panel_radius_pct': style.get('panel_radius_pct', 0),
        }

    # Build the QR data URL: cardify QR tracker endpoint for this employee
    slug = template.get('company_slug', '')
    email = employee.get('email', '') or employee.get('email_en', '')
    base = template.get('base_url', 'https://cardify.om/')
    if not base.endswith('/'):
        base += '/'
    qr_data = f'{base}qr.php?c={slug}&e={email}' if slug and email else base

    # Generate the QR matrix
    qr = qrcode.QRCode(
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=10,
        border=0,
    )
    qr.add_data(qr_data)
    qr.make(fit=True)
    matrix = qr.get_matrix()
    n = len(matrix)

    # Style colors
    color = style.get('color', '#000000') or '#000000'
    bg_color = style.get('bg_color', '#ffffff') or '#ffffff'
    eye_color = style.get('eye_color') or color  # fallback to module color
    def hx(c):
        c = c.lstrip('#')
        return (int(c[0:2], 16), int(c[2:4], 16), int(c[4:6], 16))
    fg_rgb, bg_rgb, eye_rgb = hx(color), hx(bg_color), hx(eye_color)

    # Render at high resolution: each module = 20px
    module_px = 20
    img_size = n * module_px
    img = Image.new('RGB', (img_size, img_size), bg_rgb)
    draw = ImageDraw.Draw(img)

    # Finder-eye regions: 7x7 modules in top-left, top-right, bottom-left
    eye_regions = [
        (0, 0, 7, 7),                 # top-left
        (n - 7, 0, n, 7),             # top-right
        (0, n - 7, 7, n),             # bottom-left
    ]
    def in_eye(r, c):
        for r0, c0, r1, c1 in eye_regions:
            if r0 <= r < r1 and c0 <= c < c1:
                return True
        return False

    for r in range(n):
        for c in range(n):
            if not matrix[r][c]:
                continue
            x0, y0 = c * module_px, r * module_px
            x1, y1 = x0 + module_px, y0 + module_px
            fill = eye_rgb if in_eye(r, c) else fg_rgb
            draw.rectangle([x0, y0, x1, y1], fill=fill)

    # Optional rounded panel: paste QR onto a larger padded panel
    padding = int(style.get('panel_padding_px', 0)) * module_px // 4
    panel_radius_pct = float(style.get('panel_radius_pct', 0))
    if padding > 0 or panel_radius_pct > 0:
        panel_size = img_size + 2 * padding
        panel = Image.new('RGB', (panel_size, panel_size), bg_rgb)
        panel.paste(img, (padding, padding))
        if panel_radius_pct > 0:
            radius = int(panel_size * panel_radius_pct / 100)
            mask = Image.new('L', (panel_size, panel_size), 0)
            ImageDraw.Draw(mask).rounded_rectangle(
                [0, 0, panel_size, panel_size], radius=radius, fill=255
            )
            rounded = Image.new('RGBA', (panel_size, panel_size), (0, 0, 0, 0))
            rounded.paste(panel, (0, 0))
            rounded.putalpha(mask)
            img = rounded
        else:
            img = panel

    # Place onto PDF. Convert canvas-px coords to PDF pt.
    PX_TO_PT = 72.0 / 300.0
    qr_x_pt = float(qr_spec.get('x', 0)) * PX_TO_PT
    qr_y_pt = float(qr_spec.get('y', 0)) * PX_TO_PT
    qr_size_pt = float(qr_spec.get('size', 100)) * PX_TO_PT
    if for_print:
        from_origin_pt = 13  # BLEED_PT
        qr_x_pt += from_origin_pt
        qr_y_pt += from_origin_pt
    rect = fitz.Rect(qr_x_pt, qr_y_pt, qr_x_pt + qr_size_pt, qr_y_pt + qr_size_pt)

    # Insert as raster (alpha if rounded panel was applied). For the press
    # (CMYK) profile, convert to a DeviceCMYK pixmap so the QR eyes/modules
    # land on the tenant's exact brand values instead of an RGB approximation.
    if cmyk_cfg:
        page.insert_image(rect, pixmap=_pil_to_cmyk_pixmap(img, cmyk_cfg),
                          keep_proportion=False)
    else:
        buf = io.BytesIO()
        img.save(buf, format='PNG')
        page.insert_image(rect, stream=buf.getvalue(), keep_proportion=False)


def render(template_path: str, employee_path: str, out_path: str,
           vcard_path: str = '', profile: str = 'web', for_print: bool = False,
           watermark: str = '', cmyk_cfg_path: str = '', vector_bg: bool = False) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    # Press (CMYK) config: present only for the per-card print download.
    cmyk_cfg = _load_cmyk_cfg(cmyk_cfg_path) if for_print else None

    import_dir = template['import_dir']
    fonts_dir  = template.get('fonts_dir', os.path.join(import_dir, 'fonts'))
    company_fonts_dir = template.get('company_fonts_dir')

    # Load all font buffers once. Company-uploaded fonts override extracted
    # subsets which only contain the source PDF's character set.
    font_buffers = _load_font_buffers(fonts_dir, company_fonts_dir=company_fonts_dir)

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

    # Pre-pass: collect every glyph that will be drawn per font so we can
    # subset each TTF to only those characters before insert_font. PyMuPDF
    # set_simple=True does NOT subset the font, only the encoding, so the
    # full ~250-300KB face still ships in the PDF. Subsetting here drops
    # the web-profile output from ~700KB to ~300KB.
    text_by_font = {}
    if use_simple_encoding:
        for _ps in template['pages']:
            for _f in _ps.get('fields', []):
                # Mirror the draw loop: static decorations carry static_text
                # (no employee binding); constant fields fall back to
                # detected_text. Without this the subset omits static-only
                # glyphs (e.g. A/O/l/y of "An Omantel Company") -> blank.
                _st = _f.get('static_text')
                if _st:
                    _txt = _st
                else:
                    _txt = _resolve_employee_value(_f.get('field_key', ''), employee, template_default=_f.get('detected_text', ''))
                if not _txt:
                    continue
                _fam = _f.get('font_family', 'Lato')
                _wt  = int(_f.get('font_weight', 400))
                _name, _ = _pick_font(_fam, _wt, font_buffers, text=_txt)
                if _name:
                    text_by_font.setdefault(_name, set()).update(_txt)
    subsetted_buffers = {}

    vec_src_path = os.path.join(import_dir, 'source.pdf') if vector_bg else ''
    for page_idx, page_spec in enumerate(template['pages']):
        orig_w = page_spec['width_pt']
        orig_h = page_spec['height_pt']
        # Left-x of each LEFT-aligned English text field, so the matching Arabic
        # field can left-align to EXACTLY the same margin (name_ar -> name_en x).
        en_left_x = {}
        for _ef in page_spec.get('fields', []):
            _ek = _ef.get('field_key', '')
            _eal = (_ef.get('text_align') or _ef.get('textAlign') or 'left').lower()
            if _ek.endswith('_en') and _eal == 'left':
                en_left_x[_ek[:-3]] = float(_ef.get('x_pt', 0))

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

        # Layer 1: background.
        # Prefer the redacted bg PNG (post_persist rebake removes dynamic
        # text from it) over the SVG (which preserves the source PDF's
        # baked text-as-paths and would double-strike under the runtime
        # text overlay). PNG at 1200 DPI is print-quality and matches what
        # the Fabric.js browser preview shows, so PDF + site stay in sync.
        png_rel = page_spec.get('background_png_path') or (
            page_spec.get('background_svg_path', '').replace('.svg', '.png')
            if page_spec.get('background_svg_path') else None
        )
        png_path = os.path.join(import_dir, png_rel) if png_rel else None

        # Vector-bg mode: use the ORIGINAL vector source.pdf as the background
        # (true vector artwork + embedded static text), with the designer's
        # SAMPLE person redacted out, instead of the 1200-DPI raster. The
        # dynamic-text overlay below then draws the real employee with the full
        # fonts, so type matches the design exactly and the file stays vector.
        used_vector_bg = False
        if vec_src_path and os.path.isfile(vec_src_path):
            import re as _re
            m = _re.search(r'bg-page-(\d+)', str(png_rel or page_spec.get('background_svg_path') or ''))
            src_pno = (int(m.group(1)) - 1) if m else page_idx
            try:
                vdoc = fitz.open(vec_src_path)
                if 0 <= src_pno < vdoc.page_count:
                    vp = vdoc[src_pno]
                    # Redact only the DYNAMIC field regions (keep all vector
                    # graphics + static text). fill=False so the artwork behind
                    # the removed text shows through.
                    for field in page_spec.get('fields', []):
                        if field.get('render_in_bg') or field.get('static_text'):
                            continue
                        try:
                            fx = float(field['x_pt']); fy = float(field['y_pt'])
                            fw = float(field.get('w_pt') or 0) or 90.0
                            ffs = float(field.get('font_size_pt') or 10)
                        except Exception:
                            continue
                        vp.add_redact_annot(fitz.Rect(fx - 2, fy - 2,
                                                      fx + fw + 8, fy + ffs * 1.6 + 2),
                                            fill=False)
                    try:
                        vp.apply_redactions(images=fitz.PDF_REDACT_IMAGE_NONE,
                                            graphics=fitz.PDF_REDACT_LINE_ART_NONE)
                    except Exception:
                        vp.apply_redactions()
                    page.show_pdf_page(card_rect, vdoc, pno=src_pno, keep_proportion=False)
                    used_vector_bg = True
            except Exception as e:
                print(f'WARN: vector-bg failed, falling back to raster: {e}', file=sys.stderr)

        if used_vector_bg:
            pass
        elif png_path and os.path.isfile(png_path):
            if cmyk_cfg:
                # Press profile: bake the bg into DeviceCMYK with the tenant's
                # exact brand values (flat blue -> C100 M90 Y0 K2, etc.) and
                # edge-extend it into the 3mm bleed so a full-bleed design
                # actually bleeds. Placed over the FULL page; the padding keeps
                # the trim content aligned on card_rect.
                page.insert_image(page.rect,
                                  pixmap=_png_to_cmyk_pixmap(
                                      png_path, cmyk_cfg,
                                      bleed_pt=BLEED_PT, card_w_pt=card_rect.width),
                                  keep_proportion=False)
            else:
                page.insert_image(card_rect, filename=png_path, keep_proportion=False)
        else:
            # Fallback to SVG vector underlay if no PNG exists.
            svg_rel = page_spec.get('background_svg_path')
            if svg_rel:
                svg_path = os.path.join(import_dir, svg_rel)
                if os.path.isfile(svg_path):
                    with open(svg_path, 'rb') as fh:
                        svg_bytes = fh.read()
                    svg_doc = fitz.open(stream=svg_bytes, filetype='svg')
                    pdf_bytes = svg_doc.convert_to_pdf()
                    svg_doc.close()
                    pdf_doc = fitz.open(stream=pdf_bytes, filetype='pdf')
                    page.show_pdf_page(card_rect, pdf_doc, pno=0, keep_proportion=False)
                    pdf_doc.close()

        # Phase 12: draw crop marks outside the card boundary when --for-print.
        # CMYK press files use registration black (C M Y K all on) so the marks
        # appear on every plate.
        if for_print:
            _draw_crop_marks(page, card_rect, bleed_pt=BLEED_PT,
                             color=((1, 1, 1, 1) if cmyk_cfg else (0, 0, 0)))

        # Layer 1b: QR code if the page spec has one enabled.
        qr_spec = page_spec.get('qr_code')
        if qr_spec and qr_spec.get('enabled'):
            try:
                _draw_qr_code(page, qr_spec, employee, template, card_rect,
                              for_print, cmyk_cfg)
            except Exception as e:
                print(f'WARN: qr draw failed: {e}', file=sys.stderr)

        # Layer 2: dynamic text fields.
        for field in page_spec.get('fields', []):
            field_key = field.get('field_key', '')
            # Static-text fields (decorations) carry their literal text in
            # the field spec; typed dynamic fields resolve from the employee.
            static_text = field.get('static_text')
            # Vector-bg mode: ALL static text already lives in the vector
            # source.pdf (kept, not redacted), so re-drawing it here would
            # double-strike (e.g. "An Omantel Company"). Only the dynamic
            # fields (which were redacted out) get redrawn.
            if used_vector_bg and (field.get('render_in_bg') or static_text):
                continue
            if static_text:
                text = static_text
            else:
                # detected_text is the template sample from the source
                # PDF; used as fallback for tenant-constant fields only.
                text = _resolve_employee_value(
                    field_key, employee,
                    template_default=field.get('detected_text', '')
                )
            if not text:
                continue

            raw_text = text
            # Pre-shape Arabic into visual-order presentation forms before
            # passing to insert_text (which doesn't shape RTL on its own).
            text = _shape_arabic(text)

            family = field.get('font_family', 'Lato')
            weight = int(field.get('font_weight', 400))
            font_size = float(field.get('font_size_pt', 10))
            color = _hex_to_rgb(field.get('color', '#000000'))
            x_pt = float(field['x_pt'])
            y_pt = float(field['y_pt'])

            font_name, font_buf = _pick_font(family, weight, font_buffers, text=raw_text)
            if font_name is None:
                continue  # skip field if no matching font found

            # Arabic on any GSUB-shaping font -> route through MuPDF's HarfBuzz
            # htmlbox engine. reshape+insert_text both drops ligatures (lam-alef,
            # Allah) even when the glyphs exist AND draws tofu on GSUB-only fonts;
            # HarfBuzz shapes from base codepoints + GSUB correctly in both cases.
            # Reshaper path remains only for fonts without base+GSUB (rare).
            if _is_arabic(raw_text) and _font_can_harfbuzz_arabic(font_name, font_buf):
                _ar_left = (en_left_x.get(field_key[:-3])
                            if field_key.endswith('_ar') else None)
                _draw_arabic_htmlbox(
                    page, raw_text, font_name, font_buf, x_pt, y_pt,
                    float(field.get('w_pt', 0) or 0), font_size,
                    field.get('color', '#000000'),
                    (field.get('text_align') or field.get('textAlign')
                     or field.get('align') or 'left').lower(),
                    BLEED_PT if for_print else 0.0, _ar_left,
                )
                continue

            # Auto-shrink Latin text to fit the field width box. Templates carry
            # a per-field w_pt (derived from the source-PDF text bbox); long names
            # or titles otherwise overflow rightward into adjacent fields (e.g. a
            # long English name colliding with the right-aligned email/mobile).
            # Floor keeps it readable; names needing below the floor should be
            # shortened to first+family in the data.
            # Never shrink static decorations (importer sized their box to the
            # exact text; shrinking clips them, e.g. "An Omantel Company").
            _fit_w_pt = float(field.get("w_pt", 0) or 0)
            if _fit_w_pt > 0 and not static_text:
                try:
                    _fo = fitz.Font(fontbuffer=font_buf)
                    _g = 0
                    # Reduce 1% at a time until the measured width fits the field
                    # box (so a long name/title never overlaps the next element).
                    # 8% safety margin: the importer's detected box can run a
                    # touch wide and abut baked decorations (e.g. the social-icon
                    # row sits just past the title box on Otech). Leave a gap so
                    # text never collides with the next element.
                    _target = _fit_w_pt * 0.92
                    while (_g < 110 and font_size > 2.0
                           and _fo.text_length(text, fontsize=font_size) > _target):
                        font_size *= 0.99
                        _g += 1
                except Exception:
                    pass

            # Register font on this page.
            # web profile (default): set_simple=True uses WinAnsiEncoding so spaces
            # round-trip cleanly from get_text() (CID Identity-encoded fonts return NBSP),
            # plus we subset the TTF to the glyphs actually drawn (cached per font).
            # print profile: set_simple=False embeds the full font without subsetting,
            # which is required for PDF/X-4 compliance at the print shop.
            #
            # CRITICAL: WinAnsi only covers Latin-1. For Arabic / Hebrew /
            # Cyrillic / CJK text, set_simple=True silently drops every
            # non-WinAnsi glyph (no rendering at all). Detect non-Latin
            # codepoints in the actual text and force CID encoding.
            has_non_latin = any(ord(c) > 0xFF for c in text)
            field_uses_simple = use_simple_encoding and not has_non_latin
            if field_uses_simple and font_name in text_by_font:
                if font_name not in subsetted_buffers:
                    subsetted_buffers[font_name] = _subset_font_buffer(
                        font_buf, ''.join(text_by_font[font_name]))
                actual_buf = subsetted_buffers[font_name]
            else:
                actual_buf = font_buf
            page.insert_font(fontname=font_name, fontbuffer=actual_buf,
                             set_simple=field_uses_simple)

            # baseline_y = y_pt (top of em-square) + ascender * font_size.
            # Offset by bleed margin when for_print so text stays in the card area.
            ascender = font_ascenders.get(font_name, 0.97)
            text_x = x_pt + (BLEED_PT if for_print else 0)
            text_y = y_pt + (BLEED_PT if for_print else 0)
            baseline_y = text_y + ascender * font_size

            # Honour textAlign/originX from the field spec. PyMuPDF
            # insert_text always draws LTR from the point, so we shift
            # text_x leftward by the measured glyph-run width for right/
            # center alignment. Fabric handles this natively via originX.
            #
            # text_length returns advance width (full char-cell including
            # right side-bearing). Real visible right edge ends ~5-10px
            # short. For perfect alignment with adjacent left-anchored
            # text whose visible right edge is just the glyph bbox, we
            # subtract the last glyph's right side-bearing (advance -
            # glyph_bbox.x1).
            text_align = (field.get('text_align') or field.get('textAlign') or '').lower()
            if text_align in ('right', 'center'):
                try:
                    font_obj = fitz.Font(fontbuffer=actual_buf)
                    text_w_pt = font_obj.text_length(text, fontsize=font_size)
                    # Compute trailing side-bearing of the last visible glyph
                    side_bearing_pt = 0.0
                    try:
                        last_ch = text.rstrip()[-1] if text.rstrip() else ''
                        if last_ch:
                            gid = font_obj.has_glyph(ord(last_ch))
                            if gid:
                                bbox = font_obj.glyph_bbox(gid)
                                # bbox in font units, convert to pt at font_size
                                units = font_obj.glyph_advance(gid) or 1.0
                                advance_pt = font_obj.glyph_advance(gid) * font_size
                                visible_right_pt = bbox.x1 * font_size
                                side_bearing_pt = max(0.0, advance_pt - visible_right_pt)
                    except Exception:
                        side_bearing_pt = font_size * 0.05  # rough fallback
                    effective_w = text_w_pt - side_bearing_pt
                    # Anchor from the field's RIGHT edge (x_pt + w_pt) when the
                    # bbox width is known: x is the bbox LEFT edge (rule 47),
                    # matching the Fabric paths (left = x + width) and the
                    # Arabic htmlbox path (text_right = bx + w_pt). Fields
                    # without a stored width keep x_pt itself as the anchor.
                    _w_pt = float(field.get('w_pt') or 0)
                    if text_align == 'right':
                        anchor_x = text_x + _w_pt if _w_pt > 0 else text_x
                        text_x = anchor_x - effective_w
                    elif text_align == 'center':
                        anchor_x = text_x + _w_pt / 2 if _w_pt > 0 else text_x
                        text_x = anchor_x - effective_w / 2
                except Exception:
                    pass

            # Press profile: draw Latin text directly in CMYK so black lands
            # K-only (not rich black) and brand-coloured text stays exact.
            text_color = _rgb01_to_cmyk(color, cmyk_cfg) if cmyk_cfg else color
            page.insert_text(
                fitz.Point(text_x, baseline_y),
                text,
                fontname=font_name,
                fontsize=font_size,
                color=text_color,
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

    # Press finalisation: convert residual RGB -> CMYK and inject the
    # CutContour cut layer. Done before the vCard embed so the attachment
    # (added via PyMuPDF, which Ghostscript would otherwise strip) survives.
    if cmyk_cfg:
        _finalize_press(out_path, cmyk_cfg, BLEED_PT)

    # Phase 7: embed vCard as a PDF attachment (Acrobat / Apple Mail "Add to Contacts").
    # Re-open the saved file, add the attachment, save to a sibling temp path,
    # then atomically replace the original (PyMuPDF cannot overwrite in-place
    # without incremental=True, which inflates size; write-then-replace is cleaner).
    if vcard_path and os.path.isfile(vcard_path):
        vcf_doc = fitz.open(out_path)
        with open(vcard_path, 'rb') as fh:
            vcard_bytes = fh.read()
        # If the source/template PDF already carries a contact.vcf (re-render of
        # an already-embedded card), embfile_add raises "Name 'contact.vcf'
        # already exists" and the whole render fails. Update in place when it
        # exists, otherwise add fresh.
        try:
            existing = set(vcf_doc.embfile_names())
        except Exception:
            existing = set()
        if 'contact.vcf' in existing:
            vcf_doc.embfile_upd(
                'contact.vcf',
                vcard_bytes,
                filename='contact.vcf',
                ufilename='contact.vcf',
                desc='Add this contact to your address book',
            )
        else:
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
    ap.add_argument('--cmyk', default='',
                    help='Path to a CMYK press-config JSON (brand colour map + '
                         'corner_radius_mm). Enables DeviceCMYK output + CutContour '
                         'cut layer. Requires --for-print.')
    ap.add_argument('--vector-bg', action='store_true',
                    help='Use the original vector source.pdf as the background '
                         '(designer sample redacted) instead of the raster bg, '
                         'for an all-vector card.')
    args = ap.parse_args()
    sys.exit(render(args.template, args.employee, args.out, args.vcard,
                    profile=args.profile, for_print=args.for_print,
                    watermark=args.watermark, cmyk_cfg_path=args.cmyk,
                    vector_bg=args.vector_bg))


if __name__ == '__main__':
    main()
