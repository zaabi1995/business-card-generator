/**
 * Stable test fixtures for Cardify E2E tests.
 *
 * Override at CI time via env:
 *   KNOWN_CARD_SLUG=bhdoman
 *   KNOWN_CARD_EID=<uuid of a card that will never be deactivated>
 *   KNOWN_CARD_NAME="<name printed on that card>"
 *
 * READ THIS BEFORE CHANGING THE DEFAULT AGAIN.
 *
 * This suite asserts against LIVE production data, so any card it names can be
 * deactivated by ordinary business activity, and when that happens roughly 15
 * tests turn red every night with 404s and 400s that look like product
 * breakage. It has now happened twice: `muhammed.ali` was deactivated and
 * emailed false failures for about three weeks, the default was moved here to
 * fix it, and the replacement card
 * (0dc7e708-bb76-41c5-913d-f37a03500d06) is itself returning 400 from
 * card-pdf.php as of 3 Aug 2026.
 *
 * Swapping in another real employee only restarts that clock. The durable fix
 * is a card that exists solely to be tested and is never deactivated, supplied
 * via KNOWN_CARD_EID as a repository variable.
 *
 * Until that exists, assertFixtureAlive() below turns a dead fixture into ONE
 * clear message naming the cause, instead of fifteen assertion failures that
 * each look like a different bug.
 */
export const KNOWN_CARD = {
  slug: process.env.KNOWN_CARD_SLUG || 'bhdoman',
  // The owner's own card. Verified live 3 Aug 2026: card-pdf.php?i=ali-bhd
  // returns 200, application/pdf, 335KB starting %PDF- (the vector path).
  //
  // Chosen because it is the one card in the system nobody will deactivate.
  // The previous two defaults were ordinary staff cards and both were
  // deactivated in normal business, each time turning ~15 tests red for weeks.
  //
  // Identifiers here are employee slugs (employees.id), not UUIDs. The old
  // default was a UUID from a superseded schema, which is why it returned 400
  // rather than 404: the lookup never even recognised the shape.
  eid: process.env.KNOWN_CARD_EID || 'ali-bhd',
  name: process.env.KNOWN_CARD_NAME || 'Ali Adnan Haider Darwish',
};

/**
 * The card used by identity-chain.spec.ts, which walks the whole PUBLIC surface
 * of one real card: page -> vCard -> QR -> wallet -> card-image -> card-pdf.
 *
 * That chain needs one thing KNOWN_CARD does not carry: the email LOCALPART,
 * because the pretty public URL is /<slug>/<localpart>.vcf and the localpart is
 * not derivable from the employee id. So this fixture names all three tokens
 * (slug, id, localpart) rather than reusing KNOWN_CARD and guessing.
 *
 * Verified live 8 Aug 2026:
 *   /adnan/card/fcb71d11da10e8ba5fd428ed  301 -> https://adnan.cardify.om/jarwish9
 *   /adnan/jarwish9.vcf                   200 text/vcard, 547 bytes
 *   /wallet_apple.php?i=<id>              200 application/vnd.apple.pkpass
 *   /card-image.php?i=<id>                200 image/png
 *   /card-pdf.php?i=<id>                  200 application/pdf
 *
 * Same standing caveat as KNOWN_CARD above: this is live production data and a
 * deactivation turns the whole spec red. assertPublicCardAlive() below reduces
 * that to one legible message. Override via env for a purpose-built test card.
 */
export const PUBLIC_CARD = {
  slug: process.env.PUBLIC_CARD_SLUG || 'adnan',
  eid: process.env.PUBLIC_CARD_EID || 'fcb71d11da10e8ba5fd428ed',
  localpart: process.env.PUBLIC_CARD_LOCALPART || 'jarwish9',
  name: process.env.PUBLIC_CARD_NAME || 'Adnan Haider Darwish',
  // The last token of `name`. vCard N puts the family name FIRST, and the
  // "an Arabic-only card had no readable name in Contacts" regression is
  // exactly a lost/empty family name, so the spec asserts this value.
  family: process.env.PUBLIC_CARD_FAMILY || 'Darwish',
};

/** Public .vcf URL for a card: /<slug>/<localpart>.vcf */
export const vcfPath = (
  slug = PUBLIC_CARD.slug,
  localpart = PUBLIC_CARD.localpart,
) => `/${slug}/${localpart}.vcf`;

/**
 * Same contract as assertFixtureAlive(), for PUBLIC_CARD: turn a deactivated
 * card into ONE message that names the cause, instead of a dozen 404s that
 * each read like a different product bug.
 */
export async function assertPublicCardAlive(request: {
  get: (url: string) => Promise<{ status: () => number }>;
}): Promise<void> {
  const res = await request.get(vcfPath());
  const status = res.status();
  if (status === 200) return;
  throw new Error(
    [
      `Fixture card is not usable: ${vcfPath()} returned ${status}.`,
      'This is almost certainly a deactivated or deleted card, not a bug in the site.',
      'Set PUBLIC_CARD_SLUG / PUBLIC_CARD_EID / PUBLIC_CARD_LOCALPART / PUBLIC_CARD_NAME',
      'to a card kept alive specifically for testing.',
    ].join(' '),
  );
}

/**
 * A syntactically-valid UUIDv4 that is deliberately unassigned in production.
 * Used by the qr.php / claim-lead.php / card_click.php negative-path tests
 * (must 404 / 400 rather than 500). If this ever accidentally gets assigned
 * to a real employee, any of a zillion other all-zero-ish UUIDs will do.
 */
export const BAD_UUID =
  process.env.BAD_UUID || '00000000-0000-0000-0000-000000000000';

export const cardPath = (slug = KNOWN_CARD.slug, eid = KNOWN_CARD.eid) =>
  `/${slug}/card/${eid}`;

/**
 * Fail fast and legibly when the fixture card no longer exists.
 *
 * A dead fixture is a DATA problem, not a product regression, and the two must
 * not look the same in an inbox. Call this in a beforeAll: the suite then says
 * "the card this suite points at is gone, set KNOWN_CARD_EID" once, rather than
 * reporting a spray of 404s that reads like the site is broken.
 */
export async function assertFixtureAlive(request: {
  get: (url: string) => Promise<{ status: () => number }>;
}): Promise<void> {
  const res = await request.get(`/card-pdf.php?i=${KNOWN_CARD.eid}`);
  const status = res.status();
  if (status === 200) return;
  throw new Error(
    [
      `Fixture card is not usable: /card-pdf.php?i=${KNOWN_CARD.eid} returned ${status}.`,
      'This is almost certainly a deactivated or deleted card, not a bug in the site.',
      'Set KNOWN_CARD_EID (and KNOWN_CARD_SLUG / KNOWN_CARD_NAME) to a card that is',
      'kept alive specifically for testing. See the note at the top of this file.',
    ].join(' '),
  );
}
