/**
 * The PUBLIC IDENTITY CHAIN, end to end, on one real card.
 *
 * Everything a stranger touches after scanning a printed Cardify card:
 *
 *   printed QR -> qr.php -> digital card page
 *                        -> vCard (.vcf)  -> iOS / Android Contacts
 *                        -> Apple Wallet pass
 *                        -> card image + card PDF (preview, print)
 *
 * Nothing automated covered any of it before this file. The 8 Aug 2026 deploy
 * rewrote includes/VCF.php, added includes/VCardRfc.php, and changed vcf.php's
 * status codes, all with zero E2E cover.
 *
 * The vCard assertions are BYTE assertions on purpose. "200 OK, text/vcard" was
 * already true on the day an Arabic-only card reached iOS Contacts with no
 * readable name at all: the bytes were served, they just said the wrong thing.
 * So this file asserts structure (line octets, UTF-8 validity, FN, N family
 * name), not status.
 *
 * READ-ONLY. Every request here is a GET on a public surface. Nothing in this
 * file writes to production.
 *
 * NOTE ON SCOPE. This proves the invariants hold for the bytes production is
 * ACTUALLY emitting today. The exhaustive proof of the folding algorithm
 * itself, including a 120-character Arabic run folded mid-sequence and an emoji
 * run, lives in tests/php/vcf_test.php as a pure unit test. That file needs no
 * database and runs in well under a second, and until now nothing ran it; it is
 * wired into the php-unit job in .github/workflows/php.yml alongside this suite.
 */
import { test, expect } from '@playwright/test';
import {
  PUBLIC_CARD,
  vcfPath,
  assertPublicCardAlive,
} from './fixtures';
import {
  parseVCard,
  prop,
  prop1,
  components,
  unescapeValue,
  MAX_OCTETS,
  type VCard,
} from './vcard';

test.beforeAll(async ({ request }) => {
  await assertPublicCardAlive(request);
});

/** Fetch + parse the fixture card's vCard once per test that needs it. */
async function fetchCard(request: any): Promise<VCard> {
  const res = await request.get(vcfPath());
  expect(res.status(), `${vcfPath()} status`).toBe(200);
  return parseVCard(await res.body());
}

// ---------------------------------------------------------------------------
// A. The chain: every public surface of one real card answers
// ---------------------------------------------------------------------------

test('digital card page loads and shows the cardholder name', async ({
  request,
}) => {
  // /<slug>/card/<id> is the canonical printed URL. It 301s to the tenant
  // subdomain (adnan.cardify.om/jarwish9); following that is the point.
  const res = await request.get(`/${PUBLIC_CARD.slug}/card/${PUBLIC_CARD.eid}`);
  expect(res.status(), 'digital card page status').toBe(200);

  const html = await res.text();
  // The name has to be IN THE PAGE. A 200 on a shell that renders no identity
  // is the failure mode this catches.
  expect(html, 'cardholder name in page HTML').toContain(PUBLIC_CARD.name);
});

test('the printed card URL redirects to the tenant profile, it does not 404', async ({
  request,
}) => {
  const res = await request.get(
    `/${PUBLIC_CARD.slug}/card/${PUBLIC_CARD.eid}`,
    { maxRedirects: 0 },
  );
  expect(res.status(), 'expected a redirect from the path-form card URL').toBe(
    301,
  );
  expect(res.headers()['location'], 'redirect target').toContain(
    PUBLIC_CARD.slug,
  );
});

test('QR resolves: qr.php reaches a live surface for the card', async ({
  request,
}) => {
  // qr.php has three legitimate outcomes and the fixture card must hit one:
  //   - 302 to the digital card page (the default since the dynamic-QR change)
  //   - 302 to a per-employee custom redirect URL
  //   - 200 text/vcard when the owner opted into ?vcf=1 behaviour
  // What it must never do is 404 or 500 for a live card.
  const res = await request.get(`/qr.php?i=${PUBLIC_CARD.eid}`, {
    maxRedirects: 0,
  });
  expect([200, 302], `qr.php status (got ${res.status()})`).toContain(
    res.status(),
  );

  if (res.status() === 302) {
    const target = res.headers()['location'];
    expect(target, 'qr.php redirect target').toBeTruthy();
    // A redirect that lands on a dead page is not a resolved QR.
    const followed = await request.get(target);
    expect(followed.status(), `qr.php redirect target ${target}`).toBe(200);
  }
});

test('Apple Wallet endpoint returns a real pkpass', async ({ request }) => {
  const res = await request.get(`/wallet_apple.php?i=${PUBLIC_CARD.eid}`);
  expect(res.status(), 'wallet_apple status').toBe(200);
  expect(res.headers()['content-type'], 'wallet content-type').toContain(
    'application/vnd.apple.pkpass',
  );

  const buf = await res.body();
  // A .pkpass is a ZIP. "PK\x03\x04" proves it is the archive iOS expects and
  // not an HTML error page served with an optimistic content type.
  expect(buf.subarray(0, 2).toString('ascii'), 'pkpass ZIP magic').toBe('PK');
  expect(buf.length, 'pkpass size in bytes').toBeGreaterThan(2_000);
});

/** Magic-byte sniffers, so a 200 that is really an HTML error page cannot pass. */
const IMAGE_SNIFFERS: Array<{ mime: string; test: (b: Buffer) => boolean }> = [
  {
    mime: 'image/png',
    test: (b) => b.subarray(0, 8).toString('hex') === '89504e470d0a1a0a',
  },
  {
    mime: 'image/jpeg',
    test: (b) => b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff,
  },
  {
    mime: 'image/webp',
    test: (b) =>
      b.subarray(0, 4).toString('ascii') === 'RIFF' &&
      b.subarray(8, 12).toString('ascii') === 'WEBP',
  },
];

/** The real format of a body, by magic bytes, or null when it is not an image. */
function sniffImage(buf: Buffer): string | null {
  return IMAGE_SNIFFERS.find((s) => s.test(buf))?.mime ?? null;
}

test('card image renders as a real raster image', async ({ request }) => {
  const res = await request.get(`/card-image.php?i=${PUBLIC_CARD.eid}`);
  expect(res.status(), 'card-image status').toBe(200);

  const buf = await res.body();
  // Assert by MAGIC BYTES, not by the declared header. The header is not
  // trustworthy here (see the known-defect test below), and the thing that
  // actually matters for this test is that a decodable image came back rather
  // than an HTML error page wearing an image content type.
  expect(
    sniffImage(buf),
    `card-image body is not a PNG/JPEG/WebP (first bytes ${buf
      .subarray(0, 8)
      .toString('hex')})`,
  ).not.toBeNull();
  expect(buf.length, 'image size in bytes').toBeGreaterThan(1_000);
});

/**
 * REGRESSION. This was a live defect when this suite was written on 8 Aug 2026,
 * carried here as an expected-to-fail test; it was fixed on 9 Aug 2026 and the
 * annotation is gone, so from here on a re-break is a red run.
 *
 * What it was: card-image.php served a WebP body under `Content-Type: image/png`.
 *
 *   GET /card-image.php?i=fcb71d11da10e8ba5fd428ed
 *   -> 200, content-type: image/png, x-content-type-options: nosniff
 *   -> body starts 52 49 46 46 ... 57 45 42 50   ("RIFF....WEBP")
 *      file(1): RIFF (little-endian) data, Web/P image, VP8 encoding, 542x307
 *
 * Root cause, card-image.php lines 62-63 as they stood:
 *
 *   $ext  = strtolower(pathinfo($fs, PATHINFO_EXTENSION));
 *   $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
 *
 * The MIME came from the FILE EXTENSION through a two-way ternary, so every
 * format that was not jpg/jpeg was labelled image/png. WebP was not in the
 * branch at all, yet WebP is a first-class upload format everywhere else in the
 * product (portal.php:360 maps image/webp => webp; onboarding.php:274 and
 * digital_card.php:1777 both accept it), so a .webp card image was not an edge
 * case, it was a supported input mislabelled on the way out.
 *
 * Why it bit rather than being cosmetic: the response also carries
 * X-Content-Type-Options: nosniff, which instructs clients NOT to correct the
 * type from the bytes. Per CLAUDE.md this endpoint feeds og:image and the
 * print-shop preview, and OG scrapers (WhatsApp, Twitter, Facebook) trust the
 * declared Content-Type rather than sniffing, so a mislabelled card silently
 * lost its link preview.
 *
 * The fix applies the project's own upload rule ("detect MIME from file
 * contents via finfo, never trust the extension") on the SERVE side: card-image.php
 * now sniffs the bytes, with the extension map only as a fallback for hosts
 * without fileinfo.
 *
 * The assertion deliberately compares against MAGIC BYTES rather than a
 * hard-coded 'image/webp', so it keeps holding the day the renderer switches
 * format again.
 */
test('card-image.php Content-Type matches the bytes it sends', async ({
  request,
}) => {
  const res = await request.get(`/card-image.php?i=${PUBLIC_CARD.eid}`);
  expect(res.status()).toBe(200);

  const buf = await res.body();
  const declared = (res.headers()['content-type'] ?? '').split(';')[0].trim();
  const actual = sniffImage(buf);

  // THE CONTRACT: what the header promises is what the body is.
  expect(actual, 'the body is a recognisable image').not.toBeNull();
  expect(declared, 'declared Content-Type vs actual magic bytes').toBe(actual);
});

test('card PDF renders as a real PDF', async ({ request }) => {
  const res = await request.get(`/card-pdf.php?i=${PUBLIC_CARD.eid}`);
  expect(res.status(), 'card-pdf status').toBe(200);
  expect(res.headers()['content-type'], 'card-pdf content-type').toContain(
    'application/pdf',
  );

  const buf = await res.body();
  expect(buf.subarray(0, 4).toString('ascii'), 'PDF magic').toBe('%PDF');
  expect(buf.length, 'PDF size in bytes').toBeGreaterThan(8_000);
});

// ---------------------------------------------------------------------------
// B. The vCard as DATA: RFC 2426 structure, not "did it 200"
// ---------------------------------------------------------------------------

test('vCard is served with the vCard content type', async ({ request }) => {
  const res = await request.get(vcfPath());
  expect(res.status(), 'vcf status').toBe(200);
  expect(res.headers()['content-type'], 'vcf content-type').toContain(
    'text/vcard',
  );
});

test('vCard has the RFC 2426 envelope and CRLF terminators', async ({
  request,
}) => {
  const card = await fetchCard(request);

  expect(card.logical[0], 'first content line').toBe('BEGIN:VCARD');
  expect(card.logical[1], 'second content line').toBe('VERSION:3.0');
  expect(
    card.logical[card.logical.length - 1],
    'last content line',
  ).toBe('END:VCARD');

  // Lines are CRLF-delimited. A bare LF anywhere means some line was built
  // with "\n" and would break strict parsers.
  const raw = card.raw;
  for (let i = 0; i < raw.length; i++) {
    if (raw[i] === 0x0a) {
      expect(
        i > 0 && raw[i - 1] === 0x0d,
        `bare LF (no preceding CR) at byte ${i}`,
      ).toBe(true);
    }
  }
});

test('no content line exceeds 75 octets', async ({ request }) => {
  const card = await fetchCard(request);

  // The RFC counts OCTETS, not characters. Arabic is 2 octets per letter, so a
  // character-based limit passes here and still ships over-length lines.
  const offenders = card.overLength.map(
    (i) =>
      `line ${i} is ${card.physical[i].length} octets: ` +
      `${JSON.stringify(card.physical[i].toString('utf8').slice(0, 60))}`,
  );
  expect(offenders, `lines over ${MAX_OCTETS} octets`).toEqual([]);
});

test('every content line is independently valid UTF-8', async ({ request }) => {
  const card = await fetchCard(request);

  // THE fold-boundary assertion. A fold that cuts mid-sequence leaves two
  // halves that are each invalid UTF-8 on their own, even though concatenating
  // them is fine. That is what corrupts Arabic into replacement characters in
  // iOS Contacts, and it is invisible to any check that unfolds first.
  const offenders = card.invalidUtf8.map(
    (i) => `line ${i}: ${card.physical[i].toString('hex').slice(0, 80)}`,
  );
  expect(offenders, 'physical lines that are not valid UTF-8').toEqual([]);
});

test('folding actually happens, and unfolds back to whole values', async ({
  request,
}) => {
  const card = await fetchCard(request);

  // The fixture card carries a LinkedIn URL that crosses 75 octets, so a fold
  // is present. If this ever goes false the fixture stopped exercising folding
  // and the two tests above became vacuous, which is worth knowing.
  expect(
    card.hasFold,
    'fixture card no longer contains any folded line, so the folding assertions ' +
      'above no longer prove anything. Point PUBLIC_CARD at a card with a long ' +
      'field (URL or Arabic address).',
  ).toBe(true);

  // Unfolding must reassemble whole values: no logical line may still carry a
  // fold artefact (a CR, an LF, or a stray leading space).
  for (const line of card.logical) {
    expect(line, 'unfolded line still contains CR/LF').not.toMatch(/[\r\n]/);
    expect(line, 'unfolded line starts with whitespace').not.toMatch(/^[ \t]/);
  }

  // And every unfolded line must be a real property line "NAME[;params]:value".
  for (const line of card.logical) {
    expect(line, 'unfolded line is not a NAME:VALUE property').toMatch(
      /^[A-Za-z0-9-]+(;[^:]*)?:/,
    );
  }
});

test('FN is present and non-empty', async ({ request }) => {
  const card = await fetchCard(request);

  const fn = prop(card, 'FN');
  expect(fn.length, 'exactly one FN property').toBe(1);
  expect(unescapeValue(fn[0]).trim(), 'FN value').not.toBe('');
  expect(unescapeValue(fn[0]), 'FN value').toBe(PUBLIC_CARD.name);
});

test('N is present and carries a family name', async ({ request }) => {
  const card = await fetchCard(request);

  const n = prop(card, 'N');
  expect(n.length, 'exactly one N property').toBe(1);

  // N:Family;Given;Additional;Prefix;Suffix
  const parts = components(n[0]);
  expect(parts.length, 'N component count').toBeGreaterThanOrEqual(5);

  const family = unescapeValue(parts[0]).trim();
  const given = unescapeValue(parts[1]).trim();

  // The regression this exists for: an Arabic-only card reached Contacts with
  // no readable name because the family slot came back empty. iOS renders from
  // N alone, it discards FN entirely, so an empty family name is a card with
  // no name on the device no matter how good FN looks.
  expect(family, 'N family name (component 0)').not.toBe('');
  expect(given, 'N given name (component 1)').not.toBe('');
  expect(family, 'N family name').toBe(PUBLIC_CARD.family);
});

test('no CHARSET parameter is emitted', async ({ request }) => {
  const card = await fetchCard(request);

  // CHARSET is a vCard 2.1 parameter, undefined in the RFC 2426 this file
  // declares in VERSION:3.0. It was removed deliberately in the 8 Aug deploy.
  for (const line of card.logical) {
    expect(line.toUpperCase(), 'CHARSET parameter').not.toContain('CHARSET');
  }
});

test('UID is present and identifies the employee', async ({ request }) => {
  const card = await fetchCard(request);
  expect(prop1(card, 'UID'), 'UID').toBe(PUBLIC_CARD.eid);
});

// ---------------------------------------------------------------------------
// C. Status-code contract. Cheap, and it regressed once already.
// ---------------------------------------------------------------------------

test.describe('vcf.php status contract', () => {
  test('a real card is 200', async ({ request }) => {
    const res = await request.get(
      `/vcf.php?company=${PUBLIC_CARD.slug}&email=${PUBLIC_CARD.localpart}`,
    );
    expect(res.status()).toBe(200);
  });

  test('an unknown company is 404, not 500', async ({ request }) => {
    // Regressed until 8 Aug 2026: an unknown slug threw into the catch-all and
    // answered 500, so every crawler on a dead tenant link logged a server
    // error. 404 is the contract.
    const res = await request.get(
      `/vcf.php?company=no-such-company-e2e-xyz&email=${PUBLIC_CARD.localpart}`,
    );
    expect(res.status(), 'unknown company').toBe(404);
  });

  test('an unknown contact is 404, not 500', async ({ request }) => {
    const res = await request.get(
      `/vcf.php?company=${PUBLIC_CARD.slug}&email=no-such-contact-e2e-xyz`,
    );
    expect(res.status(), 'unknown contact').toBe(404);
  });

  test('missing both params is 400', async ({ request }) => {
    const res = await request.get('/vcf.php');
    expect(res.status(), 'no params').toBe(400);
  });

  test('missing the contact param is 400', async ({ request }) => {
    const res = await request.get(`/vcf.php?company=${PUBLIC_CARD.slug}`);
    expect(res.status(), 'company but no contact').toBe(400);
  });

  test('missing the company param is 400', async ({ request }) => {
    const res = await request.get(`/vcf.php?email=${PUBLIC_CARD.localpart}`);
    expect(res.status(), 'contact but no company').toBe(400);
  });

  test('no vcf.php request answers 5xx', async ({ request }) => {
    // A 500 on any of these means the catch-all swallowed a real bug. Sweep
    // them together so a new 500 cannot hide behind a passing sibling.
    const urls = [
      '/vcf.php',
      `/vcf.php?company=${PUBLIC_CARD.slug}`,
      `/vcf.php?email=${PUBLIC_CARD.localpart}`,
      '/vcf.php?company=no-such-company-e2e-xyz&email=nobody',
      `/vcf.php?company=${PUBLIC_CARD.slug}&email=no-such-contact-e2e-xyz`,
      `/vcf.php?company=${PUBLIC_CARD.slug}&email=${PUBLIC_CARD.localpart}`,
      // Hostile shapes: these must be rejected, never crash.
      '/vcf.php?company=../../etc/passwd&email=x',
      "/vcf.php?company=%27%20OR%201%3D1--&email=x",
    ];
    const bad: string[] = [];
    for (const u of urls) {
      const res = await request.get(u);
      if (res.status() >= 500) bad.push(`${u} -> ${res.status()}`);
    }
    expect(bad, 'vcf.php requests that answered 5xx').toEqual([]);
  });
});
