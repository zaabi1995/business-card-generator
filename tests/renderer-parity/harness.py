#!/usr/bin/env python3
"""
Cardify renderer-parity harness.

Feeds ONE canonical fields_json document through every card render engine that
can be executed headlessly, then measures the geometry each engine actually
produced and normalises it to fractions of the card so different canvases are
comparable.

Engines
-------
R1  scripts/render-card-images.py            EXECUTED. Real subprocess render to
    (Pillow PNG/WebP, canvas 1050x600)       WebP. Per-field geometry measured by
                                             ABLATION: render the fixture, then
                                             re-render with one field disabled,
                                             and diff the two rasters. The bbox of
                                             the changed pixels is that field's
                                             real ink box. Nothing is inferred
                                             from the input and the renderer is
                                             not instrumented.

R2  includes/CardPDFRenderer.php::pageSpec   EXECUTED. The PHP adapter runs for
    + scripts/render-card-pdf.py             real (probe_php.php pagespec, via
    (PyMuPDF vector PDF)                     Reflection), then the Python renderer
                                             produces a real PDF. Text geometry is
                                             read back with PyMuPDF text spans
                                             (page.get_text('dict')), matched to
                                             fields by the same ablation diff. The
                                             QR is measured by rasterising the page
                                             with pdftoppm and diffing.

R3  generate_card_html.php                   TRANSFORM ONLY, NOT PAINTED. Needs a
    (Fabric.js in a browser)                 browser plus the live PHP app, a DB
                                             and fonts.bhd.om. What IS executed is
                                             the whole coordinate pipeline: the
                                             real convertLegacyFieldPositions()
                                             (PHP) and the real
                                             getTemplatePixelDims() (JS, sliced out
                                             of generate_card_html.php and run in
                                             node). Fabric is handed field.x/field.y
                                             untouched (generate_card_html.php:653),
                                             so those two functions ARE R3's
                                             geometry; only glyph metrics are
                                             missing.

R4  admin/auto_generate.php + CardLayouts    NOT COMPARABLE. CardLayouts is only
    (html2canvas)                            reached when the company has NO
                                             template at all (auto_generate.php:186
                                             `$usePreDesigned = !$hasTemplates`). It
                                             never reads fields_json; it emits fixed
                                             CSS at a hard-coded 1050x600. When
                                             templates DO exist auto_generate.php
                                             uses Fabric, but through its OWN canvas
                                             sizer getCanvasDimensions()
                                             (auto_generate.php:951), which is not
                                             getTemplatePixelDims(). Both are
                                             reported as a static note.

R5  scripts/render-preset.py                 NOT COMPARABLE. Takes --brand + --preset
    (SVG -> rsvg-convert)                    and lays out hard-coded preset geometry.
                                             It has no fields_json input at all, so
                                             it cannot render a fixture. Its
                                             hard-coded 1050x600 (render-preset.py:19)
                                             is reported for the aspect-ratio check.

Usage:  python3 tests/renderer-parity/harness.py [fixture_id ...]
Output: tests/renderer-parity/out/report.json and out/report.md
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import numpy as np
from PIL import Image

HERE = Path(__file__).resolve().parent
ROOT = HERE.parents[1]
FIXTURES = HERE / "fixtures"
OUT = HERE / "out"

# Divergence bigger than this fraction of the card dimension is flagged.
# Deliberately loose: R1 reports a glyph INK box, R2 a font em box and R3 an
# anchor point. Those three quantities differ by up to ~1% of the card by
# construction, so anything under the tolerance is measurement noise, not a
# finding. Every divergence this harness reports is >= 4%.
TOLERANCE = 0.02

EMPLOYEE = {
    "id": "parity-fixture-employee",
    "name_en": "Muhammed Ali",
    "name_ar": "محمد علي",
    "position_en": "Product Manager",
    "position_ar": "مدير المنتجات",
    "mobile": "M +968 9771 2345",
    "phone": "T +968 2483 5500",
    "email": "E muhammed.ali@otech.om",
    "website": "www.otech.om",
    "address_en": "H8JG+52V, Muscat, Oman",
    "company_en": "Otech",
}
COMPANY = {"id": "parity-fixture-company", "name": "Otech", "name_en": "Otech",
           "name_ar": "أوتك", "slug": "otech"}
PUBLIC_URL = "https://cardify.om/otech/muhammed-ali"

# Field keys that carry a QR, not text.
QR_KEYS = {"qr_code", "qr", "qrcode"}


# ----------------------------------------------------------------------------
# helpers
# ----------------------------------------------------------------------------

def run(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)


def php_probe(mode: str, fixture_path: Path) -> dict:
    p = run(["php", str(HERE / "probe_php.php"), mode, str(fixture_path)])
    if p.returncode != 0:
        raise RuntimeError(f"php probe {mode} failed: {p.stderr[:600]}")
    return json.loads(p.stdout)


def node_probe(fixture_path: Path, converted_path: Path) -> dict:
    p = run(["node", str(HERE / "probe_fabric.mjs"), str(fixture_path),
             str(converted_path)])
    if p.returncode != 0:
        raise RuntimeError(f"node probe failed: {p.stderr[:600]}")
    return json.loads(p.stdout)


def ink_bbox(a: Image.Image, b: Image.Image):
    """Bounding box of pixels that differ between two renders, or None."""
    ia = np.asarray(a.convert("RGB"), dtype=np.int16)
    ib = np.asarray(b.convert("RGB"), dtype=np.int16)
    if ia.shape != ib.shape:
        raise RuntimeError(f"raster size mismatch {ia.shape} vs {ib.shape}")
    mask = np.abs(ia - ib).max(axis=2) > 8
    if not mask.any():
        return None
    ys, xs = np.where(mask)
    return (int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1)


def fixture_fonts(fx: dict, default: Path) -> Path:
    """A fixture may name its own fonts dir (relative to tests/renderer-parity).

    Production fixtures must: render-card-pdf.py:_pick_font returns (None, None)
    for an unknown family and the field loop then SKIPS that field entirely, so
    rendering a real template against the generic fonts dir silently drops every
    Latin field and the report reads as a drawn-mismatch that is not real.
    """
    rel = fx.get("fonts_dir")
    if rel:
        p = (HERE / rel).resolve()
        if p.is_dir():
            return p
    return default


def build_fonts_dir(dst: Path) -> Path:
    """Assemble a fonts_dir for render-card-pdf.py from fonts on this box.

    Synthesised, not shipped: the real fonts_dir is per-template
    (templates.fonts_dir, written by the PDF importer). Font choice moves
    measured glyph WIDTH, never the x/y anchor, so it does not affect any
    divergence this harness reports.
    """
    dst.mkdir(parents=True, exist_ok=True)
    wanted = {
        "Inter-Regular.ttf": [Path.home() / "Library/Fonts/Inter-Regular.ttf",
                              ROOT / "assets/fonts/Inter-Bold.ttf"],
        "Inter-Bold.ttf": [ROOT / "assets/fonts/Inter-Bold.ttf"],
        "NotoSansArabic-Regular.ttf": [Path.home() / "Library/Fonts/NotoSansArabic-Regular.ttf",
                                       Path("/System/Library/Fonts/Supplemental/NotoSansArabic-Regular.ttf")],
    }
    for name, sources in wanted.items():
        if (dst / name).exists():
            continue
        for s in sources:
            if s.is_file():
                shutil.copy2(s, dst / name)
                break
    return dst


# ----------------------------------------------------------------------------
# R1  scripts/render-card-images.py
# ----------------------------------------------------------------------------

def r1_payload(fx: dict, fields: dict, public_url: str) -> dict:
    side = fx.get("side", "back")
    tpl = {"fields": fields, "settings": fx["settings"], "backgroundImage": ""}
    payload = {
        "employee": EMPLOYEE, "company": COMPANY,
        "theme": {"primary_color": "#008aa6"},
        "public_url": public_url,
        "front_template": {} if side == "back" else tpl,
        "back_template": tpl if side == "back" else {},
    }
    return payload


def r1_render(fx: dict, fields: dict, workdir: Path, tag: str,
              public_url: str = PUBLIC_URL) -> Image.Image:
    side = fx.get("side", "back")
    inp = workdir / f"in-{tag}.json"
    inp.write_text(json.dumps(r1_payload(fx, fields, public_url), ensure_ascii=False),
                   encoding="utf-8")
    outdir = workdir / f"out-{tag}"
    p = run(["python3", str(ROOT / "scripts/render-card-images.py"),
             "--input", str(inp), "--out-dir", str(outdir)])
    if p.returncode != 0:
        raise RuntimeError(f"R1 render failed ({tag}): {p.stderr[:600]}")
    return Image.open(outdir / f"{side}.webp").convert("RGB")


def measure_r1(fx: dict, workdir: Path) -> dict:
    fields = json.loads(json.dumps(fx["fields"]))
    full = r1_render(fx, fields, workdir, "full")
    W, H = full.size
    out = {"engine": "R1", "canvas": [W, H], "executed": True,
           "method": "subprocess render, per-field ablation raster diff",
           "fields": {}}

    for key in fields:
        if key in QR_KEYS:
            # Blank the QR by clearing public_url: _draw_qr returns early
            # (render-card-images.py:257) and nothing else moves.
            base = r1_render(fx, fields, workdir, "noqr", public_url="")
            bbox = ink_bbox(full, base)
            out["fields"][key] = geom(bbox, W, H, drawn=bbox is not None,
                                      anchor_x=bbox[0] if bbox else None,
                                      note="ink box of the QR modules; the quiet "
                                           "zone is invisible on a white field")
            continue
        ablated = json.loads(json.dumps(fields))
        ablated[key]["enabled"] = False
        img = r1_render(fx, ablated, workdir, f"abl-{key}")
        bbox = ink_bbox(full, img)
        out["fields"][key] = geom(bbox, W, H, drawn=bbox is not None,
                                  align=effective_align(fx, key))
    return out


# ----------------------------------------------------------------------------
# R2  CardPDFRenderer::pageSpec -> render-card-pdf.py
# ----------------------------------------------------------------------------

def r2_render(spec: dict, workdir: Path, tag: str, fonts_dir: Path):
    import fitz
    import_dir = workdir / "import"
    import_dir.mkdir(exist_ok=True)
    tpl = {"pages": [spec], "fonts_dir": str(fonts_dir), "import_dir": str(import_dir)}
    tpl_path = workdir / f"tpl-{tag}.json"
    tpl_path.write_text(json.dumps(tpl, ensure_ascii=False), encoding="utf-8")
    emp_path = workdir / "emp.json"
    emp_path.write_text(json.dumps(EMPLOYEE, ensure_ascii=False), encoding="utf-8")
    out_pdf = workdir / f"out-{tag}.pdf"
    p = run(["python3", str(ROOT / "scripts/render-card-pdf.py"),
             "--template", str(tpl_path), "--employee", str(emp_path),
             "--out", str(out_pdf)])
    if p.returncode != 0 or not out_pdf.exists():
        raise RuntimeError(f"R2 render failed ({tag}): {p.stderr[:800]}")
    doc = fitz.open(str(out_pdf))
    page = doc[0]
    spans = []
    for blk in page.get_text("dict")["blocks"]:
        for line in blk.get("lines", []):
            for sp in line.get("spans", []):
                spans.append((sp["text"], tuple(round(v, 2) for v in sp["bbox"])))
    rect = (page.rect.width, page.rect.height)
    doc.close()
    return spans, rect, out_pdf


def r2_raster(pdf: Path, workdir: Path, tag: str, w: int) -> Image.Image:
    stem = workdir / f"ras-{tag}"
    p = run(["pdftoppm", "-png", "-f", "1", "-l", "1", "-scale-to-x", str(w),
             "-scale-to-y", "-1", str(pdf), str(stem)])
    if p.returncode != 0:
        raise RuntimeError(f"pdftoppm failed: {p.stderr[:400]}")
    cands = sorted(workdir.glob(f"ras-{tag}*.png"))
    return Image.open(cands[0]).convert("RGB")


def measure_r2(fx: dict, fixture_path: Path, workdir: Path, fonts_dir: Path) -> dict:
    spec = php_probe("pagespec", fixture_path)
    spans_full, (PW, PH), pdf_full = r2_render(spec, workdir, "full", fonts_dir)
    out = {"engine": "R2", "canvas": [PW, PH], "executed": True,
           "method": "real pageSpec + real PDF; PyMuPDF text spans, per-field ablation",
           "page_spec_fields": {f["field_key"]: {k: f[k] for k in
                                                 ("x_pt", "y_pt", "w_pt", "font_size_pt")}
                                for f in spec.get("fields", [])},
           "fields": {}}

    spec_keys = [f["field_key"] for f in spec.get("fields", [])]
    for key in fx["fields"]:
        if key in QR_KEYS:
            qs = spec.get("qr_code")
            if not qs or not qs.get("enabled"):
                out["fields"][key] = geom(None, PW, PH, drawn=False)
                continue
            noqr = json.loads(json.dumps(spec))
            noqr["qr_code"]["enabled"] = False
            _, _, pdf_noqr = r2_render(noqr, workdir, "noqr", fonts_dir)
            a = r2_raster(pdf_full, workdir, "full", 1050)
            b = r2_raster(pdf_noqr, workdir, "noqr", 1050)
            bbox = ink_bbox(a, b)
            out["fields"][key] = geom(bbox, a.size[0], a.size[1],
                                      drawn=bbox is not None,
                                      anchor_x=bbox[0] if bbox else None,
                                      note="measured on a pdftoppm raster of the PDF")
            continue
        if key not in spec_keys:
            out["fields"][key] = geom(None, PW, PH, drawn=False,
                                      note="dropped by CardPDFRenderer::pageSpec")
            continue
        ablated = json.loads(json.dumps(spec))
        ablated["fields"] = [f for f in ablated["fields"] if f["field_key"] != key]
        spans_abl, _, _ = r2_render(ablated, workdir, f"abl-{key}", fonts_dir)
        removed = [s for s in spans_full if s not in spans_abl]
        if not removed:
            out["fields"][key] = geom(None, PW, PH, drawn=False,
                                      note="in page spec but produced no text span")
            continue
        x0 = min(s[1][0] for s in removed); y0 = min(s[1][1] for s in removed)
        x1 = max(s[1][2] for s in removed); y1 = max(s[1][3] for s in removed)
        # Since RENDERER_VERSION 25 R2 uses the same rule as R1 and R3: a static
        # anchors from its left edge, a dynamic field from its declared align.
        g = geom((x0, y0, x1, y1), PW, PH, drawn=True, align=effective_align(fx, key))
        g["text"] = "".join(s[0] for s in removed)
        out["fields"][key] = g
    return out


# ----------------------------------------------------------------------------
# R3  transform level
# ----------------------------------------------------------------------------

def measure_r3(fx: dict, fixture_path: Path, workdir: Path) -> dict:
    php = php_probe("fabric", fixture_path)
    conv = workdir / "converted.json"
    conv.write_text(json.dumps(php["fields"], ensure_ascii=False), encoding="utf-8")
    nod = node_probe(fixture_path, conv)
    if php["fabric_canvas"] != nod["fabric_canvas"]:
        raise RuntimeError(f"canvas disagreement php={php['fabric_canvas']} node={nod['fabric_canvas']}")
    W, H = nod["fabric_canvas"]["w"], nod["fabric_canvas"]["h"]
    out = {"engine": "R3", "canvas": [W, H], "executed": False,
           "method": ("real convertLegacyFieldPositions (PHP) + real "
                      "getTemplatePixelDims + real CardEditor.addTextField anchor "
                      "math (JS in node); Fabric paint NOT run"),
           "convert_canvas": [php["convert_canvas"]["w"], php["convert_canvas"]["h"]],
           "converted_fields": {k: {kk: v.get(kk) for kk in ("x", "y", "width", "size")}
                                for k, v in php["fields"].items()},
           "fields": {}}
    for key, f in nod["fields"].items():
        drawn = bool(f.get("enabled"))  # generate_card_html.php:600 `if (!field.enabled) continue`
        if f.get("kind") == "qr":
            x, y, size = float(f["left"]), float(f["top"]), float(f["size"])
            out["fields"][key] = geom((x, y, x + size, y + size), W, H, drawn=drawn,
                                      anchor_x=x,
                                      note="x/y/size straight through, no paint")
            continue
        x, y = float(f["left"]), float(f["top"])
        out["fields"][key] = geom((x, y, x, y), W, H, drawn=drawn,
                                  align=f.get("originX", "left"), anchor_x=x,
                                  note=f"Fabric anchor (originX={f.get('originX')}), "
                                       f"no glyph metrics")
    return out


# ----------------------------------------------------------------------------
# normalisation + report
# ----------------------------------------------------------------------------

def geom(bbox, W, H, drawn: bool, note: str = "", align: str = "left",
         anchor_x=None) -> dict:
    """Normalise one measured box to fractions of the card.

    `anchor_frac` is the cross-engine comparable: the edge of the box the
    field's own alignment anchors to (left edge for left-aligned, centre for
    centred, right edge for right-aligned). Comparing raw left edges would
    make any glyph-width difference look like a placement divergence on
    right/centre-aligned fields; comparing the anchor does not.
    """
    if bbox is None or not drawn:
        return {"drawn": False, "note": note or ""}
    x0, y0, x1, y1 = bbox
    if anchor_x is None:
        anchor_x = x0 if align == "left" else ((x0 + x1) / 2 if align == "center" else x1)
    return {
        "drawn": True,
        "px": [round(float(v), 2) for v in bbox],
        "canvas": [W, H],
        "align": align,
        "x_frac": round(x0 / W, 4),
        "y_frac": round(y0 / H, 4),
        "w_frac": round((x1 - x0) / W, 4),
        "h_frac": round((y1 - y0) / H, 4),
        "anchor_frac": round(anchor_x / W, 4),
        "note": note,
    }


def field_align(fx: dict, key: str) -> str:
    """Alignment as generate_card_html.php:625 resolves it."""
    f = fx["fields"].get(key, {})
    a = f.get("textAlign") or ("right" if key.endswith("_ar") else "left")
    return a if a in ("left", "center", "right") else "left"


def effective_align(fx: dict, key: str) -> str:
    """The origin the field is ACTUALLY anchored from once width is considered.

    A static decoration, or any field with width<=0, is anchored from its LEFT
    edge whatever its textAlign says: card-editor.js:736 forces originX 'left'
    when width<=0, and generate_card_html.php:644 passes width 0 for statics.
    Comparing such a field's right edge against another engine's left anchor
    would invent a divergence, so the harness compares the same edge.
    """
    f = fx["fields"].get(key, {})
    width = f.get("width") or 0
    if f.get("is_static") or not isinstance(width, (int, float)) or width <= 0:
        return "left"
    return field_align(fx, key)


def compare(results: dict) -> list:
    """Per field, flag engines whose normalised anchor is off the median."""
    rows = []
    engines = [e for e in ("R1", "R2", "R3") if e in results]
    keys = []
    for e in engines:
        for k in results[e]["fields"]:
            if k not in keys:
                keys.append(k)
    for k in keys:
        cell = {}
        for e in engines:
            cell[e] = results[e]["fields"].get(k, {"drawn": False, "note": "absent"})
        drawn = [e for e in engines if cell[e].get("drawn")]
        flags = []
        if 0 < len(drawn) < len(engines):
            missing = [e for e in engines if e not in drawn]
            flags.append(f"DRAWN-MISMATCH: drawn by {'+'.join(drawn)}, skipped by {'+'.join(missing)}")
        for axis in ("anchor_frac", "y_frac"):
            vals = {e: cell[e][axis] for e in drawn}
            if len(vals) >= 2:
                spread = max(vals.values()) - min(vals.values())
                if spread > TOLERANCE:
                    detail = ", ".join(f"{e}={vals[e]:.4f}" for e in sorted(vals))
                    flags.append(f"{axis.upper()} SPREAD {spread*100:.1f}% of card ({detail})")
        rows.append({"field": k, "cells": cell, "flags": flags})
    return rows


def render_md(fx: dict, results: dict, rows: list) -> str:
    L = []
    L.append(f"### fixture `{fx['id']}` ({fx.get('side','back')})")
    L.append("")
    L.append(fx["description"])
    L.append("")
    for e in ("R1", "R2", "R3"):
        if e in results:
            r = results[e]
            state = "EXECUTED" if r["executed"] else "TRANSFORM ONLY"
            ar = (f" (AR {r['canvas'][0]/r['canvas'][1]:.4f})"
                  if r["canvas"][0] and r["canvas"][1] else "")
            L.append(f"- **{e}** {state} - canvas {r['canvas'][0]}x{r['canvas'][1]}"
                     f"{ar} - {r['method']}")
    L.append("")
    def cv(e):
        c = results.get(e, {}).get("canvas", [0, 0])
        return f"{c[0]:g}x{c[1]:g}" if c[0] else "-"
    hdr = (f"| field | align | R1 raster {cv('R1')}px | R2 PDF {cv('R2')}pt "
           f"| R3 Fabric {cv('R3')}px | anchor_frac R1/R2/R3 | y_frac R1/R2/R3 | flags |")
    L.append(hdr)
    L.append("|---|---|---|---|---|---|---|---|")
    for row in rows:
        c = row["cells"]

        def cellstr(e):
            g = c.get(e, {})
            if not g.get("drawn"):
                n = g.get("note") or ""
                return "_not drawn_" + (f" ({n})" if n else "")
            p = g["px"]
            return f"x={p[0]:.1f} y={p[1]:.1f} w={p[2]-p[0]:.1f}"

        def frac(axis):
            out = []
            for e in ("R1", "R2", "R3"):
                g = c.get(e, {})
                out.append(f"{g[axis]:.4f}" if g.get("drawn") else "-")
            return " / ".join(out)

        al = next((c[e].get("align") for e in ("R2", "R1", "R3")
                   if c.get(e, {}).get("align")), "-")
        L.append(f"| `{row['field']}` | {al} | {cellstr('R1')} | {cellstr('R2')} | {cellstr('R3')} "
                 f"| {frac('anchor_frac')} | {frac('y_frac')} | {'; '.join(row['flags']) or 'ok'} |")
    L.append("")
    if "R2" in results and results["R2"].get("page_spec_fields"):
        L.append("CardPDFRenderer::pageSpec output (the numbers R2 was handed):")
        L.append("")
        L.append("| field | x_pt | y_pt | w_pt | font_size_pt |")
        L.append("|---|---|---|---|---|")
        for k, v in results["R2"]["page_spec_fields"].items():
            L.append(f"| `{k}` | {v['x_pt']:.3f} | {v['y_pt']:.3f} | {v['w_pt']:.3f} | {v['font_size_pt']:.3f} |")
        L.append("")
    return "\n".join(L)


NON_COMPARABLE = """
### Engines not in the table

- **R4 `admin/auto_generate.php` + `includes/CardLayouts.php` (html2canvas)** - not comparable.
  `CardLayouts` is only reached when the company has NO template
  (`$usePreDesigned = !$hasTemplates`, admin/auto_generate.php:186). It never reads
  `fields_json`; it emits fixed CSS at a hard-coded 1050x600
  (admin/auto_generate.php:586-587) with a fixed 120px QR (includes/CardLayouts.php:77).
  When templates DO exist auto_generate.php runs Fabric, but sizes the canvas with its
  own `getCanvasDimensions()` (admin/auto_generate.php:951), not
  `getTemplatePixelDims()`.
- **R5 `scripts/render-preset.py`** - not comparable. Its inputs are `--brand` + `--preset`;
  it has no `fields_json` input at all and lays out hard-coded preset geometry on a
  hard-coded 1050x600 (scripts/render-preset.py:19). It bakes a background, it does not
  place template fields.
"""


def main(argv):
    OUT.mkdir(parents=True, exist_ok=True)
    fonts_dir = build_fonts_dir(OUT / "fonts")
    wanted = argv[1:]
    paths = sorted(FIXTURES.glob("*.json"))
    if wanted:
        paths = [p for p in paths if p.stem in wanted]

    md = ["# Cardify renderer-parity report", "",
          f"Tolerance: a divergence is flagged when the normalised anchor spread exceeds "
          f"{TOLERANCE*100:.0f}% of the card dimension.", "",
          "R1 reports a glyph INK box, R2 a font EM box, R3 an anchor point. Those differ "
          "by construction, which is why the tolerance is loose; every flagged divergence "
          "below is far larger.", ""]
    blob = {}

    for path in paths:
        fx = json.loads(path.read_text(encoding="utf-8"))
        with tempfile.TemporaryDirectory(prefix=f"parity-{fx['id']}-") as td:
            wd = Path(td)
            results = {}
            for name, fn in (("R1", lambda: measure_r1(fx, wd)),
                             ("R2", lambda: measure_r2(fx, path, wd, fixture_fonts(fx, fonts_dir))),
                             ("R3", lambda: measure_r3(fx, path, wd))):
                try:
                    results[name] = fn()
                except Exception as exc:  # keep going; report the failure honestly
                    results[name] = {"engine": name, "canvas": [0, 0], "executed": False,
                                     "method": f"FAILED: {exc}", "fields": {}}
            rows = compare(results)
            blob[fx["id"]] = {"fixture": fx, "results": results, "rows": rows}
            md.append(render_md(fx, results, rows))
            print(f"[ok] {fx['id']}: "
                  f"{sum(1 for r in rows if r['flags'])}/{len(rows)} fields flagged",
                  file=sys.stderr)

    md.append(NON_COMPARABLE)
    (OUT / "report.md").write_text("\n".join(md), encoding="utf-8")
    (OUT / "report.json").write_text(json.dumps(blob, ensure_ascii=False, indent=1),
                                     encoding="utf-8")
    print(f"wrote {OUT/'report.md'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
