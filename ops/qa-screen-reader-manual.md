# Screen Reader QA — VoiceOver + NVDA

Cat U action 494 (assistive-tech portion). 10 minutes per reader.
The semantic checks in `tests/e2e/a11y-semantics.spec.ts` handle the
cheap regressions; this walk checks what only a human ear can judge.

## 494A — VoiceOver (macOS / iOS)

**macOS**, Cmd+F5 toggles VoiceOver. For mobile iOS, Settings →
Accessibility → VoiceOver.

Pick one URL per category + both locales:

- `https://cardify.om/`                     (landing, EN)
- `https://cardify.om/ar/`                  (landing, AR, RTL)
- `https://cardify.om/pricing`              (tiers)
- `https://cardify.om/bhdoman/card/0dc7e...` (live employee card)
- `https://cardify.om/contact`              (form)

Per page:

1. **VO+A** to read from the top. Expect:
   - Heading structure announced: "heading level 1, <name>", "heading level 2, …".
   - Skip-to-content link announced first if present.
   - No "image image image" storm (alt text must be meaningful OR
     aria-hidden).
2. **VO+U → Landmarks**. Expect: banner, main, contentinfo, and at
   least one navigation landmark.
3. **VO+U → Form Controls**. Each input field announces its purpose
   (e.g. "edit text, Your name, required").
4. **VO+Cmd+L**: next link. Step through 10 links. Each must
   announce a meaningful name — no "link" alone.
5. Arabic pages: VoiceOver should announce in Arabic. If it reads
   English labels, the `<html lang="ar">` is wrong.

## 494B — NVDA (Windows)

**Install:** https://www.nvaccess.org/download (free).

Same 5 URLs + same 5 checks, keyboard sequences:

- **Insert+Down**: "say all" from top. Same heading + alt-text checks.
- **Insert+F7**: elements dialog → landmarks tab.
- **Insert+F5** (NVDA+F5): heading list.
- **K**: cycle through links; each must have a name.
- Arabic: NVDA language profile must switch automatically. If it
  reads right-to-left text left-to-right, our BiDi marks are off.

## Regression triage

- Heading level mismatch (h3 before h2) → fixed by flattening the
  outline in the offending template.
- "image image" storm → add alt="" or aria-hidden="true" to
  decorative spacers; `tests/e2e/a11y-semantics.spec.ts` already
  flags missing alt attribute.
- Form field announces "edit text" with no name → the <label for=>
  isn't wired. `tests/e2e/a11y-semantics.spec.ts` catches this too.
- Buttons announcing only "button" → add aria-label or inner text.

## Log

`ops/qa-screen-reader-log.md` (create on first pass): date | tool
(VO macOS / VO iOS / NVDA) | page | tester initials | issues.
