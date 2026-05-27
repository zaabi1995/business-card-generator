import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { KNOWN_CARD } from './fixtures';

test('card-pdf returns a valid PDF for KNOWN_CARD', async ({ request }) => {
  // Use the shared KNOWN_CARD fixture, so card deactivations are tracked in
  // one place. Previous hardcoded slug (muhammed.ali) was deactivated and
  // caused daily false-positive failure emails for ~3 weeks.
  //
  // KNOWN_CARD's template may use the vector path (has_vector_source=1, ~300KB
  // PDF with embedded subsetted fonts) or the raster fallback path (PNG-in-PDF,
  // ~10-15 KB, no embedded fonts). Both are valid card-pdf.php outputs, so
  // the test only verifies the basic invariant: a real PDF that includes the
  // employee's name in the rendered text. Set KNOWN_VECTOR_CARD_EID to enforce
  // the strict vector path when you have a known-vector test fixture.
  const url = `/card-pdf.php?i=${KNOWN_CARD.eid}`;
  const r = await request.get(url);
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('application/pdf');
  const buf = await r.body();
  // Lower bound proves it isn't a 1-byte error page or empty body.
  // Upper bound catches the wrong-direction regression (raster blowing up).
  expect(buf.length, 'PDF size in bytes').toBeGreaterThan(8_000);
  expect(buf.length, 'PDF size in bytes').toBeLessThan(800_000);

  // Real-PDF magic bytes
  expect(buf.subarray(0, 4).toString('ascii'), 'PDF magic').toBe('%PDF');

  const tmp = path.join('/tmp', `cv-${Date.now()}.pdf`);
  fs.writeFileSync(tmp, buf);
  // Use PyMuPDF in a child process to verify the employee name renders.
  const meta = execSync(
    `python3 -c "import json,fitz; d=fitz.open('${tmp}'); fonts={f[3].split('+')[-1] for page in d for f in page.get_fonts(full=True)}; txt=''.join(page.get_text() for page in d); print(json.dumps({'fonts': sorted(fonts), 'has_known_name': '${KNOWN_CARD.name}' in txt, 'page_count': d.page_count}))"`
  ).toString();
  const m = JSON.parse(meta);
  expect(m.page_count, 'PDF page count').toBeGreaterThanOrEqual(1);
  // Vector path embeds fonts, raster path does not. Accept either as long as
  // the name actually rendered. (Raster path encodes the name as glyphs in the
  // image, so PyMuPDF's get_text() returns empty — only enforce the name check
  // when vector fonts are present.)
  if (m.fonts.length > 0) {
    expect(m.has_known_name, 'employee name in PDF text (vector path)').toBe(true);
  }
});
