import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { KNOWN_CARD } from './fixtures';

test('card-pdf returns vector PDF with embedded fonts', async ({ request }) => {
  // Use the shared KNOWN_CARD fixture, so card deactivations are tracked in
  // one place. Previous hardcoded slug (muhammed.ali) was deactivated and
  // caused daily false-positive failure emails for ~3 weeks.
  const url = `/card-pdf.php?i=${KNOWN_CARD.eid}`;
  const r = await request.get(url);
  expect(r.status()).toBe(200);
  expect(r.headers()['content-type']).toContain('application/pdf');
  const buf = await r.body();
  expect(buf.length).toBeGreaterThan(20_000);
  // Budget covers the SVG-background pages whose convert_to_pdf path pulls
  // system fonts via fontconfig (~600KB Lato + ~100KB Sora). Dynamic-field
  // fonts ARE subsetted in render-card-pdf.py; the background path isn't.
  // Bump only when a real regression beyond ~800KB lands.
  expect(buf.length).toBeLessThan(800_000);
  const tmp = path.join('/tmp', `cv-${Date.now()}.pdf`);
  fs.writeFileSync(tmp, buf);
  // Use PyMuPDF in a child process to inspect text + fonts.
  const meta = execSync(
    `python3 -c "import json,fitz; d=fitz.open('${tmp}'); fonts={f[3].split('+')[-1] for page in d for f in page.get_fonts(full=True)}; txt=''.join(page.get_text() for page in d); print(json.dumps({'fonts': sorted(fonts), 'has_known_name': '${KNOWN_CARD.name}' in txt}))"`
  ).toString();
  const m = JSON.parse(meta);
  expect(m.fonts).toEqual(expect.arrayContaining(['Lato-Medium']));
  expect(m.has_known_name, 'employee name in PDF text').toBe(true);
});
