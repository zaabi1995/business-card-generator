#!/usr/bin/env python3

import argparse
import io
import json
import os
import shutil
import subprocess
import sys
from pathlib import Path
from typing import Any

import qrcode
from PIL import Image, ImageColor, ImageDraw, ImageFont


# Legacy fallback canvas. Used only when the template carries no physical size
# (customWidth/customHeight), i.e. the stock/preset templates that were authored
# against this canvas. A PDF-imported template DOES carry its real size, and
# rendering it here squashed it: an Otech-family 92.62x59.93mm card is 1094x708
# at 300 DPI (AR 1.545), not 1050x600 (AR 1.750), so every field drifted
# downward by up to 11.4% of the card height relative to the same template's
# vector PDF and its Fabric preview. See _canvas_dims.
WIDTH = 1050
HEIGHT = 600
ROOT = Path(__file__).resolve().parents[1]


def _color(value: Any, fallback: str = "#000000") -> str:
    try:
        ImageColor.getrgb(str(value))
        return str(value)
    except (TypeError, ValueError):
        return fallback


def _template_value(template: dict, camel: str, snake: str, fallback: Any) -> Any:
    value = template.get(camel, template.get(snake, fallback))
    if isinstance(value, str) and camel in {"fields", "settings"}:
        try:
            return json.loads(value)
        except json.JSONDecodeError:
            return fallback
    return value


def _canvas_dims(template: dict) -> tuple[int, int]:
    """Pixel canvas for this template, matching getTemplatePixelDims().

    Port of generate_card_html.php:302-319, so the WebP this script writes has
    the same proportions as the Fabric card the customer approves and the
    vector PDF the print shop receives. Falls back to the legacy 1050x600 when
    the template has no stored physical size, which keeps every stock/preset
    template rendering byte-identically to before.
    """
    settings = _template_value(template, "settings", "settings_json", {}) or {}
    try:
        cw = float(settings.get("customWidth") or 0)
        ch = float(settings.get("customHeight") or 0)
        dpi = float(settings.get("dpi") or 300) or 300.0
    except (TypeError, ValueError):
        return WIDTH, HEIGHT
    if not cw or not ch:
        return WIDTH, HEIGHT
    unit = str(settings.get("customUnit") or "mm").lower()
    to_in = 1 / 25.4 if unit == "mm" else 1 / 72 if unit == "pt" else 1 if unit == "in" else 1 / 25.4
    w = int(round(cw * to_in * dpi))
    h = int(round(ch * to_in * dpi))
    if w <= 0 or h <= 0:
        return WIDTH, HEIGHT
    return w, h


def _resolve_path(value: Any, input_dir: Path) -> Path | None:
    if not value:
        return None
    raw = str(value).split("?", 1)[0]
    if raw.startswith(("http://", "https://", "data:")):
        return None
    candidate = Path(raw)
    if candidate.is_absolute() and candidate.is_file():
        return candidate
    for base in (input_dir, ROOT):
        joined = base / raw.lstrip("/")
        if joined.is_file():
            return joined
    return None


def _background(template: dict, input_dir: Path) -> Image.Image:
    settings = _template_value(template, "settings", "settings_json", {}) or {}
    width, height = _canvas_dims(template)
    canvas = Image.new("RGB", (width, height), _color(settings.get("backgroundColor"), "#ffffff"))
    path = _resolve_path(
        _template_value(template, "backgroundImage", "background_image_path", ""),
        input_dir,
    )
    if path is None:
        return canvas
    try:
        if path.suffix.lower() == ".svg":
            try:
                import cairosvg

                rendered = cairosvg.svg2png(
                    url=str(path),
                    output_width=width,
                    output_height=height,
                )
            except ImportError:
                rsvg = shutil.which("rsvg-convert")
                if rsvg is None:
                    raise RuntimeError("svg_renderer_unavailable")
                rendered = subprocess.run(
                    [rsvg, "--width", str(width), "--height", str(height), str(path)],
                    check=True,
                    capture_output=True,
                ).stdout
            layer = Image.open(io.BytesIO(rendered)).convert("RGBA")
        else:
            layer = Image.open(path).convert("RGBA")
        layer = layer.resize((width, height), Image.Resampling.LANCZOS)
        canvas.paste(layer, (0, 0), layer)
    except Exception as exc:
        raise RuntimeError(f"background_render_failed:{path.name}") from exc
    return canvas


def _font_candidates(field: dict, bold: bool, arabic: bool) -> list[Path]:
    candidates: list[Path] = []
    explicit = field.get("fontPath") or field.get("font_path")
    if explicit:
        candidates.append(Path(str(explicit)))
    family = str(field.get("fontFamily", field.get("font_family", ""))).lower()
    font_dirs = [
        ROOT / "assets" / "fonts",
        Path("/usr/share/fonts/truetype/noto"),
        Path("/usr/share/fonts/truetype/dejavu"),
        Path("/System/Library/Fonts"),
        Path("/System/Library/Fonts/Supplemental"),
    ]
    names = []
    if arabic or "arab" in family or "cairo" in family:
        names += [
            "NotoSansArabic-Bold.ttf" if bold else "NotoSansArabic-Regular.ttf",
            "NotoNaskhArabic-Bold.ttf" if bold else "NotoNaskhArabic-Regular.ttf",
            "Arial Unicode.ttf",
            "Tahoma.ttf",
            "Tahoma-Regular.ttf",
            "DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf",
        ]
    else:
        names += [
            "Inter-Bold.ttf" if bold else "Inter-Regular.ttf",
            "InterDisplay-Bold.ttf" if bold else "Inter-Regular.ttf",
            "Arial Bold.ttf" if bold else "Arial.ttf",
            "DejaVuSans-Bold.ttf" if bold else "DejaVuSans.ttf",
            "MyriadPro-Bold.otf" if bold else "MyriadPro-Regular.otf",
        ]
    for directory in font_dirs:
        for name in names:
            candidates.append(directory / name)
            candidates.append(directory / "myriad-pro" / name)
            candidates.append(directory / "tahoma" / name)
    return candidates


def _font(
    field: dict,
    arabic: bool,
    size_override: int | None = None,
) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    requested = size_override if size_override is not None else field.get(
        "fontSize", field.get("font_size", 24)
    )
    size = max(8, min(180, int(round(float(requested)))))
    weight = str(field.get("fontWeight", field.get("font_weight", ""))).lower()
    bold = weight in {"bold", "600", "700", "800", "900"} or (weight.isdigit() and int(weight) >= 600)
    for candidate in _font_candidates(field, bold, arabic):
        try:
            if candidate.is_file():
                return ImageFont.truetype(str(candidate), size=size)
        except OSError:
            continue
    return ImageFont.load_default(size=size)


def _field_items(template: dict) -> list[tuple[str, dict]]:
    fields = _template_value(template, "fields", "fields_json", {}) or {}
    if isinstance(fields, dict):
        return [(str(key), value) for key, value in fields.items() if isinstance(value, dict)]
    result = []
    if isinstance(fields, list):
        for index, value in enumerate(fields):
            if not isinstance(value, dict):
                continue
            key = value.get("field_key") or value.get("fieldKey") or value.get("key") or value.get("name")
            if key:
                result.append((str(key), value))
            elif value.get("type") == "qr":
                result.append(("qr_code", value))
            else:
                result.append((f"field_{index}", value))
    return result


# Fields whose value is the same for every person in the tenant. Kept in step
# with scripts/render-card-pdf.py, which draws the same distinction.
_TENANT_CONSTANT_BASES = ("website", "company", "address", "fax", "social")


def _is_tenant_constant(field_key: str) -> bool:
    return (field_key or "").lower().startswith(_TENANT_CONSTANT_BASES)


def _employee_value(key: str, payload: dict, field: dict) -> str:
    if field.get("is_static"):
        return str(field.get("detected_text") or field.get("text") or "")
    if field.get("text") not in (None, ""):
        return str(field["text"])
    employee = payload.get("employee") or {}
    company = payload.get("company") or {}
    aliases = {
        "name": "name_en",
        "position": "position_en",
        "job_title": "position_en",
        "company": "company_en",
        "address": "address_en",
    }
    resolved = aliases.get(key, key)
    if resolved == "company_en":
        return str(employee.get("company_en") or company.get("name_en") or company.get("name") or "")
    if resolved == "company_ar":
        return str(employee.get("company_ar") or company.get("name_ar") or company.get("name") or "")
    value = employee.get(resolved)
    if value in (None, "") and resolved == "phone":
        value = employee.get("mobile")
    if value in (None, "") and resolved == "mobile":
        value = employee.get("phone")
    company_fallbacks = {
        "website": "default_website",
        "website_ar": "default_website",
        "fax": "default_fax",
        "fax_ar": "default_fax",
        "address": "default_address_en",
        "address_en": "default_address_en",
        "address_2_en": "default_address_2_en",
        "address_ar": "default_address_ar",
        "address_2_ar": "default_address_2_ar",
    }
    if value in (None, "") and resolved in company_fallbacks:
        value = company.get(company_fallbacks[resolved])
    # detected_text is the sample lifted out of the source PDF at import: the
    # words that were on the card the design came from. Falling back to it for a
    # PER-PERSON field prints the person whose card was imported.
    #
    # It was live: Ahmed Al-Siyabi's card on aedoman.cardify.om carried
    # "علي محمد المجيني" as its Arabic name, because that employee has no
    # name_ar and the template's detected_text still held the original owner's.
    # scripts/render-card-pdf.py was fixed for exactly this in the vector path
    # ("Al Maha's single-line job titles were inheriting the three-line
    # designation of the employee the design came from") and the PNG path,
    # which is the one the digital card, the wallet strip, the og:image and the
    # print preview all read, was left as it was.
    #
    # Website, company, address, fax and social are the same for everyone in
    # the tenant, so the design's own text is the right default there.
    if value in (None, ""):
        if _is_tenant_constant(resolved) or _is_tenant_constant(key):
            value = field.get("detected_text")
        else:
            value = ""
    return "" if value is None else str(value)


def _coords(field: dict, template: dict) -> tuple[float, float]:
    x = float(field.get("x", field.get("left", 0)) or 0)
    y = float(field.get("y", field.get("top", 0)) or 0)
    settings = _template_value(template, "settings", "settings_json", {}) or {}
    field_format = (
        template.get("fields_format")
        or settings.get("fields_format")
        or settings.get("fieldsFormat")
    )
    if field_format != "px" and 0 <= x <= 100 and 0 <= y <= 100:
        width, height = _canvas_dims(template)
        x = x * width / 100
        y = y * height / 100
    return x, y


def _draw_text(draw: ImageDraw.ImageDraw, template: dict, key: str, field: dict, text: str) -> None:
    if not text:
        return
    arabic = key.endswith("_ar") or any("\u0600" <= char <= "\u06ff" for char in text)
    font = _font(field, arabic)
    x, y = _coords(field, template)
    fill = _color(field.get("fill", field.get("color")), "#111827")
    align = str(field.get("textAlign", field.get("text_align", "right" if arabic else "left"))).lower()
    origin = str(field.get("originX", field.get("origin_x", align))).lower()
    width = float(field.get("width", 0) or 0)
    if field.get("is_static"):
        # Static decorations carry a bbox sized tightly to the original glyph
        # run, so both Fabric (generate_card_html.php:644) and this script
        # bypass the width constraint for them.
        width = 0.0
    if width > 0:
        requested_size = max(8, int(round(float(field.get("fontSize", field.get("font_size", 24))))))
        while requested_size > 8 and draw.textlength(text, font=font) > width:
            requested_size -= 1
            font = _font(field, arabic, requested_size)

    # Derive the anchor from (x, width, originX). `x` is the bbox LEFT edge
    # (CardifyTemplateImporter.php:100-105), so a right-aligned field sits its
    # visible right edge at x + width and a centred one at x + width/2. This is
    # the same math CardEditor.addTextField does (assets/js/card-editor.js:730-737)
    # and the same edge render-card-pdf.py anchors to (x_pt + w_pt,
    # render-card-pdf.py:818-821). Anchoring about `x` instead, as this script
    # did, put a right-aligned field a full field-width to the left: on a 500px
    # wide field that is 45% of the card, and the measured case ran clean off
    # the left edge to x=0. width<=0 keeps the old left anchor at x.
    if width > 0 and origin == "right":
        anchor_x, anchor = x + width, "rt"
    elif width > 0 and origin == "center":
        anchor_x, anchor = x + width / 2.0, "mt"
    else:
        anchor_x, anchor = x, "lt"
    x = anchor_x
    kwargs = {"fill": fill, "font": font, "anchor": anchor, "align": align}
    if arabic:
        kwargs["direction"] = "rtl"
        kwargs["language"] = "ar"
    try:
        draw.text((x, y), text, **kwargs)
    except (TypeError, ValueError):
        kwargs.pop("direction", None)
        kwargs.pop("language", None)
        draw.text((x, y), text, **kwargs)


def _draw_qr(canvas: Image.Image, template: dict, field: dict, public_url: str) -> None:
    if not public_url:
        return
    x, y = _coords(field, template)
    # Upper bound scales with the canvas instead of being pinned to 360, which
    # was the 60% cap for the legacy 1050x600 canvas. On 1050x600 this is still
    # exactly 360 (nothing moves); on a real 1094x708 imported card it stops
    # shrinking a template's own QR, which R2 and R3 both draw at full size.
    canvas_w, canvas_h = _canvas_dims(template)
    ceiling = max(360, int(min(canvas_w, canvas_h) * 0.6))
    size = max(96, min(ceiling, int(field.get("size", field.get("width", 180)) or 180)))
    qr = qrcode.QRCode(version=None, error_correction=qrcode.constants.ERROR_CORRECT_M, box_size=8, border=4)
    qr.add_data(public_url)
    qr.make(fit=True)
    image = qr.make_image(fill_color="black", back_color="white").convert("RGB")
    image = image.resize((size, size), Image.Resampling.NEAREST)
    canvas.paste(image, (int(round(x)), int(round(y))))


def _preset(payload: dict, template: dict, side: str) -> Image.Image:
    theme = payload.get("theme") or {}
    primary = _color(theme.get("primary_color"), "#008aa6")
    canvas = Image.new("RGB", (WIDTH, HEIGHT), "#ffffff" if side == "front" else primary)
    draw = ImageDraw.Draw(canvas)
    draw.rounded_rectangle((42, 42, 1008, 558), radius=36, outline=primary, width=7)
    employee = payload.get("employee") or {}
    company = payload.get("company") or {}
    if side == "front":
        _draw_text(draw, template, "name_en", {
            "x": 72, "y": 90, "fontSize": 48, "fontWeight": "bold", "fill": "#10243b"
        }, str(employee.get("name_en") or employee.get("name") or ""))
        _draw_text(draw, template, "position_en", {
            "x": 72, "y": 160, "fontSize": 28, "fill": primary
        }, str(employee.get("position_en") or employee.get("position") or ""))
        _draw_text(draw, template, "email", {
            "x": 72, "y": 430, "fontSize": 24, "fill": "#334155"
        }, str(employee.get("email") or ""))
    else:
        _draw_text(draw, template, "company_en", {
            "x": 525, "y": 120, "fontSize": 46, "fontWeight": "bold",
            "fill": "#ffffff", "originX": "center", "textAlign": "center"
        }, str(company.get("name_en") or company.get("name") or ""))
    return canvas


def _render(payload: dict, template: dict, side: str, input_dir: Path) -> Image.Image:
    canvas = _preset(payload, template, side) if template.get("preset_id") else _background(template, input_dir)
    draw = ImageDraw.Draw(canvas)
    qr_drawn = False
    for key, field in _field_items(template):
        if field.get("enabled", True) is False or field.get("visible", True) is False:
            continue
        if field.get("render_in_bg"):
            continue
        if key in {"qr", "qr_code", "qrcode"}:
            _draw_qr(canvas, template, field, str(payload.get("public_url") or ""))
            qr_drawn = True
            continue
        _draw_text(draw, template, key, field, _employee_value(key, payload, field))
    if side == "back" and not qr_drawn:
        # Fallback QR for a back with no qr_code field. The literals were
        # 805/330/190, i.e. fractions of the legacy 1050x600 canvas; expressed
        # as fractions they land identically there and stay in the same corner
        # on a real imported card size instead of drifting inward.
        cw, ch = _canvas_dims(template)
        _draw_qr(canvas, template, {
            "x": round(cw * 805 / 1050),
            "y": round(ch * 330 / 600),
            "size": round(cw * 190 / 1050),
        }, str(payload.get("public_url") or ""))
    return canvas


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--out-dir", required=True)
    args = parser.parse_args()
    input_path = Path(args.input).resolve()
    output_dir = Path(args.out_dir).resolve()
    payload = json.loads(input_path.read_text(encoding="utf-8"))
    output_dir.mkdir(parents=True, exist_ok=True)
    result = {"success": True}
    for side in ("front", "back"):
        template = payload.get(f"{side}_template") or {}
        canvas = _render(payload, template, side, input_path.parent)
        path = output_dir / f"{side}.webp"
        canvas.save(path, "WEBP", lossless=True, method=6, exact=True)
        result[side] = str(path)
    print(json.dumps(result, ensure_ascii=False, separators=(",", ":")))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(json.dumps({"success": False, "error": str(exc)}, separators=(",", ":")), file=sys.stderr)
        raise SystemExit(1)
