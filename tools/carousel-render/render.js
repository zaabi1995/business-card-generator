#!/usr/bin/env node
/**
 * Cardify LinkedIn Carousel Renderer
 * Usage: node render.js <input.json> <output.pdf>
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

async function main() {
  const [inputPath, outputPath] = process.argv.slice(2);
  if (!inputPath || !outputPath) {
    console.error('Usage: node render.js <input.json> <output.pdf>');
    process.exit(1);
  }

  const data = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
  const template = fs.readFileSync(path.join(__dirname, 'template.html'), 'utf8');

  const imgs = data.images || [];
  let rendered = template
    .replace('{{HOOK_EN}}', escapeHtml(data.hook_en))
    .replace('{{HOOK_AR}}', escapeHtml(data.hook_ar))
    .replace('{{TENSION}}', escapeHtml(data.tension))
    .replace('{{POINT_1_NUM}}', escapeHtml(data.points[0].number))
    .replace('{{POINT_1_TEXT}}', escapeHtml(data.points[0].text))
    .replace('{{POINT_2_NUM}}', escapeHtml(data.points[1].number))
    .replace('{{POINT_2_TEXT}}', escapeHtml(data.points[1].text))
    .replace('{{POINT_3_NUM}}', escapeHtml(data.points[2].number))
    .replace('{{POINT_3_TEXT}}', escapeHtml(data.points[2].text))
    .replace('{{TAKEAWAY_EN}}', escapeHtml(data.takeaway_en))
    .replace('{{TAKEAWAY_AR}}', escapeHtml(data.takeaway_ar))
    .replace('{{CTA_EN}}', escapeHtml(data.cta_en))
    .replace('{{CTA_AR}}', escapeHtml(data.cta_ar))
    .replace('{{QR_DATA_URL}}', data.qr_data_url || '');
  for (let i = 1; i <= 7; i++) {
    rendered = rendered.replace(`{{IMG_${i}}}`, imgs[i - 1] || '');
  }

  const tmpHtml = path.join(__dirname, '.tmp-render.html');
  fs.writeFileSync(tmpHtml, rendered);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1080, height: 1350 } });
  await page.goto('file://' + tmpHtml, { waitUntil: 'networkidle' });
  await page.evaluateHandle('document.fonts.ready');

  await page.pdf({
    path: outputPath,
    width: '1080px',
    height: '1350px',
    printBackground: true,
    pageRanges: '1-7',
    margin: { top: 0, right: 0, bottom: 0, left: 0 },
  });

  await browser.close();
  fs.unlinkSync(tmpHtml);
  console.log('OK: ' + outputPath);
}

function escapeHtml(s) {
  return String(s || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

main().catch(err => {
  console.error('RENDER ERROR:', err.message);
  process.exit(1);
});
