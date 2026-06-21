// Render the WC social ad set (OG 1200x630, Square 1080x1080, Story 1080x1920)
// from ad.html via headless Chromium. Fonts from fonts.bhd.om, FA7 from
// design.bhd.om. Logos embedded as data URIs so the capture is self-contained.
import { chromium } from 'playwright';
import { readFileSync, writeFileSync } from 'fs';
import { fileURLToPath } from 'url';
import { dirname, resolve } from 'path';

const __dir = dirname(fileURLToPath(import.meta.url));
const root  = resolve(__dir, '../../');           // repo root (worktree)
const adsDir = resolve(root, 'assets/wc/ads');

const wcLogo   = readFileSync(resolve(root, 'assets/wc/wc-logo.png')).toString('base64');
const cardify  = readFileSync(resolve(root, 'assets/images/logo.svg')).toString('utf8');
const wcUri    = 'data:image/png;base64,' + wcLogo;
const cardUri  = 'data:image/svg+xml;base64,' + Buffer.from(cardify).toString('base64');

let html = readFileSync(resolve(__dir, 'ad.html'), 'utf8')
  .replaceAll('__WCLOGO__', wcUri)
  .replaceAll('__CARDIFY__', cardUri);

const targets = [
  { id: 'og',    w: 1200, h: 630,  out: 'og.jpg',    jpg: true  },
  { id: 'sq',    w: 1080, h: 1080, out: 'square.jpg', jpg: true  },
  { id: 'story', w: 1080, h: 1920, out: 'story.jpg',  jpg: true  },
];

const browser = await chromium.launch();
const page = await browser.newPage({ deviceScaleFactor: 2 });
await page.setContent(html, { waitUntil: 'networkidle' });
// give webfonts + FA a beat to settle
await page.waitForTimeout(1200);

for (const t of targets) {
  const el = await page.$('#' + t.id);
  const buf = await el.screenshot(
    t.jpg ? { type: 'jpeg', quality: 90 } : { type: 'png' }
  );
  writeFileSync(resolve(adsDir, t.out), buf);
  console.log(`rendered ${t.out} (${t.w}x${t.h})`);
}

await browser.close();
console.log('done');
