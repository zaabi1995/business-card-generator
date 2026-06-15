# Cardify Design System

The locked visual language for Cardify (cardify.om). This is the **elevation of the existing brand**, not a new one: same teal (`#009bc1`), same type stack (Sora / Plus Jakarta Sans / Noto Sans Arabic via fonts.bhd.om). What changes is discipline, restraint, and a single hero object.

Direction: **Quiet Teal base + Wallet-Pass hero** (approved 15 Jun 2026). Reference rivals: CardSpace (premium restraint), CardPass (wallet-pass-as-hero, casual Arabic). Cardify wins on Arabic-first depth + Oman trust.

**Builds on the existing brand layer** — do not replace it: `assets/css/cardify-brand-2026.css` already defines the cyan scale `--cf-50…--cf-900` (anchor `--cf-500: #009bc1`), remaps Tailwind `blue-*`→cyan on `.cardify-brand` pages, and sets Sora headings. Arabic loads as **IBM Plex Sans Arabic** (header `ui-header.php`), not Noto. The token names below map to the `--cf-*` scale; extend that file, don't fork it.

## Principles
1. **One accent.** Teal is the only brand colour. Retire green (`#16a34a`) as a primary CTA. No multi-colour banding.
2. **One hero object.** The wallet pass / digital card is the star on every key surface (CardPass lesson). No busy 3-card clusters, no placeholder "John Doe" — real Omani names + real client logos.
3. **Air over density.** Off-white canvas, generous whitespace, restrained sections. Kill full-width teal bands; teal appears as accent, the hero object, and hairlines.
4. **Bilingual by default, RTL first-class.** Every surface reads correctly in Arabic. Latin display in Sora, Arabic display in Noto Sans Arabic, both heavy.
5. **Quiet motion.** Slow reveal, soft shadows, no bounce. Apple-grade calm.

## Color tokens
```
--teal:        #009bc1   /* brand, primary CTA, accent */
--teal-deep:   #067a98   /* hover, gradients */
--teal-ink:    #053b49   /* deep pass gradient end, dark teal text */
--ink:         #0c1418   /* primary text */
--muted:       #5b6b72   /* secondary text */
--canvas:      #f7f8f7   /* warm off-white page bg */
--surface:     #ffffff   /* cards, passes-on-light */
--line:        #e7ebea   /* hairlines, borders */
--ok:          #16a34a   /* success/status ONLY, never primary CTA */
```
Pass gradient: `linear-gradient(150deg, var(--teal), var(--teal-ink))`.
Shadows: cards `0 40px 80px -30px rgba(5,59,73,.45)`; buttons `0 8px 24px -8px rgba(0,155,193,.6)`.

## Type
Load via fonts.bhd.om (never googleapis): `Sora:400;500;600;700;800` · `Plus Jakarta Sans:300;400;500;600;700` · `Noto Sans Arabic:400;500;600;700;800`.
- **Display (Latin):** Sora 800, letter-spacing -0.02em. H1 ~56–64px desktop / 34–40px mobile.
- **Display (Arabic):** Noto Sans Arabic 800, line-height ~1.25 (Arabic needs more leading).
- **Body / UI:** Plus Jakarta Sans 400–600, 16–18px, line-height 1.6.
- **Eyebrow / label:** Sora 700, 13px, letter-spacing 0.12em, uppercase, teal.
- Phone/email/URL pinned `dir=ltr` even in RTL (existing cardify rule 30).

## Spacing & radius
8px base scale. Section padding 7vw horizontal. Card radius 22–24px, buttons 14px, swatches/avatars 10–18px. Hairline 1px `--line`.

## Components
- **Buttons:** primary = teal fill, white text, radius 14px, 14×24px padding, teal glow shadow. Secondary = white fill, `--line` border, ink text. 44px min hit target.
- **Wallet pass (hero object):** teal gradient, `LIVE` pill top-right (rgba white .16), company eyebrow (Sora 700, tracking .16em, 80% opacity), name (Sora 700 24–28px), title, QR bottom-left. This is the canonical hero across marketing, onboarding, card pages.
- **Brand-colour swatch picker:** white pill, label + 5 swatches (rainbow / blue / black / teal-selected / a second tone), selected = teal ring `#b9e3ee` offset 2px. Used in editor + hero to show personalization.
- **Digital card (light variant):** white surface, teal gradient header band, rounded avatar overlapping band, name + title, action row (Save contact / WhatsApp / QR) as equal soft-teal chips.
- **Stat block:** Sora 800 number in teal + muted label; Arabic uses Arabic-Indic numerals.
- **Section rhythm:** eyebrow (teal) → H1 (bilingual) → body (muted) → CTA pair → proof row (dotted teal markers). No more than one teal-tinted radial glow per section.

## Hero pattern (canonical)
Two-column: left = eyebrow + bilingual H1 (Latin line + Arabic line, teal emphasis on the second half) + muted subhead + CTA pair + proof markers. Right = the wallet pass hero + swatch picker beneath it + "Add to Apple Wallet · محفظة آبل" caption. Background = `--canvas` with a single teal radial glow at 88% 12%, 10% opacity. RTL flips columns; Arabic becomes primary headline.

## What this replaces (current-site fixes baked in)
- Heavy full-width teal section bands → restrained accent use.
- Busy floating 3-card hero cluster → one wallet pass.
- Placeholder names (John Doe / Alex Kim) → real Omani names + real tenant logos.
- Green as primary CTA → teal; green demoted to status only.
- Scattered inline hexes → these tokens (and a populated `tailwind.config.js theme.extend`).

## Mockup reference
Approved heroes rendered at `/tmp/cardify-mockups/heroes.html` (Direction A canvas + Direction B wallet/swatch hero = the blend). Canonical blended hero screenshot is the build target for Phase 1.
