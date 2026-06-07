#!/usr/bin/env python3
"""Replace PyMuPDF-extracted font SUBSETS with the foundry originals from
fonts.bhd.om when available.

Why: parse_card_pdf.py / extract_template_fonts.py dump the glyph subset that
was embedded in the source PDF. Those subsets lack Arabic glyphs + GSUB shaping
features, so any runtime-rendered text (especially Arabic names/titles) falls
back to the wrong font (Fabric) or renders as tofu/dots (PyMuPDF). fonts.bhd.om
is the BHD-Group webfont CDN (Google-Fonts URL grammar); when it hosts the
family, its TTF is the correct foundry source.

Best-effort: any network/parse failure leaves the subset untouched, so import
never breaks. Idempotent: only replaces when the CDN font is genuinely richer.

Usage: fetch-foundry-fonts.py <fonts_dir>
"""
import sys, os, re, ssl, urllib.request

CDN = "https://fonts.bhd.om"
_FONT_MAGIC = (b"\x00\x01\x00\x00", b"true", b"ttcf", b"OTTO")


def glyph_stats(path):
    """(total cmap entries, arabic-block entries) or (-1,-1) on failure."""
    try:
        from fontTools.ttLib import TTFont
        cmap = TTFont(path, fontNumber=0).getBestCmap()
        arabic = sum(1 for cp in cmap if 0x0600 <= cp <= 0x06FF)
        return len(cmap), arabic
    except Exception:
        return -1, -1


def main(fonts_dir):
    if not os.path.isdir(fonts_dir):
        print(f"fetch-foundry-fonts: no dir {fonts_dir}", file=sys.stderr)
        return
    ctx = ssl.create_default_context()
    for fn in sorted(os.listdir(fonts_dir)):
        if not fn.lower().endswith(".ttf"):
            continue
        m = re.match(r"^(.+?)-([A-Za-z]+)\.ttf$", fn)
        if not m:
            continue
        family, weight = m.group(1), m.group(2)
        dst = os.path.join(fonts_dir, fn)
        cur_total, cur_ar = glyph_stats(dst)
        url = f"{CDN}/{family}/{family}-{weight}.ttf"
        try:
            req = urllib.request.Request(url, headers={"User-Agent": "cardify-import"})
            data = urllib.request.urlopen(req, timeout=15, context=ctx).read()
        except Exception as e:
            print(f"fetch-foundry-fonts: skip {fn} (cdn miss: {e})", file=sys.stderr)
            continue
        if len(data) < 2000 or data[:4] not in _FONT_MAGIC:
            print(f"fetch-foundry-fonts: skip {fn} (cdn not a font)", file=sys.stderr)
            continue
        tmp = dst + ".cdn"
        with open(tmp, "wb") as fh:
            fh.write(data)
        new_total, new_ar = glyph_stats(tmp)
        if new_total > cur_total or new_ar > cur_ar:
            os.replace(tmp, dst)
            print(f"fetch-foundry-fonts: replaced {fn} glyphs {cur_total}->{new_total} arabic {cur_ar}->{new_ar}")
        else:
            os.remove(tmp)
            print(f"fetch-foundry-fonts: kept {fn} (subset >= cdn {cur_total}/{cur_ar})")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("usage: fetch-foundry-fonts.py <fonts_dir>", file=sys.stderr)
        sys.exit(1)
    main(sys.argv[1])
