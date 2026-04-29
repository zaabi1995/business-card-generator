// Cardify load test, 100 concurrent virtual users (Cat T action 469).
//
// Usage (from the repo root on any box with k6 installed):
//   k6 run ops/k6-load-test.js
//   k6 run --vus 100 --duration 2m ops/k6-load-test.js
//   TARGET=https://stage.cardify.om k6 run ops/k6-load-test.js
//
// Install k6:
//   Ubuntu: sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
//           echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
//           sudo apt-get update && sudo apt-get install k6
//   macOS:  brew install k6
//
// Strategy:
//   - Ramp 0 → 100 VUs over 30s, hold for 60s, ramp down 30s.
//   - 80% of VUs read (home, pricing, status, /companies).
//   - 15% of VUs fetch a deep page (a company profile, an industry
//     landing, a blog post).
//   - 5% of VUs hit /api/health and /api/erp-health (API path).
//
// Thresholds:
//   - 95th pct response time <= 1500ms.
//   - Failure rate <= 1%.
// A k6 run exits non-zero if thresholds break, so this can slot into
// a nightly cron once staging is live.
//
// Intentionally read-only: no POSTs against live so we don't create
// test orders or spoof payments. Run the payment path on staging.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend } from 'k6/metrics';

const TARGET = __ENV.TARGET || 'https://cardify.om';
const errs   = new Rate('cardify_errors');
const ttfb   = new Trend('cardify_ttfb_ms', true);

export const options = {
  discardResponseBodies: true,
  stages: [
    { duration: '30s', target: 100 },
    { duration: '60s', target: 100 },
    { duration: '30s', target: 0   },
  ],
  thresholds: {
    http_req_duration:   ['p(95)<1500'],
    http_req_failed:     ['rate<0.01'],
    cardify_errors:      ['rate<0.01'],
  },
  tags: { service: 'cardify', run: 'load-100vu' },
};

const readPaths = [
  '/',
  '/pricing',
  '/status',
  '/faq',
  '/companies',
  '/logos',
  '/case-studies',
  '/contact',
  '/about',
];

const deepPaths = [
  '/companies/bhd-printing-and-designing',
  '/companies/dardasha',
  '/industries/restaurants',
  '/industries/oil-gas',
  '/solutions/bilingual-arabic-english-business-cards',
  '/blog',
];

const apiPaths = [
  '/api/health',
  '/api/erp-health',
];

function pick(arr) { return arr[Math.floor(Math.random() * arr.length)]; }

export default function () {
  const roll = Math.random();
  let path;
  if      (roll < 0.80) path = pick(readPaths);
  else if (roll < 0.95) path = pick(deepPaths);
  else                  path = pick(apiPaths);

  const url = `${TARGET}${path}`;
  const res = http.get(url, {
    headers: { 'User-Agent': 'k6-cardify/1.0', 'Accept': 'text/html,application/json' },
    tags:    { path },
    redirects: 3,
  });

  ttfb.add(res.timings.waiting, { path });

  const ok = check(res, {
    'status 200 or 304':  (r) => r.status === 200 || r.status === 304,
    'server headers set': (r) => r.headers['Content-Type'] !== undefined,
  });
  errs.add(!ok);

  // Realistic think time so we don't pound a single endpoint.
  sleep(Math.random() * 2 + 0.5);
}

// Human-readable summary printed at end of run.
export function handleSummary(data) {
  const avg = data.metrics.http_req_duration?.values?.avg?.toFixed(0);
  const p95 = data.metrics['http_req_duration']?.values?.['p(95)']?.toFixed(0);
  const fail = (data.metrics.http_req_failed?.values?.rate * 100).toFixed(2);
  const total = data.metrics.http_reqs?.values?.count;
  const summary = [
    'Cardify load test, 100 VUs',
    `Target: ${TARGET}`,
    `Requests: ${total}`,
    `Avg duration: ${avg}ms`,
    `p95 duration: ${p95}ms (threshold 1500)`,
    `Failure rate: ${fail}% (threshold 1%)`,
  ].join('\n');
  return {
    'stdout': summary + '\n',
    'ops/k6-last-run.json': JSON.stringify(data, null, 2),
  };
}
