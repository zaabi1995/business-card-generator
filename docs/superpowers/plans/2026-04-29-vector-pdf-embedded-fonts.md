# Vector PDF + Embedded Fonts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate true-vector PDFs with embedded fonts for the customer download (`/card-pdf.php`) and the print-shop imposition sheet (`api/print-ready.php`), instead of the current PNG-wrapped-in-PDF.

**Architecture:** A new Python helper (`scripts/render-card-pdf.py`) uses PyMuPDF to take the SVG bg the importer already emits + the per-employee data + the Lato/Sora fonts already embedded in `source.pdf`, and produces a 2-page vector PDF. A PHP wrapper (`includes/CardPDFRenderer.php`) caches the result by signature and is called from `card-pdf.php` (single download) and `api/print-ready.php` (imposition). Existing PNG-in-PDF flow stays as fallback for templates without a vector source.

**Tech Stack:** Python 3.12 + PyMuPDF 1.27 (already on VPS, used by `parse_card_pdf.py`), PHP 8.3, MySQL 10.x, TCPDF (already vendored, used for fallback + 8-up imposition assembly). No new server dependencies.

---

## Context

Today the canonical card PDF served by `card-pdf.php` is a PDF that embeds two raster PNGs (front + back at 1200 DPI). `api/print-ready.php` does the same and tiles them 8-up on A4. Both render-shippable but the text inside the PDF is pixels, not glyphs — so the print-shop's RIP cannot vectorize edges, file size is ~1.6 MB per card, contact info is not selectable / searchable / accessible, and any future scale-up (A3 poster, billboard) softens.

The importer already saves three artifacts per side at template-import time:
- `bg-page-N.png` — 4376×2832 raster at print 1200 DPI (used by Fabric editor + canonical PNG export)
- `bg-page-N.svg` — true vector, ~335 KB per page (currently unused at runtime)
- `source.pdf` — original uploaded PDF with Lato-Medium + Sora-Regular embedded as font subsets

This plan wires the unused SVG + extracted fonts into a new vector-PDF render path. The existing PNG path stays untouched — the canonical PNG is still served on the web (`digital_card.php`), as Apple Wallet strip image, as `og:image`, and to the print-shop preview. Only the **download / print** PDF surfaces switch to vector.

## File Structure

| File | Purpose |
|---|---|
| `scripts/render-card-pdf.py` | NEW. Python helper: takes `(employee_id, output_path)`, emits a 2-page vector PDF using `bg-page-N.svg` + extracted Lato/Sora + employee data from MySQL. |
| `scripts/extract-template-fonts.py` | NEW. One-shot helper that extracts embedded TTF fonts out of `source.pdf` into `<import-token>/fonts/{Lato-Medium,Sora-Regular}.ttf`. Run once per imported template. |
| `includes/CardPDFRenderer.php` | NEW. PHP wrapper: shells out to `render-card-pdf.py`, caches in `tmp/pdf-vector/`, signature-keys with `template.current_version + employee.updated_at`. Returns absolute filesystem path. |
| `includes/CardRenderer.php` | MODIFY. Add `vectorPdfFor(employeeId)` that defers to `CardPDFRenderer`; existing PNG behaviour unchanged. |
| `card-pdf.php` | MODIFY. Prefer `CardPDFRenderer` output over the canvas-PNG-embed path; fall back when Python helper unavailable or template has no vector source. |
| `api/print-ready.php` | MODIFY. New `mode=vector` flag and code path that imposes per-employee vector PDFs into A4 8-up via PyMuPDF (new helper) instead of TCPDF wrapping PNGs. |
| `scripts/imposition-vector.py` | NEW. Takes a list of per-employee vector PDFs + paper size + bleed + cutting-mark spec, emits one A4/A3 imposition PDF. PyMuPDF Page-overlay. |
| `database/migrations/095_template_vector_assets.php` | NEW. Adds `templates.has_vector_source TINYINT(1) DEFAULT 0`, `templates.fonts_dir VARCHAR(500) NULL`. Backfills existing rows with vector detection. |
| `tests/python/test_render_card_pdf.py` | NEW. pytest suite: bbox positions match `fields_json`, fonts embedded, text selectable, file < 200 KB. |
| `tests/e2e/vector-pdf.spec.ts` | NEW. Playwright: download `/card-pdf.php?i=...`, parse with PyMuPDF in a Node child process, assert text-select works, fonts panel lists Lato + Sora. |

## Sequencing

1. Migration + asset extraction (Task 1-2) — touches DB schema + filesystem only, zero blast radius
2. Python renderer + tests (Task 3-6) — pure Python, runs offline, no web traffic
3. PHP wrapper + caching (Task 7-9) — server-side only, no UI change
4. `card-pdf.php` switchover with fallback (Task 10-12) — first user-visible change, fallback keeps it safe
5. Print-shop imposition (Task 13-15) — second user-visible change
6. Backfill + verification (Task 16-17) — sweep existing templates

Each task is a self-contained commit. After Task 12 the customer download is vector; after Task 15 the print shop receives vector. Tasks 1-3 ship in one session, 4-6 in the next, etc.

---

## Task 1: Database migration for vector source flags

**Files:**
- Create: `database/migrations/095_template_vector_assets.php`

- [ ] **Step 1: Write the migration**

```php
<?php
/**
 * Migration 095: track vector-source availability per template.
 *
 *   has_vector_source TINYINT(1) DEFAULT 0
 *     1 when scripts/parse_card_pdf.py exported a usable bg-page-N.svg
 *     and source.pdf has at least one embedded TTF font.
 *
 *   fonts_dir VARCHAR(500) NULL
 *     Web-relative path to the directory holding extracted TTF fonts
 *     for this template (e.g. /uploads/templates/imports/<token>/fonts).
 *     NULL until extract-template-fonts.py runs for the template.
 */
require_once __DIR__ . '/../../config.php';

try {
    $db = Database::getInstance();
    $db->exec(
        "ALTER TABLE templates
         ADD COLUMN IF NOT EXISTS has_vector_source TINYINT(1) NOT NULL DEFAULT 0
                 AFTER current_version,
         ADD COLUMN IF NOT EXISTS fonts_dir VARCHAR(500) NULL
                 AFTER has_vector_source"
    );
    echo "Migration 095: templates.has_vector_source + fonts_dir added\n";
} catch (Throwable $e) {
    echo "Migration 095 failed: " . $e->getMessage() . "\n";
    exit(1);
}
```

- [ ] **Step 2: Run the migration**

Run on VPS:
```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php database/migrations/095_template_vector_assets.php"
```
Expected: `Migration 095: templates.has_vector_source + fonts_dir added`

- [ ] **Step 3: Verify schema**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J -h 127.0.0.1 bc -e 'DESCRIBE templates;' | grep -E 'has_vector|fonts_dir'"
```
Expected: two rows showing `has_vector_source tinyint(1)` and `fonts_dir varchar(500)`.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/095_template_vector_assets.php
git commit -m "migration 095: templates.has_vector_source + fonts_dir for vector-PDF render path"
```

---

## Task 2: Font extraction helper

**Files:**
- Create: `scripts/extract-template-fonts.py`

- [ ] **Step 1: Write the helper**

```python
#!/usr/bin/env python3
"""
Extract embedded TTF fonts from a template's source.pdf.

Usage:
    python3 scripts/extract-template-fonts.py <import-dir>

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


def main():
    if len(sys.argv) != 2:
        print("Usage: extract-template-fonts.py <import-dir>", file=sys.stderr)
        return 2
    import_dir = sys.argv[1].rstrip('/')
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
```

- [ ] **Step 2: Test on Otech locally**

```bash
python3 scripts/extract-template-fonts.py /tmp/otech-pdf
ls -la /tmp/otech-pdf/fonts/
```
Expected: `Extracted 2 fonts to /tmp/otech-pdf/fonts` plus files `Lato-Medium.ttf`, `Sora-Regular.ttf`, `manifest.json`.

- [ ] **Step 3: Run on VPS for the existing Otech template**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && python3 scripts/extract-template-fonts.py uploads/templates/imports/d0d6a6ce343e6635 && chown -R www:www uploads/templates/imports/d0d6a6ce343e6635/fonts"
```
Expected: same output.

- [ ] **Step 4: Update Otech templates row**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J -h 127.0.0.1 bc -e \"UPDATE templates SET has_vector_source=1, fonts_dir='/uploads/templates/imports/d0d6a6ce343e6635/fonts' WHERE company_id='otech7010-rfq-2026-odp-omandatapark';\""
```

- [ ] **Step 5: Commit**

```bash
git add scripts/extract-template-fonts.py
git commit -m "scripts: extract TTF fonts from template source.pdf"
```

---

## Task 3: Python vector-PDF renderer skeleton + first test

**Files:**
- Create: `scripts/render-card-pdf.py`
- Create: `tests/python/test_render_card_pdf.py`
- Create: `tests/python/conftest.py`

- [ ] **Step 1: Write the failing test**

```python
# tests/python/conftest.py
import sys, os
sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', '..', 'scripts'))
```

```python
# tests/python/test_render_card_pdf.py
"""
Renderer contract: given an import-dir + a fields_json (template) + employee
data, emit a 2-page vector PDF where every dynamic field is real text drawn
with the embedded fonts. The Otech sample data fixture exercises the
common case (4 dynamic fields per side, Lato-Medium body + Sora-Regular
name).
"""
import os
import json
import subprocess
import fitz
import pytest

OTECH_DIR = '/tmp/otech-pdf'

EMPLOYEE = {
    'id': 'muhammed.ali',
    'name_en': 'Muhammed Ali',
    'position_en': 'Product Manager',
    'mobile': 'M +968 9771 2345',
    'email': 'E muhammed.ali@otech.om',
    'address_en': 'H8JG+52V, Muscat, Oman',
    'website': 'www.otech.om',
}

# Two-page template fixture: front (mostly static + QR) + back (dynamic).
# Mirrors the shape parse_card_pdf.py emits.
TEMPLATE_FIXTURE = {
    'pages': [
        {'side': 'front', 'width_pt': 262.55, 'height_pt': 169.89,
         'background_svg_path': 'bg-page-1.svg', 'fields': []},
        {'side': 'back',  'width_pt': 262.55, 'height_pt': 169.89,
         'background_svg_path': 'bg-page-2.svg', 'fields': [
            {'field_key': 'name_en', 'x_pt': 31.8, 'y_pt': 48.4,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 13.1,
             'color': '#ffffff'},
            {'field_key': 'position_en', 'x_pt': 32.2, 'y_pt': 66.2,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 8.7,
             'color': '#ffffff'},
            {'field_key': 'mobile', 'x_pt': 32.6, 'y_pt': 97.7,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 7.6,
             'color': '#ffffff'},
            {'field_key': 'email', 'x_pt': 32.6, 'y_pt': 107.5,
             'font_family': 'Lato', 'font_weight': 500, 'font_size_pt': 7.6,
             'color': '#ffffff'},
         ]},
    ],
    'fonts_dir': os.path.join(OTECH_DIR, 'fonts'),
}


@pytest.fixture
def otech_dir(tmp_path):
    """Snapshot the live Otech import dir + fonts into a tmp path."""
    import shutil
    dst = tmp_path / 'otech'
    shutil.copytree(OTECH_DIR, dst)
    return str(dst)


def test_renderer_emits_two_page_pdf(otech_dir, tmp_path):
    template_path = tmp_path / 'template.json'
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir'] = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    template_path.write_text(json.dumps(fixture))

    employee_path = tmp_path / 'employee.json'
    employee_path.write_text(json.dumps(EMPLOYEE))

    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(template_path),
        '--employee', str(employee_path),
        '--out', str(out),
    ])

    assert out.exists() and out.stat().st_size > 0
    doc = fitz.open(str(out))
    assert doc.page_count == 2
```

- [ ] **Step 2: Run test, verify it fails**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs
python3 -m pytest tests/python/test_render_card_pdf.py::test_renderer_emits_two_page_pdf -v
```
Expected: FAIL with `FileNotFoundError: scripts/render-card-pdf.py` or `subprocess.CalledProcessError`.

- [ ] **Step 3: Implement the renderer skeleton**

```python
#!/usr/bin/env python3
"""
Render a per-employee 2-page vector PDF for one Cardify card.

Usage:
    python3 scripts/render-card-pdf.py \
        --template <path/to/template.json> \
        --employee <path/to/employee.json> \
        --out      <path/to/output.pdf>

template.json shape:
    {
      "import_dir": "<absolute path holding source.pdf + bg-page-N.svg>",
      "fonts_dir":  "<absolute path holding extracted .ttf fonts>",
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
import sys
import fitz


def render(template_path: str, employee_path: str, out_path: str) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    out_doc = fitz.open()  # empty
    for page_spec in template['pages']:
        page = out_doc.new_page(
            width=page_spec['width_pt'],
            height=page_spec['height_pt'],
        )
        # Stub: bg + fields wired in Task 4 + 5
        _ = page_spec, page

    out_doc.save(out_path, garbage=4, deflate=True)
    out_doc.close()
    return 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--template', required=True)
    ap.add_argument('--employee', required=True)
    ap.add_argument('--out',      required=True)
    args = ap.parse_args()
    sys.exit(render(args.template, args.employee, args.out))


if __name__ == '__main__':
    main()
```

- [ ] **Step 4: Run the test, verify it passes**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py::test_renderer_emits_two_page_pdf -v
```
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/render-card-pdf.py tests/python/conftest.py tests/python/test_render_card_pdf.py
git commit -m "scripts/render-card-pdf: 2-page PDF skeleton + pytest harness"
```

---

## Task 4: Embed SVG backgrounds as vector

**Files:**
- Modify: `scripts/render-card-pdf.py`
- Modify: `tests/python/test_render_card_pdf.py`

- [ ] **Step 1: Write the failing test**

Append to `tests/python/test_render_card_pdf.py`:

```python
def test_renderer_embeds_svg_bg_as_vector(otech_dir, tmp_path):
    template_path = tmp_path / 'template.json'
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    template_path.write_text(json.dumps(fixture))
    (tmp_path / 'employee.json').write_text(json.dumps(EMPLOYEE))

    out = tmp_path / 'out.pdf'
    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(template_path),
        '--employee', str(tmp_path / 'employee.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    # Both pages should have at least one vector drawing from the SVG.
    for i, page in enumerate(doc):
        drawings = page.get_drawings()
        assert len(drawings) > 100, (
            f"page {i} has only {len(drawings)} drawings, "
            f"expected >100 (source SVG has ~1900 paths each)"
        )
```

- [ ] **Step 2: Run, verify it fails**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py::test_renderer_embeds_svg_bg_as_vector -v
```
Expected: FAIL `assert 0 > 100`.

- [ ] **Step 3: Implement SVG bg embedding**

Replace the body of `render()` in `scripts/render-card-pdf.py`:

```python
def render(template_path: str, employee_path: str, out_path: str) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    import_dir = template['import_dir']
    out_doc = fitz.open()

    for page_spec in template['pages']:
        page = out_doc.new_page(
            width=page_spec['width_pt'],
            height=page_spec['height_pt'],
        )
        svg_rel = page_spec.get('background_svg_path')
        if svg_rel:
            svg_path = os.path.join(import_dir, svg_rel)
            if os.path.isfile(svg_path):
                with open(svg_path, 'rb') as fh:
                    svg_bytes = fh.read()
                # PyMuPDF lays SVG into a Document, then we copy that
                # document's first page onto our card page as a vector
                # underlay. show_pdf_page preserves paths/text/images.
                svg_doc = fitz.open(stream=svg_bytes, filetype='svg')
                page.show_pdf_page(
                    page.rect,
                    svg_doc,
                    pno=0,
                    keep_proportion=False,
                )
                svg_doc.close()

    out_doc.save(out_path, garbage=4, deflate=True)
    out_doc.close()
    return 0
```

- [ ] **Step 4: Run, verify it passes**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py -v
```
Expected: both tests PASS.

- [ ] **Step 5: Open the produced PDF + manually verify**

```bash
python3 -c "
import fitz, json, subprocess, os, shutil, tempfile
import tests.python.test_render_card_pdf as t
tmp = tempfile.mkdtemp()
shutil.copytree(t.OTECH_DIR, os.path.join(tmp, 'otech'))
fixture = dict(t.TEMPLATE_FIXTURE)
fixture['fonts_dir']  = os.path.join(tmp, 'otech', 'fonts')
fixture['import_dir'] = os.path.join(tmp, 'otech')
open(os.path.join(tmp, 'tpl.json'),'w').write(json.dumps(fixture))
open(os.path.join(tmp, 'emp.json'),'w').write(json.dumps(t.EMPLOYEE))
subprocess.check_call(['python3','scripts/render-card-pdf.py',
    '--template', os.path.join(tmp,'tpl.json'),
    '--employee', os.path.join(tmp,'emp.json'),
    '--out', '/tmp/cmp-bg.pdf'])
print('Wrote /tmp/cmp-bg.pdf')
"
open /tmp/cmp-bg.pdf
```
Expected: 2 pages, blue card with dot pattern + orange diamond + X/in icons + "An Omantel Company" + "Oman" + "@otech" all visible. NO dynamic text yet (Task 5).

- [ ] **Step 6: Commit**

```bash
git add scripts/render-card-pdf.py tests/python/test_render_card_pdf.py
git commit -m "render-card-pdf: lay SVG bg into the page as vector underlay"
```

---

## Task 5: Draw dynamic text with embedded fonts

**Files:**
- Modify: `scripts/render-card-pdf.py`
- Modify: `tests/python/test_render_card_pdf.py`

- [ ] **Step 1: Write the failing test**

Append:

```python
def test_dynamic_text_is_real_pdf_text(otech_dir, tmp_path):
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    (tmp_path / 'emp.json').write_text(json.dumps(EMPLOYEE))
    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    # Page 2 is the back, where the dynamic fields live.
    back = doc[1]
    page_text = back.get_text()
    # All four dynamic fields readable as real text.
    assert 'Muhammed Ali' in page_text, page_text
    assert 'Product Manager' in page_text, page_text
    assert '+968 9771 2345' in page_text, page_text
    assert 'muhammed.ali@otech.om' in page_text, page_text


def test_fonts_are_embedded(otech_dir, tmp_path):
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    (tmp_path / 'emp.json').write_text(json.dumps(EMPLOYEE))
    out = tmp_path / 'out.pdf'

    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    doc = fitz.open(str(out))
    fonts = []
    for page in doc:
        fonts.extend(page.get_fonts(full=True))
    families = {f[3].split('+')[-1].lower() for f in fonts}
    assert any('lato' in f for f in families), f"Lato not embedded: {families}"
```

- [ ] **Step 2: Run, verify both fail**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py -v -k "dynamic_text or fonts_are"
```
Expected: 2 FAIL, the produced PDF currently has no employee text.

- [ ] **Step 3: Implement font registration + text drawing**

Replace the per-page block in `render()`:

```python
def render(template_path: str, employee_path: str, out_path: str) -> int:
    with open(template_path) as fh:
        template = json.load(fh)
    with open(employee_path) as fh:
        employee = json.load(fh)

    import_dir = template['import_dir']
    fonts_dir  = template['fonts_dir']

    # Load + cache font bytes once per renderer invocation. PyMuPDF
    # accepts the raw TTF buffer via insert_font(fontbuffer=...).
    font_buffers = {}
    if os.path.isdir(fonts_dir):
        for name in os.listdir(fonts_dir):
            if name.lower().endswith(('.ttf', '.otf')):
                with open(os.path.join(fonts_dir, name), 'rb') as fh:
                    font_buffers[name.rsplit('.', 1)[0]] = fh.read()

    def pick_font(family: str, weight: int) -> tuple:
        """Map (Lato, 500) -> ('Lato-Medium', <buffer>). Falls back
        within the same family, then Helvetica as last resort."""
        family = (family or '').strip()
        candidates = []
        if family.lower() == 'lato':
            if weight >= 700: candidates = ['Lato-Bold', 'Lato-Medium', 'Lato-Regular']
            elif weight >= 500: candidates = ['Lato-Medium', 'Lato-Regular', 'Lato-Bold']
            else: candidates = ['Lato-Regular', 'Lato-Medium']
        elif family.lower() == 'sora':
            candidates = ['Sora-Regular', 'Sora-Medium']
        else:
            candidates = [family]
        for c in candidates:
            if c in font_buffers:
                return (c, font_buffers[c])
        return (None, None)

    out_doc = fitz.open()

    for page_spec in template['pages']:
        page = out_doc.new_page(
            width=page_spec['width_pt'],
            height=page_spec['height_pt'],
        )

        # 1. SVG bg as vector underlay
        svg_rel = page_spec.get('background_svg_path')
        if svg_rel:
            svg_path = os.path.join(import_dir, svg_rel)
            if os.path.isfile(svg_path):
                with open(svg_path, 'rb') as fh:
                    svg_bytes = fh.read()
                svg_doc = fitz.open(stream=svg_bytes, filetype='svg')
                page.show_pdf_page(page.rect, svg_doc, pno=0,
                                   keep_proportion=False)
                svg_doc.close()

        # 2. Dynamic fields as real PDF text
        registered_fonts = {}
        for field in page_spec.get('fields', []):
            key = field.get('field_key')
            if not key:
                continue
            text = (employee.get(key) or '').strip()
            if not text:
                # Fall back to address_en for the address slot
                if key == 'address':
                    text = (employee.get('address_en') or '').strip()
                if not text:
                    continue
            font_name, font_buf = pick_font(
                field.get('font_family', ''),
                int(field.get('font_weight', 400)),
            )
            if not font_buf:
                continue
            if font_name not in registered_fonts:
                registered_fonts[font_name] = page.insert_font(
                    fontname=font_name, fontbuffer=font_buf,
                )
            color_hex = field.get('color', '#ffffff').lstrip('#')
            r = int(color_hex[0:2], 16) / 255.0
            g = int(color_hex[2:4], 16) / 255.0
            b = int(color_hex[4:6], 16) / 255.0
            font_size = float(field.get('font_size_pt', 10))

            # PyMuPDF's insert_text expects the BASELINE position. The
            # importer stores y_pt at the cell-top of the glyph cell;
            # baseline = cell_top + ascender * font_size / em.
            # For Lato (ascender 0.987 of em), and Sora (0.97), the
            # offset is ~0.97 * fontSize. Approximate with 0.97.
            baseline_y = float(field['y_pt']) + 0.97 * font_size

            page.insert_text(
                fitz.Point(float(field['x_pt']), baseline_y),
                text,
                fontname=font_name,
                fontsize=font_size,
                color=(r, g, b),
                render_mode=0,
            )

    out_doc.save(out_path, garbage=4, deflate=True)
    out_doc.close()
    return 0
```

- [ ] **Step 4: Run all tests, verify they pass**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py -v
```
Expected: 4 PASS.

- [ ] **Step 5: Open + manually verify**

```bash
python3 -c "
import fitz, json, subprocess, os, shutil, tempfile
import tests.python.test_render_card_pdf as t
tmp = tempfile.mkdtemp()
shutil.copytree(t.OTECH_DIR, os.path.join(tmp, 'otech'))
fixture = dict(t.TEMPLATE_FIXTURE)
fixture['fonts_dir']  = os.path.join(tmp, 'otech', 'fonts')
fixture['import_dir'] = os.path.join(tmp, 'otech')
open(os.path.join(tmp, 'tpl.json'),'w').write(json.dumps(fixture))
open(os.path.join(tmp, 'emp.json'),'w').write(json.dumps(t.EMPLOYEE))
subprocess.check_call(['python3','scripts/render-card-pdf.py',
    '--template', os.path.join(tmp,'tpl.json'),
    '--employee', os.path.join(tmp,'emp.json'),
    '--out', '/tmp/cmp-vector.pdf'])
print('Wrote /tmp/cmp-vector.pdf')
"
open /tmp/cmp-vector.pdf
```
Expected: 2-page PDF, vector everything, fonts embedded. Open in Preview/Acrobat → text-select Muhammed Ali → highlight works. Right-click → Properties → Fonts panel lists Lato-Medium subset.

- [ ] **Step 6: Commit**

```bash
git add scripts/render-card-pdf.py tests/python/test_render_card_pdf.py
git commit -m "render-card-pdf: real text + embedded font subsets for dynamic fields"
```

---

## Task 6: Pixel-position parity with the canonical PNG

**Files:**
- Modify: `tests/python/test_render_card_pdf.py`

The y-correction we tuned for Fabric (`FABRIC_HALF_LEADING_FRAC=0.20`) doesn't apply here, PyMuPDF's `insert_text` at a given baseline lands the cap-top at exactly that baseline minus ascender_height. Verify the rendered text lands at the same place as the source PDF's original.

- [ ] **Step 1: Write the parity test**

```python
def test_text_baselines_match_source_pdf(otech_dir, tmp_path):
    """The renderer's PDF for the placeholder employee data should land
    text within 1 pt of the original source.pdf (which has the same
    text at the same coords)."""
    fixture = json.loads(json.dumps(TEMPLATE_FIXTURE))
    fixture['fonts_dir']  = os.path.join(otech_dir, 'fonts')
    fixture['import_dir'] = otech_dir
    (tmp_path / 'tpl.json').write_text(json.dumps(fixture))
    # Use the placeholder data the source.pdf was made with so
    # baseline positions are directly comparable.
    placeholder = {
        'id': 'sample',
        'name_en':     'Muhammed Ali',
        'position_en': 'Product Manager',
        'mobile':      'M +971 50 789 4563',
        'email':       'E muhammed.ali@otech.om',
        'address_en':  'H8JG+52V, Muscat, Oman',
        'website':     'www.otech.om',
    }
    (tmp_path / 'emp.json').write_text(json.dumps(placeholder))
    out = tmp_path / 'out.pdf'
    subprocess.check_call([
        'python3', 'scripts/render-card-pdf.py',
        '--template', str(tmp_path / 'tpl.json'),
        '--employee', str(tmp_path / 'emp.json'),
        '--out', str(out),
    ])

    src = fitz.open(os.path.join(otech_dir, 'source.pdf'))
    new = fitz.open(str(out))

    def first_bbox(page, needle):
        for b in page.get_text('dict')['blocks']:
            if b.get('type') != 0: continue
            for l in b['lines']:
                for s in l['spans']:
                    if needle in s['text']:
                        return s['bbox']
        return None

    for needle in ['Muhammed Ali', 'Product Manager', 'muhammed.ali@otech.om']:
        sb = first_bbox(src[1], needle)
        nb = first_bbox(new[1], needle)
        assert sb and nb, f"missing bbox for {needle}: src={sb} new={nb}"
        # Y delta within 1.5pt (half a 7.6pt cap height).
        assert abs(sb[1] - nb[1]) < 1.5, (
            f"{needle} y drift {sb[1]} vs {nb[1]} = {sb[1]-nb[1]:+.2f}pt"
        )
        assert abs(sb[0] - nb[0]) < 1.0, (
            f"{needle} x drift {sb[0]} vs {nb[0]} = {sb[0]-nb[0]:+.2f}pt"
        )
```

- [ ] **Step 2: Run, expect FAIL on the y-drift assertion**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py::test_text_baselines_match_source_pdf -v
```
Expected: FAIL with a y drift number > 1.5pt OR missing bbox (font ascender ratio used in Step 3 of Task 5 may be off for this font).

- [ ] **Step 3: Tune the ascender constant per font**

Replace the `0.97` magic number in `scripts/render-card-pdf.py` with a per-font lookup:

```python
# After font_buffers is loaded, read each font's actual ascender once.
font_ascenders = {}
for fname, buf in font_buffers.items():
    try:
        f = fitz.Font(fontbuffer=buf)
        font_ascenders[fname] = float(f.ascender)
    except Exception:
        font_ascenders[fname] = 0.97  # safe default
```

Then in the loop where `baseline_y` is computed:

```python
ascender = font_ascenders.get(font_name, 0.97)
baseline_y = float(field['y_pt']) + ascender * font_size
```

- [ ] **Step 4: Run, verify it passes**

```bash
python3 -m pytest tests/python/test_render_card_pdf.py -v
```
Expected: 5 PASS.

- [ ] **Step 5: Commit**

```bash
git add scripts/render-card-pdf.py tests/python/test_render_card_pdf.py
git commit -m "render-card-pdf: per-font ascender for pixel-perfect baseline match"
```

---

## Task 7: PHP wrapper with signature-keyed cache

**Files:**
- Create: `includes/CardPDFRenderer.php`

- [ ] **Step 1: Write the wrapper**

```php
<?php
/**
 * CardPDFRenderer, single-source-of-truth vector PDF for one employee.
 *
 * Shells out to scripts/render-card-pdf.py, caches the resulting PDF
 * in tmp/pdf-vector/ keyed by:
 *   sha1(employee_id . current_version . employee.updated_at . theme.updated_at)
 * so it stays warm until any of those bump.
 */
class CardPDFRenderer
{
    /**
     * Render or fetch a cached vector PDF for one employee.
     * Returns ['success'=>true, 'path'=>absolute fs path, 'cached'=>bool]
     * or ['success'=>false, 'error'=>string].
     *
     * Caller (card-pdf.php) is responsible for falling back to the
     * raster path when has_vector_source=0 or success=false.
     */
    public static function render(string $employeeId): array
    {
        if ($employeeId === '') {
            return ['success' => false, 'error' => 'empty employee id'];
        }

        $db = Database::getInstance();
        $employee = $db->fetchOne(
            'SELECT * FROM employees WHERE id = :id LIMIT 1',
            ['id' => $employeeId]
        );
        if (!is_array($employee)) {
            return ['success' => false, 'error' => 'employee not found'];
        }

        $companyId = $employee['company_id'];
        $tplFront = $db->fetchOne(
            "SELECT * FROM templates
              WHERE company_id = :cid AND side = 'front' AND is_active = 1
              ORDER BY created_at DESC LIMIT 1",
            ['cid' => $companyId]
        );
        $tplBack = $db->fetchOne(
            "SELECT * FROM templates
              WHERE company_id = :cid AND side = 'back' AND is_active = 1
              ORDER BY created_at DESC LIMIT 1",
            ['cid' => $companyId]
        );
        if (!is_array($tplFront) && !is_array($tplBack)) {
            return ['success' => false, 'error' => 'no active templates'];
        }
        // Front + back must both be vector-capable; otherwise we fall back.
        $vectorOk = is_array($tplFront) && (int)($tplFront['has_vector_source'] ?? 0) === 1
                 && is_array($tplBack)  && (int)($tplBack['has_vector_source']  ?? 0) === 1;
        if (!$vectorOk) {
            return ['success' => false, 'error' => 'template lacks vector source'];
        }

        // Cache signature, anything that changes the visible card busts.
        $sig = sha1(implode('|', [
            $employee['id'],
            (int)($tplFront['current_version'] ?? 1),
            (int)($tplBack['current_version']  ?? 1),
            $employee['updated_at']  ?? '',
        ]));
        $cacheDir = BASE_DIR . '/tmp/pdf-vector';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        $cachePath = $cacheDir . '/' . $sig . '.pdf';
        if (is_file($cachePath) && filesize($cachePath) > 1024) {
            return ['success' => true, 'path' => $cachePath, 'cached' => true];
        }

        // Build template + employee JSON for the Python renderer.
        $importDirFront = self::importDirOf($tplFront);
        $importDirBack  = self::importDirOf($tplBack);
        // Both sides currently come from the same import_token in
        // practice. Pick the back's import_dir as the source for the
        // shared font + svg paths; if they ever diverge, refactor to
        // pass per-page import_dir.
        $importDir = $importDirBack ?: $importDirFront;
        if (!$importDir || !is_dir($importDir)) {
            return ['success' => false, 'error' => 'import_dir missing'];
        }

        $fontsDir = self::resolveFs((string)($tplBack['fonts_dir'] ?? ''));
        if (!$fontsDir || !is_dir($fontsDir)) {
            return ['success' => false, 'error' => 'fonts_dir missing, run extract-template-fonts.py'];
        }

        $template = [
            'import_dir' => $importDir,
            'fonts_dir'  => $fontsDir,
            'pages' => [
                self::pageSpec($tplFront, 'front'),
                self::pageSpec($tplBack,  'back'),
            ],
        ];
        $tmpTpl = tempnam(sys_get_temp_dir(), 'cpdftpl_') . '.json';
        $tmpEmp = tempnam(sys_get_temp_dir(), 'cpdfemp_') . '.json';
        file_put_contents($tmpTpl, json_encode($template, JSON_UNESCAPED_UNICODE));
        file_put_contents($tmpEmp, json_encode($employee, JSON_UNESCAPED_UNICODE));

        $py  = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellcmd($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/render-card-pdf.py')
             . ' --template ' . escapeshellarg($tmpTpl)
             . ' --employee ' . escapeshellarg($tmpEmp)
             . ' --out '      . escapeshellarg($cachePath)
             . ' 2>&1';
        $rc = 0; $out = [];
        exec($cmd, $out, $rc);
        @unlink($tmpTpl);
        @unlink($tmpEmp);

        if ($rc !== 0 || !is_file($cachePath) || filesize($cachePath) < 1024) {
            error_log('CardPDFRenderer rc=' . $rc . ' out=' . implode("\n", $out));
            return ['success' => false, 'error' => 'render failed'];
        }
        return ['success' => true, 'path' => $cachePath, 'cached' => false];
    }

    private static function pageSpec(?array $tpl, string $side): array
    {
        if (!is_array($tpl)) {
            return ['side' => $side, 'width_pt' => 262.55, 'height_pt' => 169.89, 'fields' => []];
        }
        $settings = json_decode((string)($tpl['settings_json'] ?? ''), true) ?: [];
        $widthMm  = (float)($settings['customWidth']  ?? 92.62);
        $heightMm = (float)($settings['customHeight'] ?? 59.93);
        $widthPt  = $widthMm  * 72 / 25.4;
        $heightPt = $heightMm * 72 / 25.4;
        $fields   = json_decode((string)($tpl['fields_json'] ?? '[]'), true) ?: [];

        // settings_json from the importer carries a background_svg_path
        // alongside background_image_path.
        $svgRel = $settings['background_svg_path'] ?? str_replace('.png', '.svg', basename((string)($tpl['background_image_path'] ?? '')));

        $fieldList = [];
        foreach ($fields as $key => $f) {
            if (!is_array($f)) continue;
            // Only DYNAMIC fields go through PyMuPDF's text drawing.
            // Statics are already in the SVG bg.
            if (!empty($f['render_in_bg'])) continue;
            if (!empty($f['is_static']))    continue;
            if ($key === 'qr_code')         continue;
            $fieldList[] = [
                'field_key'    => $key,
                'x_pt'         => (float)($f['x_pt']     ?? ($f['x'] ?? 0) / 4.166),
                'y_pt'         => (float)($f['y_pt']     ?? ($f['y'] ?? 0) / 4.166),
                'font_family'  => (string)($f['fontFamily'] ?? $f['font_family'] ?? 'Lato'),
                'font_weight'  => (int)($f['fontWeight'] ?? $f['font_weight'] ?? 400),
                'font_size_pt' => (float)($f['font_size_pt'] ?? ($f['fontSize'] ?? 10) / 4.166),
                'color'        => (string)($f['fill'] ?? $f['color'] ?? '#ffffff'),
            ];
        }
        return [
            'side'                 => $side,
            'width_pt'             => $widthPt,
            'height_pt'            => $heightPt,
            'background_svg_path'  => $svgRel,
            'fields'               => $fieldList,
        ];
    }

    private static function importDirOf(?array $tpl): ?string
    {
        if (!is_array($tpl)) return null;
        $bg = (string)($tpl['background_image_path'] ?? '');
        if ($bg === '') return null;
        $bg = ltrim($bg, '/');
        $abs = BASE_DIR . '/' . $bg;
        return is_file($abs) ? dirname($abs) : null;
    }

    private static function resolveFs(string $rel): string
    {
        if ($rel === '') return '';
        if ($rel[0] === '/') return BASE_DIR . $rel;
        return BASE_DIR . '/' . ltrim($rel, '/');
    }
}
```

- [ ] **Step 2: Lint**

```bash
php -l includes/CardPDFRenderer.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3: Smoke-test from CLI**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php -r '
require \"config.php\";
require \"includes/CardPDFRenderer.php\";
\$r = CardPDFRenderer::render(\"muhammed.ali\");
print_r(\$r);
if (\$r[\"success\"]) {
    echo \"size=\" . filesize(\$r[\"path\"]) . \"\n\";
}'"
```
Expected: `success => true`, `path => ...pdf`, file size 50–200 KB.

- [ ] **Step 4: Verify the produced PDF on local machine**

```bash
scp root@147.93.20.54:$(ssh root@147.93.20.54 "/www/server/php/83/bin/php -r 'require \"/www/wwwroot/cardify.om/config.php\"; require \"/www/wwwroot/cardify.om/includes/CardPDFRenderer.php\"; \$r = CardPDFRenderer::render(\"muhammed.ali\"); echo \$r[\"path\"];'") /tmp/cardpdf-server.pdf
open /tmp/cardpdf-server.pdf
```
Expected: vector PDF, fonts embedded, text selectable.

- [ ] **Step 5: Commit**

```bash
git add includes/CardPDFRenderer.php
git commit -m "includes/CardPDFRenderer: PHP wrapper that shells out to render-card-pdf.py"
```

---

## Task 8: Cache invalidation hooks

**Files:**
- Modify: `includes/CardRenderer.php`

`CardRenderer::invalidateForCompany()` already nulls out `generated_cards.{front,back}_file_path`. Extend it to also clear stale vector PDFs from `tmp/pdf-vector/`.

- [ ] **Step 1: Read the existing invalidate method**

```bash
grep -nA 25 'public static function invalidateForCompany' includes/CardRenderer.php
```

- [ ] **Step 2: Add the cache sweep**

Add after the existing `UPDATE generated_cards ...` query inside `invalidateForCompany`:

```php
        // Clear cached vector PDFs for every employee in the company.
        // Cache key includes employee_id + template_version, but bumping
        // the template version means every employee's signature changes,
        // so the simplest correct sweep is to drop everything in the
        // company's slice. The dir is shared so we filter by employee.
        try {
            $cacheDir = BASE_DIR . '/tmp/pdf-vector';
            if (is_dir($cacheDir)) {
                $employeeIds = $db->fetchAll(
                    'SELECT id FROM employees WHERE company_id = :cid',
                    ['cid' => $companyId]
                );
                $ids = array_map(fn($r) => $r['id'], $employeeIds);
                // Cache filenames are sha1 of (id|...) so we can't filter
                // by id alone. The conservative sweep is to delete every
                // file in the dir; the dir is bounded (one PDF per
                // employee) and re-renders on next download.
                foreach (glob($cacheDir . '/*.pdf') as $f) {
                    @unlink($f);
                }
            }
        } catch (Throwable $e) {
            error_log('CardPDFRenderer cache sweep: ' . $e->getMessage());
        }
```

- [ ] **Step 3: Commit**

```bash
git add includes/CardRenderer.php
git commit -m "CardRenderer::invalidateForCompany: sweep cached vector PDFs too"
```

---

## Task 9: Wire `/card-pdf.php` to prefer vector

**Files:**
- Modify: `card-pdf.php`

- [ ] **Step 1: Add the vector-PDF fast path**

Find the block in `card-pdf.php` that begins:

```php
$ctx = CardRenderer::forEmployee($employeeId);
if (!$ctx || ($ctx['employee']['status'] ?? 'active') !== 'active') {
```

Insert immediately AFTER the `$employee = $ctx['employee'];` block:

```php
    // Prefer the vector PDF when the template was imported with a
    // vector source. Falls back to the existing PNG-in-PDF path
    // when the renderer is unavailable.
    require_once INCLUDES_DIR . '/CardPDFRenderer.php';
    $vector = CardPDFRenderer::render((string)$employee['id']);
    if (!empty($vector['success']) && is_file($vector['path'])) {
        try { QRTracker::logScan($employee['id'], $company['id']); } catch (Throwable $e) {}
        while (ob_get_level()) { ob_end_clean(); }
        $name = trim((string)($employee['name_en'] ?? $employee['name'] ?? '')) ?: 'Employee';
        $downloadName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) . '.pdf';
        if ($downloadName === '.pdf') $downloadName = 'business-card.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($vector['path']));
        header('Cache-Control: private, max-age=300');
        header('X-Content-Type-Options: nosniff');
        readfile($vector['path']);
        exit;
    }
    // Otherwise fall through to the existing canvas-PNG-embed fallback.
```

- [ ] **Step 2: Lint + deploy**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs
php -l card-pdf.php
git add card-pdf.php
git commit -m "card-pdf: prefer CardPDFRenderer vector PDF, fall back to PNG-in-PDF"
git push origin main
ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
```

- [ ] **Step 3: Smoke test**

```bash
curl -sI 'https://cardify.om/card-pdf.php?i=muhammed.ali' | grep -iE 'HTTP|content-type|content-length'
curl -s 'https://cardify.om/card-pdf.php?i=muhammed.ali' -o /tmp/live-cardpdf.pdf
ls -la /tmp/live-cardpdf.pdf
python3 -c "
import fitz
d = fitz.open('/tmp/live-cardpdf.pdf')
print('pages:', d.page_count)
print('text page 2:', repr(d[1].get_text()[:200]))
fonts = []
for p in d: fonts += p.get_fonts(full=True)
print('fonts:', sorted({f[3].split('+')[-1] for f in fonts}))
"
```
Expected:
- `Content-Length` ~50–200 KB (vs ~1.6 MB before)
- `text page 2` includes `Muhammed Ali`, `Product Manager`, the phone, the email
- `fonts` lists `Lato-Medium`, `Sora-Regular`

- [ ] **Step 4: Open in Adobe + verify**

```bash
open /tmp/live-cardpdf.pdf
```
Open Acrobat → File → Properties → Fonts tab. Lato-Medium subset and Sora-Regular subset should be listed as Embedded Subset.

---

## Task 10: Update parse_card_pdf.py to also extract fonts on import

**Files:**
- Modify: `scripts/parse_card_pdf.py`

- [ ] **Step 1: Add the font-extract call to the importer**

Locate the section in `parse_card_pdf.py` that finishes writing the parse output (around the `pages_out.append(...)` and `return {...}` block). After the loop completes, before `return`:

```python
    # Extract embedded fonts to <output_dir>/fonts/. CardPDFRenderer
    # picks them up via templates.fonts_dir at render time.
    try:
        from extract_template_fonts import main as extract_main
    except ImportError:
        extract_main = None
    fonts_dir_rel = None
    if extract_main is not None:
        try:
            rc = extract_main(output_dir)
            if rc == 0:
                fonts_dir_rel = os.path.join(os.path.basename(output_dir), 'fonts')
        except Exception as e:
            print(f'WARN: font extraction failed: {e}', file=sys.stderr)
```

(The script already has the redaction + SVG export. We just bolt on font extraction.)

- [ ] **Step 2: Add an importable wrapper to `extract-template-fonts.py`**

Rename the script's main function so it can be imported:

```python
# scripts/extract_template_fonts.py (note underscore for Python import)
# ...keep the existing logic, exposed as `main(import_dir)`.
```

Symlink or rename so both `scripts/extract-template-fonts.py` (CLI) and `scripts/extract_template_fonts.py` (module) work.

```bash
cp scripts/extract-template-fonts.py scripts/extract_template_fonts.py
```

- [ ] **Step 3: Update `printshop/import_pdf.php` to record fonts_dir + has_vector_source**

After the existing `parse.json` write, add:

```php
// Persist vector-source flags onto the templates row at persist time
// (keeps the importer self-contained, avoids a separate migration step).
foreach ($parsed['pages'] as &$page) {
    if (!empty($page['fonts_dir_rel'])) {
        $page['fonts_dir_rel'] = '/' . trim($page['fonts_dir_rel'], '/');
    }
}
unset($page);
```

The persist step (`persist_template.php`) reads `fonts_dir_rel` from the parse output and writes it to `templates.fonts_dir` along with `has_vector_source = (fonts_dir_rel ? 1 : 0)`.

- [ ] **Step 4: Test end-to-end with a fresh import**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && python3 scripts/parse_card_pdf.py uploads/templates/imports/d0d6a6ce343e6635/source.pdf /tmp/test-import"
ls /tmp/test-import/fonts/
```
Expected: `Lato-Medium.ttf`, `Sora-Regular.ttf`, `manifest.json`.

- [ ] **Step 5: Commit**

```bash
git add scripts/parse_card_pdf.py scripts/extract_template_fonts.py printshop/import_pdf.php
git commit -m "parse_card_pdf: extract embedded fonts on import; persist fonts_dir"
```

---

## Task 11: Print-shop imposition with vector PDFs

**Files:**
- Create: `scripts/imposition-vector.py`
- Modify: `api/print-ready.php`

- [ ] **Step 1: Write the imposition helper**

```python
#!/usr/bin/env python3
"""
Compose a print-ready imposition sheet from N copies of a per-employee
vector card PDF. Layout is rows*cols on the requested paper size, with
3mm bleed, 0.25pt cutting marks at the corners of each card slot.

Usage:
    python3 scripts/imposition-vector.py \
        --card <front-or-back.pdf> \
        --paper A4 \
        --rows 5 --cols 2 \
        --bleed-mm 3 \
        --out <imposition.pdf>
"""
import argparse, fitz

PAPER_PT = {
    'A4': (595.28, 841.89),
    'A3': (841.89, 1190.55),
}

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--card',  required=True)
    ap.add_argument('--paper', default='A4', choices=PAPER_PT.keys())
    ap.add_argument('--rows',  type=int, default=5)
    ap.add_argument('--cols',  type=int, default=2)
    ap.add_argument('--bleed-mm', type=float, default=3.0)
    ap.add_argument('--margin-mm', type=float, default=10.0)
    ap.add_argument('--out',  required=True)
    args = ap.parse_args()

    paper_w, paper_h = PAPER_PT[args.paper]
    margin = args.margin_mm * 72 / 25.4
    bleed  = args.bleed_mm  * 72 / 25.4

    src = fitz.open(args.card)
    card_w = src[0].rect.width
    card_h = src[0].rect.height

    grid_w = paper_w - 2 * margin
    grid_h = paper_h - 2 * margin
    slot_w = grid_w / args.cols
    slot_h = grid_h / args.rows
    if slot_w < card_w or slot_h < card_h:
        raise SystemExit(f"card {card_w:.1f}x{card_h:.1f} doesn't fit in slot {slot_w:.1f}x{slot_h:.1f}")

    out = fitz.open()
    sheet = out.new_page(width=paper_w, height=paper_h)
    for r in range(args.rows):
        for c in range(args.cols):
            x0 = margin + c * slot_w + (slot_w - card_w) / 2
            y0 = margin + r * slot_h + (slot_h - card_h) / 2
            target = fitz.Rect(x0, y0, x0 + card_w, y0 + card_h)
            sheet.show_pdf_page(target, src, pno=0, keep_proportion=False)
            # Cutting marks, 5mm long at each corner, 0.25pt.
            mm = 5 * 72 / 25.4
            for (mx, my) in [(x0, y0), (x0 + card_w, y0), (x0, y0 + card_h), (x0 + card_w, y0 + card_h)]:
                sheet.draw_line(fitz.Point(mx - mm, my), fitz.Point(mx + mm, my), color=(0,0,0), width=0.25)
                sheet.draw_line(fitz.Point(mx, my - mm), fitz.Point(mx, my + mm), color=(0,0,0), width=0.25)

    out.save(args.out, garbage=4, deflate=True)

if __name__ == '__main__':
    main()
```

- [ ] **Step 2: Wire into `api/print-ready.php`**

Add a new branch in `handleGenerateRequest()` that, when `templates.has_vector_source = 1` for the order's company, calls the new helper instead of TCPDF:

```php
// Prefer the vector imposition when both sides are vector-source.
$frontTpl = $db->fetchOne(
    "SELECT has_vector_source FROM templates WHERE company_id = :cid AND side='front' AND is_active=1 LIMIT 1",
    ['cid' => $order['company_id']]
);
$backTpl = $db->fetchOne(
    "SELECT has_vector_source FROM templates WHERE company_id = :cid AND side='back'  AND is_active=1 LIMIT 1",
    ['cid' => $order['company_id']]
);
$wantVector = is_array($frontTpl) && (int)$frontTpl['has_vector_source'] === 1
           && is_array($backTpl)  && (int)$backTpl['has_vector_source']  === 1;

if ($wantVector) {
    require_once INCLUDES_DIR . '/CardPDFRenderer.php';
    $cardPdf = CardPDFRenderer::render((string)$order['employee_id']);
    if (!empty($cardPdf['success'])) {
        $py = trim((string)@shell_exec('command -v python3 2>/dev/null')) ?: 'python3';
        $cmd = escapeshellcmd($py)
             . ' ' . escapeshellarg(BASE_DIR . '/scripts/imposition-vector.py')
             . ' --card '  . escapeshellarg($cardPdf['path'])
             . ' --paper ' . escapeshellarg($paperSize)
             . ' --rows '  . (int)$rows
             . ' --cols '  . (int)$cols
             . ' --out '   . escapeshellarg($outputPath)
             . ' 2>&1';
        $rc = 0; $out = [];
        exec($cmd, $out, $rc);
        if ($rc === 0 && is_file($outputPath) && filesize($outputPath) > 1024) {
            // Success, fall through to the existing response writer.
            $useVectorImposition = true;
        }
    }
}
if (empty($useVectorImposition)) {
    // Existing TCPDF-wraps-PNG path stays untouched.
}
```

- [ ] **Step 3: Lint, deploy, smoke test**

```bash
php -l scripts/imposition-vector.py api/print-ready.php
git add scripts/imposition-vector.py api/print-ready.php
git commit -m "print-ready: vector imposition path for templates with embedded fonts"
git push origin main
ssh root@147.93.20.54 "/usr/local/bin/deploy-cardify.sh"
```

- [ ] **Step 4: Generate a test sheet**

```bash
# Print-shop login + POST to /api/print-ready.php?action=generate&order_id=<id>
# Verify the returned URL serves a vector PDF.
```

---

## Task 12: Backfill existing templates

**Files:**
- Create: `scripts/backfill-vector-source.php`

- [ ] **Step 1: Write the backfill**

```php
<?php
/**
 * Walk every template, run extract-template-fonts.py against its
 * import dir, set has_vector_source + fonts_dir on the row.
 * Idempotent, safe to re-run.
 */
require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT id, company_id, background_image_path FROM templates");
$updated = 0;
foreach ($rows as $r) {
    $bg = (string)($r['background_image_path'] ?? '');
    if ($bg === '') continue;
    $absDir = dirname(BASE_DIR . '/' . ltrim($bg, '/'));
    if (!is_file($absDir . '/source.pdf')) continue;
    $cmd = 'python3 ' . escapeshellarg(BASE_DIR . '/scripts/extract_template_fonts.py')
         . ' ' . escapeshellarg($absDir) . ' 2>&1';
    $rc = 0; $out = [];
    exec($cmd, $out, $rc);
    if ($rc !== 0) {
        echo "skip {$r['id']}: " . implode("\n", $out) . "\n";
        continue;
    }
    $rel = '/' . trim(str_replace(BASE_DIR, '', $absDir . '/fonts'), '/');
    $db->query(
        "UPDATE templates SET has_vector_source = 1, fonts_dir = :fd WHERE id = :id",
        ['fd' => $rel, 'id' => $r['id']]
    );
    $updated++;
    echo "ok   {$r['id']}: $rel\n";
}
echo "Done, $updated rows updated\n";
```

- [ ] **Step 2: Run on VPS**

```bash
ssh root@147.93.20.54 "cd /www/wwwroot/cardify.om && /www/server/php/83/bin/php scripts/backfill-vector-source.php && chown -R www:www uploads/templates/imports/*/fonts 2>/dev/null"
```

- [ ] **Step 3: Verify**

```bash
ssh root@147.93.20.54 "mysql -u bc -ppWewN3fwFmEHh32J -h 127.0.0.1 bc -e 'SELECT COUNT(*) AS total, SUM(has_vector_source) AS vector FROM templates;'"
```
Expected: `vector` close to `total`.

- [ ] **Step 4: Commit**

```bash
git add scripts/backfill-vector-source.php
git commit -m "scripts: backfill has_vector_source + fonts_dir on existing templates"
```

---

## Task 13: E2E verification

**Files:**
- Create: `tests/e2e/vector-pdf.spec.ts`

- [ ] **Step 1: Write the Playwright spec**

```typescript
import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';

test('card-pdf returns vector PDF with embedded fonts', async ({ request }) => {
  const url = '/card-pdf.php?i=muhammed.ali';
  const r = await request.get(url);
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('application/pdf');
  const buf = await r.body();
  expect(buf.length).toBeGreaterThan(20_000);
  expect(buf.length).toBeLessThan(500_000);
  const tmp = path.join('/tmp', `cv-${Date.now()}.pdf`);
  fs.writeFileSync(tmp, buf);
  // Use PyMuPDF in a child process to inspect text + fonts.
  const meta = execSync(
    `python3 -c "import json,fitz; d=fitz.open('${tmp}'); fonts=set(); txt=''; \\
[fonts.update(f[3].split('+')[-1] for f in p.get_fonts(full=True)) for p in d]; \\
[txt:=txt+p.get_text() for p in d]; \\
print(json.dumps({'fonts': sorted(fonts), 'has_name': 'Muhammed Ali' in txt, \\
'has_email': 'muhammed.ali@otech.om' in txt}))"`
  ).toString();
  const m = JSON.parse(meta);
  expect(m.fonts).toEqual(expect.arrayContaining(['Lato-Medium']));
  expect(m.has_name).toBe(true);
  expect(m.has_email).toBe(true);
});
```

- [ ] **Step 2: Run**

```bash
cd /Users/ali/claude/projects/cardify.om/.worktrees/ux-employee-tabs
BASE_URL=https://cardify.om npx playwright test tests/e2e/vector-pdf.spec.ts --project=chromium
```
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add tests/e2e/vector-pdf.spec.ts
git commit -m "tests/e2e: vector-pdf, ensures /card-pdf.php returns vector + embedded fonts"
```

---

## Verification (manual, end-to-end after Task 13)

Run after Task 13 ships. None of these are scripted; they're sanity checks before declaring "vector everywhere".

1. **Customer download size + format**
   - `curl -sI 'https://cardify.om/card-pdf.php?i=muhammed.ali' | grep -i content-length`
   - Expected: 50–200 KB (was ~1.6 MB)

2. **Selectable text in the PDF**
   - Open `/tmp/live-cardpdf.pdf` in Preview/Acrobat
   - Click and drag over "Muhammed Ali" → highlights as text
   - Cmd+C → "Muhammed Ali" copies to clipboard
   - Cmd+F "muhammed.ali@otech.om" → 1 match

3. **Embedded fonts**
   - Acrobat → File → Properties → Fonts
   - Listed: `Lato-Medium` (Embedded Subset), `Sora-Regular` (Embedded Subset)

4. **Vector zoom**
   - Zoom to 800% → text + bg paths still sharp, no aliasing

5. **Print-shop imposition**
   - Login as the BHD print shop, open an Otech print order
   - "Generate print sheet" → downloads imposition PDF
   - Open at 800% → 8 cards per A4, vector everywhere, cutting marks at corners

6. **Existing PNG flow still works for the web**
   - Visit `https://otech.cardify.om/muhammed.ali` → still serves the PNG canonical
   - Wallet pass strip image still raster (PNG)
   - `og:image` still raster (PNG)

7. **Fallback path still works**
   - Pick a template that has `has_vector_source = 0` (or temporarily UPDATE to 0)
   - `/card-pdf.php?i=...` for one of its employees → should fall back to PNG-in-PDF, not 500

8. **Cache hits**
   - First request: `cached=false`, full Python invocation
   - Second request within version: `cached=true`, instant
   - After `UPDATE templates SET current_version = current_version + 1`: cache miss, fresh render

If any step fails, the relevant Task above is the one to revisit. None of the verification steps require code changes if they pass.

---

## Notes

- **Apple Wallet `strip.png`** stays raster — Apple Wallet's spec demands PNG for the strip image. Vector is only meaningful for surfaces that go to a paper printer or a viewer that can zoom (PDF download, imposition sheet).
- **Web display** stays raster (canonical PNG via `digital_card.php`). Browsers render either format fine, but raster PNG matches what existing OG/wallet/print-shop preview code expects, and the web doesn't benefit from vector zoom (CSS already constrains size).
- **No new server packages.** PyMuPDF is already on the VPS (`/usr/bin/python3` + `pip install pymupdf` per the `parse_card_pdf.py` flow). No node-canvas, no ImageMagick changes.
- **Storage cost** is negligible: per-template `fonts/` dir is ~100 KB total (Lato-Medium + Sora-Regular subsets), per-employee cached PDF is ~50–200 KB.
- **Do not** change the canonical PNG export pipeline. The PNG remains the source of truth for everything web-facing. The vector PDF is an additional artifact.
