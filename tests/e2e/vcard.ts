/**
 * A minimal, INDEPENDENT vCard reader for the E2E suite.
 *
 * WHY NOT AN npm vCard PARSER
 * ---------------------------
 * The properties this suite has to prove are BYTE properties: no content line
 * over 75 octets, no fold point inside a UTF-8 sequence, CRLF terminators. A
 * library parses those away, it hands back unfolded strings and the very
 * defects we are testing for become invisible. (A naive substr() fold produces
 * lines a tolerant parser still reads correctly, while iOS Contacts shows
 * replacement characters.) A parser would also add the first runtime dependency
 * to a repo whose only devDependency is @playwright/test.
 *
 * So this file works on the raw Buffer and unfolds per RFC 2426 section 2.6 in
 * about fifteen lines. It is written from the RFC, not from includes/VCF.php or
 * includes/VCardRfc.php, so fold-here -> unfold-there round-tripping is real
 * evidence rather than the same bug agreeing with itself. Same rationale the
 * repo's own tests/php/vcf_test.php gives for hand-rolling unfold().
 */

/** RFC 2426 section 2.6: a content line SHOULD NOT be longer than 75 octets. */
export const MAX_OCTETS = 75;

export interface VCard {
  /** Lines exactly as they arrived on the wire, split on CRLF, no terminators. */
  physical: Buffer[];
  /** Content lines after unfolding, decoded as UTF-8. */
  logical: string[];
  /** Physical lines that are NOT valid UTF-8 on their own. Must be empty. */
  invalidUtf8: number[];
  /** Physical lines over MAX_OCTETS. Must be empty. */
  overLength: number[];
  /** True when at least one line was actually folded (a continuation exists). */
  hasFold: boolean;
  /** Raw bytes, for terminator assertions. */
  raw: Buffer;
}

/**
 * Unfold + decode, and record the byte-level violations on the way through.
 *
 * Unfolding rule: remove every CRLF that is followed by a single space or tab,
 * together with that one whitespace character. Everything else is content.
 */
export function parseVCard(raw: Buffer): VCard {
  const physical = splitCrlf(raw);

  const invalidUtf8: number[] = [];
  const overLength: number[] = [];
  physical.forEach((line, i) => {
    if (line.length > MAX_OCTETS) overLength.push(i);
    if (!isValidUtf8(line)) invalidUtf8.push(i);
  });

  // Unfold: a line starting with a single SP or HTAB continues the previous one.
  const logical: string[] = [];
  let hasFold = false;
  for (const line of physical) {
    const isContinuation =
      line.length > 0 && (line[0] === 0x20 || line[0] === 0x09);
    if (isContinuation && logical.length > 0) {
      hasFold = true;
      // Drop exactly one leading whitespace octet, per the RFC. Anything beyond
      // that first octet is content and must survive.
      logical[logical.length - 1] += line.subarray(1).toString('utf8');
    } else {
      logical.push(line.toString('utf8'));
    }
  }

  return { physical, logical, invalidUtf8, overLength, hasFold, raw };
}

/** Split on CRLF and drop a single trailing empty segment. */
function splitCrlf(raw: Buffer): Buffer[] {
  const out: Buffer[] = [];
  let start = 0;
  for (let i = 0; i + 1 < raw.length; i++) {
    if (raw[i] === 0x0d && raw[i + 1] === 0x0a) {
      out.push(raw.subarray(start, i));
      i++;
      start = i + 1;
    }
  }
  if (start < raw.length) out.push(raw.subarray(start));
  return out;
}

/**
 * Strict UTF-8 check on a single physical line.
 *
 * This is the assertion that catches a fold landing mid-sequence: the halves of
 * a split 2-octet Arabic letter are each invalid UTF-8 on their own, even though
 * the concatenation is fine. TextDecoder with fatal:true is the check; Buffer's
 * own toString() silently substitutes U+FFFD and would hide the bug.
 */
function isValidUtf8(buf: Buffer): boolean {
  try {
    new TextDecoder('utf-8', { fatal: true }).decode(buf);
    return true;
  } catch {
    return false;
  }
}

/**
 * All values for a property name, e.g. prop(card, 'FN') or prop(card, 'TEL').
 * Matches the name before any ';' parameters and before the ':' value.
 * Values are returned still vCard-escaped; use unescapeValue() when comparing
 * against human text.
 */
export function prop(card: VCard, name: string): string[] {
  const want = name.toUpperCase();
  const out: string[] = [];
  for (const line of card.logical) {
    const colon = line.indexOf(':');
    if (colon < 0) continue;
    const semi = line.indexOf(';');
    const end = semi >= 0 && semi < colon ? semi : colon;
    if (line.slice(0, end).toUpperCase() === want) {
      out.push(line.slice(colon + 1));
    }
  }
  return out;
}

/** First value of a property, or '' when absent. */
export function prop1(card: VCard, name: string): string {
  return prop(card, name)[0] ?? '';
}

/**
 * Split a structured value (N, ADR) on UNESCAPED semicolons. A '\;' inside a
 * component is literal data and must not create a new component.
 */
export function components(value: string): string[] {
  const out: string[] = [];
  let cur = '';
  for (let i = 0; i < value.length; i++) {
    const ch = value[i];
    if (ch === '\\' && i + 1 < value.length) {
      cur += ch + value[i + 1];
      i++;
    } else if (ch === ';') {
      out.push(cur);
      cur = '';
    } else {
      cur += ch;
    }
  }
  out.push(cur);
  return out;
}

/** Reverse the RFC 2426 escaping, so a value can be compared to human text. */
export function unescapeValue(value: string): string {
  let out = '';
  for (let i = 0; i < value.length; i++) {
    if (value[i] === '\\' && i + 1 < value.length) {
      const next = value[i + 1];
      out += next === 'n' || next === 'N' ? '\n' : next;
      i++;
    } else {
      out += value[i];
    }
  }
  return out;
}
