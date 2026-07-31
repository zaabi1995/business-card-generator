#!/usr/bin/env python3

import argparse
import io
import json
import os
import sys
from pathlib import Path
from typing import Any

import qrcode
from PIL import Image, ImageColor, ImageDraw, ImageFont


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
    canvas = Image.new("RGB", (WIDTH, HEIGHT), _color(settings.get("backgroundColor"), "#ffffff"))
    path = _resolve_path(
        _template_value(template, "backgroundImage", "background_image_path", ""),
        input_dir,
    )
    if path is None:
        return canvas
    try:
        if path.suffix.lower() == ".svg":
            import cairosvg

            rendered = cairosvg.svg2png(
                url=str(path),
                output_width=WIDTH,
                output_height=HEIGHT,
            )
            layer = Image.open(io.BytesIO(rendered)).convert("RGBA")
        else:
            layer = Image.open(path).convert("RGBA")
        layer = layer.resize((WIDTH, HEIGHT), Image.Resampling.LANCZOS)
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


def _font(field: dict, arabic: bool) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    size = max(8, min(180, int(round(float(field.get("fontSize", field.get("font_size", 24)))))))
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


def _employee_value(key: str, payload: dict, field: dict) -> str:
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
        return str(company.get("name_en") or company.get("name") or "")
    if resolved == "company_ar":
        return str(company.get("name_ar") or company.get("name") or "")
    value = employee.get(resolved)
    if value in (None, "") and resolved == "phone":
        value = employee.get("mobile")
    if value in (None, "") and resolved == "mobile":
        value = employee.get("phone")
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
        x = x * WIDTH / 100
        y = y * HEIGHT / 100
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
    anchor = "ra" if origin == "right" else "ma" if origin == "center" else "la"
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
    size = max(96, min(360, int(field.get("size", field.get("width", 180)) or 180)))
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
        if key in {"qr", "qr_code", "qrcode"}:
            _draw_qr(canvas, template, field, str(payload.get("public_url") or ""))
            qr_drawn = True
            continue
        _draw_text(draw, template, key, field, _employee_value(key, payload, field))
    if side == "back" and not qr_drawn:
        _draw_qr(canvas, template, {"x": 805, "y": 330, "size": 190}, str(payload.get("public_url") or ""))
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
