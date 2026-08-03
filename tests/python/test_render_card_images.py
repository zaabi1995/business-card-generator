import hashlib
import json
import subprocess
from pathlib import Path

import cv2
from PIL import Image, ImageDraw


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "render-card-images.py"


def _fixture(tmp_path: Path, background_kind: str = "raster") -> dict:
    if background_kind == "raster":
        background = tmp_path / "background.png"
        image = Image.new("RGB", (1050, 600), "#f7fafc")
        ImageDraw.Draw(image).rectangle((0, 0, 28, 600), fill="#008aa6")
        image.save(background)
    else:
        background = tmp_path / "background.svg"
        background.write_text(
            '<svg xmlns="http://www.w3.org/2000/svg" width="1050" height="600">'
            '<rect width="1050" height="600" fill="#10243b"/>'
            '<circle cx="940" cy="90" r="180" fill="#008aa6"/></svg>',
            encoding="utf-8",
        )

    fields = {
        "name_en": {
            "enabled": True,
            "x": 72,
            "y": 82,
            "width": 520,
            "fontSize": 42,
            "fontWeight": "bold",
            "fill": "#10243b" if background_kind == "raster" else "#ffffff",
        },
        "name_ar": {
            "enabled": True,
            "x": 978,
            "y": 150,
            "width": 520,
            "fontSize": 36,
            "fontFamily": "Noto Sans Arabic",
            "fill": "#10243b" if background_kind == "raster" else "#ffffff",
            "originX": "right",
            "textAlign": "right",
        },
        "email": {
            "enabled": True,
            "x": 72,
            "y": 250,
            "width": 600,
            "fontSize": 24,
            "fill": "#334155" if background_kind == "raster" else "#dbeafe",
        },
    }
    return {
        "employee": {
            "id": "profile-1",
            "name_en": "Ali Darwish",
            "name_ar": "علي درويش",
            "position_en": "Product Lead",
            "email": "ali@example.test",
        },
        "company": {"id": "company-1", "name": "Cardify", "name_ar": "كارديفاي"},
        "theme": {"primary_color": "#008aa6"},
        "front_template": {
            "backgroundImage": str(background),
            "fields": fields,
            "settings": {"backgroundColor": "#ffffff", "fields_format": "px"},
        },
        "back_template": {
            "backgroundImage": str(background),
            "fields": {
                "company_en": {
                    "enabled": True,
                    "x": 525,
                    "y": 90,
                    "fontSize": 38,
                    "fontWeight": "bold",
                    "fill": "#10243b" if background_kind == "raster" else "#ffffff",
                    "originX": "center",
                    "textAlign": "center",
                },
                "qr_code": {"enabled": True, "x": 785, "y": 300, "size": 210},
            },
            "settings": {"backgroundColor": "#ffffff", "fields_format": "px"},
        },
        "public_url": "https://example.cardify.om/profile-1",
    }


def _run(tmp_path: Path, payload: dict) -> tuple[dict, Path]:
    tmp_path.mkdir(parents=True, exist_ok=True)
    input_path = tmp_path / "input.json"
    output_dir = tmp_path / "output"
    input_path.write_text(json.dumps(payload), encoding="utf-8")
    completed = subprocess.run(
        [
            "python3",
            str(SCRIPT),
            "--input",
            str(input_path),
            "--out-dir",
            str(output_dir),
        ],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    )
    return json.loads(completed.stdout), output_dir


def _pixel_hash(path: Path) -> str:
    with Image.open(path) as image:
        return hashlib.sha256(image.convert("RGB").tobytes()).hexdigest()


def _decode_qr(path: Path) -> str:
    image = cv2.imread(str(path))
    value, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
    return value


def test_raster_background_is_deterministic_and_contains_bilingual_text(tmp_path):
    payload = _fixture(tmp_path, "raster")
    first, _ = _run(tmp_path / "first", payload)
    second, _ = _run(tmp_path / "second", payload)

    assert first["success"] is True
    assert Image.open(first["front"]).size == (1050, 600)
    assert Image.open(first["back"]).size == (1050, 600)
    assert _decode_qr(Path(first["back"])) == payload["public_url"]
    assert _pixel_hash(Path(first["front"])) == _pixel_hash(Path(second["front"]))

    with Image.open(first["front"]).convert("RGB") as image:
        base = image.getpixel((700, 550))
        assert any(
            image.getpixel((x, y)) != base
            for x in range(72, 592, 8)
            for y in range(82, 210, 8)
        )


def test_vector_background_renders_to_webp(tmp_path):
    result, _ = _run(tmp_path, _fixture(tmp_path, "vector"))

    assert result["success"] is True
    assert Path(result["front"]).suffix == ".webp"
    assert Image.open(result["front"]).size == (1050, 600)
    assert _decode_qr(Path(result["back"])) == "https://example.cardify.om/profile-1"


def test_app_preset_renders_both_sides(tmp_path):
    payload = _fixture(tmp_path, "raster")
    payload["front_template"] = {"preset_id": "clean", "side": "front"}
    payload["back_template"] = {
        "preset_id": "clean",
        "side": "back",
        "fields": {"qr_code": {"enabled": True, "x": 785, "y": 300, "size": 210}},
    }

    result, _ = _run(tmp_path, payload)

    assert result["success"] is True
    assert Image.open(result["front"]).size == (1050, 600)
    assert Image.open(result["back"]).size == (1050, 600)
    assert _decode_qr(Path(result["back"])) == payload["public_url"]
