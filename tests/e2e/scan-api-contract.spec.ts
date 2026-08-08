/**
 * The scan API's HTTP CONTRACT, for the surface reachable without an account.
 *
 * api/scan/* had no E2E cover at all. These endpoints are what the Cardify Scan
 * iOS app talks to, so a status-code change here breaks a shipped binary that
 * cannot be hotfixed.
 *
 * WHAT THIS FILE CAN AND CANNOT REACH
 * -----------------------------------
 * Every mutating endpoint sits behind ScanAuth::requireEmployee(Mutation)(),
 * which is a real 401. Reaching past it needs a bearer token for a live
 * account, and using one would WRITE to production, so this file stops at the
 * gate and asserts the gate. What is reachable, and asserted here:
 *
 *   - 401 on every bearer endpoint, unauthenticated
 *   - 405 on wrong method for the endpoints whose method check runs BEFORE
 *     auth (update, sync, invite, otp-request, claim-otp)
 *   - 404 for claim-otp.php with an unknown claim token
 *   - no 5xx anywhere on the unauthenticated surface
 *
 * Tests that would need a live account or would send a real OTP to a real
 * person are NOT written here. See "NOT COVERED" at the bottom of this file.
 *
 * READ-ONLY. Every request either fails auth, fails the method check, or fails
 * input validation before anything is written or sent. The one endpoint that
 * could send a message (otp-request.php) is only ever called with identifiers
 * that are rejected by validation BEFORE the send path.
 */
import { test, expect } from '@playwright/test';

/** Endpoints that require a bearer token, with auth as the FIRST gate. */
const BEARER_ENDPOINTS = [
  'list.php',
  'create.php',
  'refine.php',
  'link-request.php',
] as const;

/** Endpoints whose REQUEST_METHOD check runs BEFORE auth, so 405 wins. */
const METHOD_FIRST_ENDPOINTS = [
  'update.php',
  'sync.php',
  'invite.php',
  'otp-request.php',
  'claim-otp.php',
] as const;

// ---------------------------------------------------------------------------
// Authentication gate
// ---------------------------------------------------------------------------

test.describe('unauthenticated access is refused with 401', () => {
  for (const ep of BEARER_ENDPOINTS) {
    test(`GET api/scan/${ep} is 401 without a token`, async ({ request }) => {
      const res = await request.get(`/api/scan/${ep}`);
      expect(res.status(), `api/scan/${ep} unauthenticated`).toBe(401);

      // The refusal must be machine-readable JSON, not an HTML error page: the
      // app parses this body.
      const body = await res.json();
      expect(body.success, 'success flag').toBe(false);
      expect(body.error, 'error string').toBeTruthy();
    });
  }

  test('api/scan/list.php is 401 with a garbage bearer token', async ({
    request,
  }) => {
    // A token that is well-formed but not real must be refused exactly like no
    // token at all. An endpoint that accepts an unknown token is the bug.
    const res = await request.get('/api/scan/list.php', {
      headers: { Authorization: 'Bearer not-a-real-token-e2e-0000000000' },
    });
    expect(res.status(), 'garbage bearer').toBe(401);
  });

  test('api/scan/list.php is 401 for POST as well as GET', async ({
    request,
  }) => {
    // list.php has no method check, so auth is the only gate on every verb.
    const res = await request.post('/api/scan/list.php');
    expect(res.status(), 'POST list.php unauthenticated').toBe(401);
  });
});

// ---------------------------------------------------------------------------
// Method gate
// ---------------------------------------------------------------------------

test.describe('wrong method is refused with 405', () => {
  for (const ep of METHOD_FIRST_ENDPOINTS) {
    test(`GET api/scan/${ep} is 405`, async ({ request }) => {
      // These five check REQUEST_METHOD before touching auth, which is why the
      // assertion is reachable with no credentials at all.
      const res = await request.get(`/api/scan/${ep}`);
      expect(res.status(), `GET api/scan/${ep}`).toBe(405);

      const body = await res.json();
      expect(body.success, 'success flag').toBe(false);
    });
  }
});

// ---------------------------------------------------------------------------
// Not-found gate
// ---------------------------------------------------------------------------

test('claim-otp.php answers 404 for an unknown claim token', async ({
  request,
}) => {
  // The claim token IS the authority on this endpoint (no session, no CSRF by
  // design), so an unknown token must be a hard 404 and must not disclose
  // whether the token merely expired, was opted out, or never existed.
  const res = await request.post('/api/scan/claim-otp.php', {
    data: { token: 'no-such-claim-token-e2e-000000', action: 'send' },
  });
  expect(res.status(), 'unknown claim token').toBe(404);

  const body = await res.json();
  expect(body.success).toBe(false);
  expect(body.error, 'error string').toBe('invalid_token');
});

// ---------------------------------------------------------------------------
// success:false must never ride on HTTP 200
// ---------------------------------------------------------------------------

test('no refusal on the unauthenticated surface rides on HTTP 200', async ({
  request,
}) => {
  // A JSON body saying success:false under a 200 is invisible to every monitor,
  // proxy, retry policy and error budget in the stack. Sweep the whole
  // unauthenticated surface in one test so a new instance of the bug cannot
  // hide behind a passing sibling.
  //
  // otp-request.php's VALIDATION paths used to be the known exception here and
  // were excluded. They were fixed on 9 Aug 2026, so they are now swept with
  // everything else. They stay safe to send: both identifiers below are
  // rejected by syntax validation before any lookup or any send.
  const probes: Array<{ label: string; run: () => Promise<any> }> = [];

  for (const ep of BEARER_ENDPOINTS) {
    probes.push({
      label: `GET api/scan/${ep}`,
      run: () => request.get(`/api/scan/${ep}`),
    });
  }
  for (const ep of METHOD_FIRST_ENDPOINTS) {
    probes.push({
      label: `GET api/scan/${ep}`,
      run: () => request.get(`/api/scan/${ep}`),
    });
  }
  probes.push({
    label: 'POST api/scan/claim-otp.php (unknown token)',
    run: () =>
      request.post('/api/scan/claim-otp.php', {
        data: { token: 'no-such-claim-token-e2e-000000', action: 'send' },
      }),
  });
  probes.push({
    label: 'POST api/scan/otp-request.php (no identifier)',
    run: () => request.post('/api/scan/otp-request.php', { data: {} }),
  });
  probes.push({
    label: 'POST api/scan/otp-request.php (unparseable identifier)',
    run: () =>
      request.post('/api/scan/otp-request.php', {
        data: { identifier: 'not-an-email-or-phone-e2e!!!' },
      }),
  });

  const offenders: string[] = [];
  for (const p of probes) {
    const res = await p.run();
    const status = res.status();
    let body: any = null;
    try {
      body = await res.json();
    } catch {
      // Non-JSON body is a separate problem, not this test's.
    }
    if (body && body.success === false && status < 400) {
      offenders.push(`${p.label} -> HTTP ${status} with success:false`);
    }
  }
  expect(offenders, 'refusals served with a 2xx/3xx status').toEqual([]);
});

/**
 * REGRESSION. A live defect when this suite was written on 8 Aug 2026, carried
 * here as an expected-to-fail test; fixed on 9 Aug 2026 and the annotation is
 * gone, so a re-break is now a red run.
 *
 * What it was: api/scan/otp-request.php rejected a malformed identifier with
 * HTTP 200 and a success:false body:
 *
 *   POST {}                                  -> 200 {"success":false,"error":"identifier_required"}
 *   POST {"identifier":"not-an-email!!!"}    -> 200 {"success":false,"error":"invalid_identifier"}
 *
 * Lines 46, 59 and 68 echoed without any preceding http_response_code(), while
 * the same file's method check, rate limit and server error all set one
 * correctly. An oversight in three branches, not a design.
 *
 * All three now answer 400 with an unchanged body. That is safe to do WITHOUT
 * creating an enumeration oracle because all three fire on the SYNTAX of the
 * supplied string, before any lookup: they answer "is this a well-formed
 * identifier", never "does this identifier have an account". The
 * anti-enumeration property lives further down the file, where a WELL-FORMED
 * identifier gets the same 200 {success:true, channel, identifier_masked}
 * whether or not an account exists, and that path was not touched.
 *
 * The same 200-on-refusal shape still exists on endpoints this suite cannot
 * reach without an account: invite.php (9 branches), link-request.php,
 * refine.php line 50, update.php line 108, and claim-otp.php, where a
 * rate-limited request answers 200 instead of 429. Listed under NOT COVERED.
 */
test('otp-request.php rejects a bad identifier with a 4xx, not a 200', async ({
  request,
}) => {
  // Deliberately unparseable as either an email or a phone number, so the
  // request is rejected by validation and NO OTP is sent to anybody.
  const res = await request.post('/api/scan/otp-request.php', {
    data: { identifier: 'not-an-email-or-phone-e2e!!!' },
  });

  const body = await res.json();
  expect(body.success, 'the request was in fact refused').toBe(false);
  // The body is unchanged, which is what keeps the shipped iOS binary working:
  // it reads body.error, not the status line.
  expect(body.error, 'error code the app switches on').toBe('invalid_identifier');

  expect(
    res.status(),
    'a refused identifier must not be an HTTP 200',
  ).toBeGreaterThanOrEqual(400);
});

test('otp-request.php rejects a missing identifier with a 4xx, not a 200', async ({
  request,
}) => {
  // The other repaired branch: no identifier at all. Nothing is sent, and no
  // lookup happens, so this cannot leak account existence either.
  const res = await request.post('/api/scan/otp-request.php', { data: {} });

  const body = await res.json();
  expect(body.success).toBe(false);
  expect(body.error).toBe('identifier_required');
  expect(
    res.status(),
    'a missing identifier must not be an HTTP 200',
  ).toBeGreaterThanOrEqual(400);
});

// ---------------------------------------------------------------------------
// Stability under repeat traffic
// ---------------------------------------------------------------------------

test('repeated requests never produce a 5xx', async ({ request }) => {
  // "Rate-limited endpoints must not 500." This does NOT cross a rate-limit
  // threshold, and saying so is the honest framing: every limiter in api/scan/
  // sits behind either auth or a valid identifier, so genuinely tripping one
  // would need a live account or would fire real WhatsApp/email OTPs at a real
  // person. What this does prove is that the refusal paths are stable under
  // repeat traffic and never degrade into a server error, which is the failure
  // mode that actually took the app down.
  const bad: string[] = [];
  for (let i = 0; i < 12; i++) {
    const res = await request.get('/api/scan/list.php');
    if (res.status() >= 500) bad.push(`iteration ${i} -> ${res.status()}`);
    if (res.status() !== 401) bad.push(`iteration ${i} -> ${res.status()}, expected 401`);
  }
  expect(bad, 'unstable responses under repeat traffic').toEqual([]);
});

test('no unauthenticated scan endpoint answers 5xx', async ({ request }) => {
  const bad: string[] = [];
  for (const ep of [...BEARER_ENDPOINTS, ...METHOD_FIRST_ENDPOINTS]) {
    for (const method of ['get', 'post'] as const) {
      const res =
        method === 'get'
          ? await request.get(`/api/scan/${ep}`)
          : await request.post(`/api/scan/${ep}`, { data: {} });
      if (res.status() >= 500) {
        bad.push(`${method.toUpperCase()} api/scan/${ep} -> ${res.status()}`);
      }
    }
  }
  expect(bad, 'scan endpoints answering 5xx').toEqual([]);
});

/*
 * NOT COVERED, and why. Each of these needs either a bearer token for a live
 * account (so the test would WRITE to production) or would send a real message
 * to a real person. Written down rather than half-tested:
 *
 *  - The ext / linkedin round-trip through create.php -> sync.php. This is the
 *    8 Aug ScanParser::sanitizeDraft() change and it is the single most
 *    valuable thing left untested. It needs a throwaway scan account: create a
 *    scan with an ext and a linkedin URL, pull it back through sync.php, assert
 *    both survived, delete the scan. It is covered as a unit test today in
 *    tests/php/scan_parser_test.php, which this branch wires into CI.
 *  - merge_provenance round-trip through update.php, including the oversized
 *    input that must be REJECTED rather than truncated. Same reason; also unit
 *    covered in tests/php/scan_parser_test.php.
 *  - create.php's COALESCE guard, that a push WITHOUT provenance cannot erase
 *    an existing history. Needs two sequential authenticated writes.
 *  - invite.php's nine 200-on-failure branches, link-request.php's validation
 *    and conflict branches, refine.php line 50, update.php line 108. All behind
 *    auth.
 *  - claim-otp.php answering 200 instead of 429 when rate-limited. Needs a
 *    valid claim token, which only exists for a real shadow profile.
 *  - create.php returning 400 rather than 405 on a wrong method. The method
 *    check sits AFTER auth, so it is unreachable without a token.
 *  - list.php accepting any verb (no method check at all). Reachable only with
 *    a token, since auth refuses first.
 */
