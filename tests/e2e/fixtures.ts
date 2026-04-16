/**
 * Stable test fixtures for Cardify E2E tests.
 *
 * Override at CI time via env:
 *   KNOWN_CARD_SLUG=bhdoman
 *   KNOWN_CARD_EID=0dc7e708-bb76-41c5-913d-f37a03500d06
 *   KNOWN_CARD_NAME="Ali Al-Zaabi"
 *
 * Defaults point at a well-exercised production employee (Ali Al-Zaabi @ BHD Oman)
 * that has been live since launch. If this ever gets deactivated, bump the
 * defaults below and re-run.
 */
export const KNOWN_CARD = {
  slug: process.env.KNOWN_CARD_SLUG || 'bhdoman',
  eid: process.env.KNOWN_CARD_EID || '0dc7e708-bb76-41c5-913d-f37a03500d06',
  name: process.env.KNOWN_CARD_NAME || 'Ali Al-Zaabi',
};

export const cardPath = (slug = KNOWN_CARD.slug, eid = KNOWN_CARD.eid) =>
  `/${slug}/card/${eid}`;
