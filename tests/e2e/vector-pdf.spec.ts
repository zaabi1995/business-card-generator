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
  // Budget covers the SVG-background pages whose convert_to_pdf path pulls
  // system fonts via fontconfig (~600KB Lato + ~100KB Sora). Dynamic-field
  // fonts ARE subsetted in render-card-pdf.py; the background path isn't.
  // Bump only when a real regression beyond ~800KB lands.
  expect(buf.length).toBeLessThan(800_000);
  const tmp = path.join('/tmp', `cv-${Date.now()}.pdf`);
  fs.writeFileSync(tmp, buf);
  // Use PyMuPDF in a child process to inspect text + fonts.
  const meta = execSync(
    `python3 -c "import json,fitz; d=fitz.open('${tmp}'); fonts={f[3].split('+')[-1] for page in d for f in page.get_fonts(full=True)}; txt=''.join(page.get_text() for page in d); print(json.dumps({'fonts': sorted(fonts), 'has_name': 'Muhammed Ali' in txt, 'has_email': 'muhammed.ali@otech.om' in txt}))"`
  ).toString();
  const m = JSON.parse(meta);
  expect(m.fonts).toEqual(expect.arrayContaining(['Lato-Medium']));
  expect(m.has_name).toBe(true);
  expect(m.has_email).toBe(true);
});
