# Vector PDF Pipeline, 20-Phase Improvement Plan

**Goal:** Harden, instrument, and extend the vector-PDF render path that just shipped. Each phase is a self-contained commit. Run autonomously, no per-phase user check-in.

**Scope guardrails:**
- Stay inside the card-rendering / printing / editor pipeline. Don't touch payments, auth, or unrelated admin pages.
- No phase requires product decisions; sensible defaults documented per phase.
- If a phase fails and can't be unblocked from in-context state, skip it, log the reason, continue.

---

## Phase 1, Playwright spec for fall-through path

**Files:**
- Create: `tests/e2e/vector-pdf-fallback.spec.ts`

When `templates.has_vector_source = 0` for a tenant, `card-pdf.php` must serve the raster PNG-in-PDF fallback (not 500). Lock that behaviour with a Playwright spec that:
1. Uses a session cookie (mint via `scripts/mint-session.php`)
2. Flips the active template's `has_vector_source` to 0 via a DB query (super-admin only)
3. Hits `https://cardify.om/card-pdf.php?i=muhammed.ali`
4. Asserts `Content-Length > 1_000_000` (raster fallback)
5. Restores `has_vector_source` to 1
6. Hits the URL again, asserts `Content-Length < 500_000` (vector)

Skip if no super-admin SID available (skip with a clear message, don't fail).

## Phase 2, Cache invalidation tests

**Files:**
- Create: `tests/python/test_invalidation.py`

Two tests:
1. `invalidateForCompany` removes all `tmp/pdf-vector/*.pdf` files (mock filesystem)
2. `invalidateForEmployee` removes them too (mocks the same way)

Use PHPUnit-style integration via shelling to PHP, or pure-Python fs mock that pre-creates dummy .pdf files in a temp dir, runs the relevant PHP method, asserts the dir is empty.

## Phase 3, Font-weight ranker test

**Files:**
- Modify: `tests/python/test_render_card_pdf.py`

Add a fixture template that requests `font_weight=700` but only `Lato-Regular.ttf` and `Lato-Black.ttf` are in the fonts dir. Assert the ranker picks `Lato-Black` (closer to 700 than `Lato-Regular`). Then a second sub-test where only `Lato-Light.ttf` and `Lato-Medium.ttf` exist for weight=700: assert `Lato-Medium` wins.

## Phase 4, Cross-surface vector audit script

**Files:**
- Modify: `scripts/audit-card-surfaces.php`

Today the audit checks PNG freshness across digital_card / wallet / og / print-shop. Add a vector-aware section that, for each employee with `has_vector_source=1`:
- HEAD `card-pdf.php?i=<id>`, assert Content-Length 50K-500K
- Verify cache file exists in `tmp/pdf-vector/`
- Check fonts_dir has Lato/Sora TTFs

Output: `vector_status` column in the audit table (vector / raster-fallback / missing).

## Phase 5, PDF metadata

**Files:**
- Modify: `scripts/render-card-pdf.py`

After `out_doc.save(out_path, ...)`, set PDF metadata via `out_doc.set_metadata({...})`:
- Title: `f"{employee['name_en']} business card"`
- Author: `company['name']`
- Subject: `"Digital business card"`
- Keywords: `"business card, contact, vCard, " + company_slug`
- Creator: `"Cardify (cardify.om)"`
- Producer: PyMuPDF version

Acrobat / Preview shows these in the document Properties panel.

## Phase 6, PDF/UA accessibility tags

**Files:**
- Modify: `scripts/render-card-pdf.py`

PyMuPDF supports limited tag-tree generation via `Page.insert_text(... tag=...)`. Wrap each dynamic field's `insert_text` call so the resulting PDF has:
- `<H1>` for the name
- `<P>` for the role
- `<P>` for each contact field

Even partial tagging makes the PDF screen-reader accessible. Verify with `pdf-tools` or `python -c "import fitz; d=fitz.open('out.pdf'); print(d.has_struct_tree())"`.

If PyMuPDF version doesn't support tags, skip the phase with a comment.

## Phase 7, Embedded vCard attachment

**Files:**
- Modify: `scripts/render-card-pdf.py`
- Reuse: `includes/VCF.php`

Embed the employee's vCard 3.0 as a PDF file attachment so PDF viewers (Acrobat, Apple Mail) offer a one-click "add to contacts". PyMuPDF: `out_doc.embfile_add(...)`.

Encode the vCard bytes in PHP first via `VCF::generate($employee, $company)`, write to a temp file, pass the path through the JSON template payload as `vcard_path`, then in Python embed it.

## Phase 8, Per-employee granular cache pruning

**Files:**
- Modify: `includes/CardPDFRenderer.php`
- Modify: `includes/CardRenderer.php`

Today `invalidateForEmployee` and `invalidateForCompany` both nuke ALL of `tmp/pdf-vector/*.pdf`. Make it surgical:

1. `CardPDFRenderer` writes a sidecar `<sha1>.meta` JSON next to each cached PDF with `{employee_id, generated_at, theme_updated_at}`.
2. `CardRenderer::invalidateForEmployee($id)` reads each `.meta`, deletes only the matching ones.
3. `CardRenderer::invalidateForCompany($cid)` looks up the company's employee_ids, deletes matching `.meta` + `.pdf` pairs.

Metadata files stay tiny (<200 B each). Better cache hit rate across multi-tenant traffic.

## Phase 9, PDF linearization

**Files:**
- Modify: `scripts/render-card-pdf.py`

Add `linear=True` to `out_doc.save(...)`. Web-optimized PDFs stream first-page-first, browsers can render the visible page before the full file downloads. ~10% bigger file but better perceived speed.

## Phase 10, Async warmup cron

**Files:**
- Create: `scripts/warm-vector-cache.php`
- Add cron entry on VPS (manual)

Scan `templates` for any with `has_vector_source=1`. For each active employee in those companies, check `tmp/pdf-vector/<sig>.pdf` existence; if missing, call `CardPDFRenderer::render` to warm it. Run via cron every 5 min (low priority, single worker).

Skip if VPS already at >80% disk usage.

## Phase 11, PDF/X-4 compliance flag

**Files:**
- Modify: `scripts/render-card-pdf.py`
- Add CLI flag: `--profile [print|web]`

When `--profile print`, set:
- Output intent: ICC profile (use Coated FOGRA39 for offset, sRGB IEC61966 for digital)
- PDF version 1.4 (PDF/X-4 baseline)
- Embed full font (not subset) for print-shop reuse
- Tagged for trapping decisions

`api/print-ready.php` calls with `--profile print`. Customer-download `card-pdf.php` calls with `--profile web` (smaller, subsetted, OK for screen).

## Phase 12, Bleed + crop marks on single-card vector PDF

**Files:**
- Modify: `scripts/render-card-pdf.py`

Today the single-card PDF (used by `card-pdf.php`) has no bleed area or crop marks. For print-shop reuse (someone might print a single card directly), add 3mm bleed + cutting marks gated by `--for-print` flag. Default off (customer download stays clean).

## Phase 13, hex_to_rgb hardening

**Files:**
- Modify: `scripts/render-card-pdf.py`

Wrap the hex parser:
```python
def _hex_to_rgb(hex_color):
    s = (hex_color or '').lstrip('#')
    if len(s) == 3:
        s = ''.join(c*2 for c in s)
    if len(s) != 6:
        return (0, 0, 0)
    try:
        return tuple(int(s[i:i+2], 16)/255.0 for i in (0, 2, 4))
    except ValueError:
        return (0, 0, 0)
```

Stops one bad-template malformed-color crash.

## Phase 14, Scrub employee row before passing to renderer

**Files:**
- Modify: `includes/CardPDFRenderer.php`

Today `CardPDFRenderer::render` JSON-encodes the entire `employees` row (`SELECT *`) into the temp file passed to Python. That includes `password_hash` and any other private columns. Replace `SELECT * FROM employees` with `SELECT id, name_en, name_ar, position_en, position_ar, mobile, phone, email, website, address_en, address_ar, company_id, updated_at FROM employees`.

## Phase 15, Editor preview uses bg.svg when available

**Files:**
- Modify: `admin/index.php` (the `loadBackground` call site)
- Reuse: existing `_loadSvgAsRaster` in `assets/js/card-editor.js`

If `template.backgroundSvg` exists (set when has_vector_source=1), prefer it over `template.backgroundImage`. The SVG rasterizes per zoom level so editor stays sharp at any zoom. Fall back to PNG if SVG missing.

## Phase 16, "Preview as PDF" admin button

**Files:**
- Modify: `admin/auto_generate.php` (success state)

After the PNG canonical save, add a button:
> Download print PDF

Button hits `card-pdf.php?i={employee_id}` in a new tab. Lets admins audit the print PDF without re-saving.

## Phase 17, X-Cardify-Pdf-Mode response header

**Files:**
- Modify: `card-pdf.php`

Set response header `X-Cardify-Pdf-Mode: vector` when serving vector, `raster-fallback` when serving raster. Ops/canary monitoring can grep for `raster-fallback` to spot tenants where `has_vector_source` should be flipped on.

## Phase 18, Cache TTL bump + Last-Modified header

**Files:**
- Modify: `card-pdf.php`

Today the response is `Cache-Control: private, max-age=300` (5 min). Bump to 86400 (1 day) for vector path since it's invalidated by template changes. Add `Last-Modified` header from the cached file's mtime so browsers can revalidate cheaply.

## Phase 19, Vector OG image variant

**Files:**
- Create: `og-card.php` (new endpoint)
- Modify: `digital_card.php` (add link tag)

For social sharing on platforms that support PDF previews (LinkedIn, Slack), serve a 1-page vector PDF preview at `og-card.php?i=<employee_id>`. Same renderer as `card-pdf.php` but only the front page. Add `<meta name="twitter:image" content="og-card.php?i=...&format=png">` fallback for image-only social.

If the platform doesn't accept PDF, the existing PNG og:image still works.

## Phase 20, Documentation update

**Files:**
- Modify: `CLAUDE.md` (add a "vector PDF render path" section)
- Modify: `docs/CONVEX_DEPLOY.md` or equivalent (note the new tmp/pdf-vector cache dir)

Document:
- Architecture: bg-page-N.svg + extracted fonts + per-employee dynamic text
- Cache layout: `tmp/pdf-vector/<sha1>.pdf` keyed by `(employee, template_version, employee.updated_at, theme.updated_at)`
- Invalidation: `CardRenderer::invalidateForCompany|Employee` sweeps the cache
- Fall-through: `has_vector_source=0` → PNG-in-PDF
- Deploy: `tmp/pdf-vector/` is bootstrapped by `/usr/local/bin/deploy-cardify.sh`

---

## Execution loop

1. Phases 1-4 are tests + audit, batch one subagent
2. Phases 5-9 are renderer enhancements, batch one subagent
3. Phases 10-13 are infrastructure, batch one subagent
4. Phases 14-17 are PHP integration, batch one subagent
5. Phases 18-20 are polish, batch one subagent

After each batch, lint + deploy + smoke. If any phase fails, log the failure inline and continue. The remaining phases must not depend on a failed earlier phase.
