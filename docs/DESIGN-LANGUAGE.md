# Cardify Design Language

Codified from [/intro](../intro.php). Apply to every public marketing page, pricing page, landing page, and company portal splash. Where the BHD-teal overrides conflict, teal wins on ERP-integrated and portal surfaces, the intro palette wins on public/marketing surfaces.

## 1. Brand Voice

- **Pitch in one line**: "Design once, cards for everyone."
- **Value props, in order**: Free platform, pay only for prints. Made for Omani SMEs. Unlimited employees + templates.
- **Tone**: Plain, friendly, confident. No consulting jargon. Action verbs in CTAs ("Start Creating", "See How It Works", "Order Now"). Duration promises ("2 min setup", "30-sec signup", "Under 5 minutes").
- **Trust markers**: "Supporting Omani SME Companies" pill, "Made in Oman" footer, "Verified" print shops, Omani testimonial with named CEO.
- **FREE signal**: Red pill next to the badge. Green "FREE" stamps on every line item on the pricing card. One paid line ("Physical printing, Pay per order") in blue to anchor the monetisation story.

## 2. Color System

### 2.1 Core palette (as used on /intro)

| Role | Hex | Notes |
|---|---|---|
| Primary action | `#2563eb` (blue-600) | Buttons, step 1 accent, Physical printing card |
| Primary hover | `#1d4ed8` (blue-700) | |
| Primary shadow | `rgba(37, 99, 235, 0.25–0.40)` | Glow under primary CTA |
| Secondary | `#7c3aed` (purple-600) | Step 2 accent, gradient-text midpoint |
| Tertiary | `#db2777` (pink-600) | Step 3 accent, gradient-text endpoint |
| Success | `#16a34a` (green-600) | Step 4, FREE stamps, print shop card |
| Omani red | `#c8102e` | Omani SME section accent |
| Warning/star | `#f59e0b` (amber-500) | Star ratings |

### 2.2 Surface palette

| Role | Hex |
|---|---|
| Page bg | `#ffffff` |
| Section bg alt | gradient `from-gray-50 to-white` |
| Gray sections | `#f9fafb` (gray-50) |
| Borders | `#f3f4f6` (gray-100), active `#2563eb` |
| Body text | `#4b5563` (gray-600) |
| Heading | `#111827` (gray-900) |
| Footer | `#111827` bg, `#9ca3af` text |

### 2.3 Signature gradients

```css
/* Hero headline, step counter, scroll-progress bar */
background: linear-gradient(135deg, #2563eb 0%, #7c3aed 50%, #db2777 100%);

/* Final-CTA animated mesh (15s loop) */
background: linear-gradient(-45deg, #667eea, #764ba2, #6B8DD6, #8E37D7);
background-size: 400% 400%;

/* Demo card body, neutral */
background: linear-gradient(to bottom right, #1e293b, #334155, #0f172a);
/* slate-800 via slate-700 to slate-900 */

/* Omani SME section frame */
background: linear-gradient(to bottom right, #fef2f2, #ffffff, #f0fdf4);
/* red-50 via white to green-50, Omani flag nod */

/* Section header pill bg, per accent */
bg-blue-50, bg-green-50, bg-red-50, bg-purple-50, bg-amber-50
```

### 2.4 Ambient blur blobs (hero)

Three absolute-positioned 384x384px circles, `rounded-full`, `mix-blend-multiply`, `blur-3xl`, `opacity-70`, paired with 6s floating animation:

```html
<div class="absolute -top-40 -right-40 w-96 h-96 bg-blue-100 ... float"></div>
<div class="absolute top-40 -left-40 w-96 h-96 bg-purple-100 ... float-delayed"></div>
<div class="absolute -bottom-40 left-1/3 w-96 h-96 bg-pink-100 ... float"></div>
```

Plus a 3% opacity radial-dot grid at 40px pitch:

```css
background-image: radial-gradient(#000 1px, transparent 1px);
background-size: 40px 40px;
opacity: 0.03;
```

## 3. Typography

- **Font**: [Inter](https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap), weights 300/400/500/600/700/800/900. One typeface, nine weights.
- **Universal apply**: `* { font-family: 'Inter', sans-serif; }` on intro. Keep that rule page-scoped, do not let it stomp `<code>` or `<pre>` globally.
- **Scale (mobile → desktop)**

| Token | Mobile | Desktop | Weight | Usage |
|---|---|---|---|---|
| Display | `text-5xl` 48px | `text-7xl` 72px | 900 | Hero headline |
| H2 | `text-4xl` 36px | `text-5xl` 48px | 900 | Section title |
| H3 | `text-2xl` 24px | `text-2xl` 24px | 700 | Step/feature title |
| Lead | `text-xl` 20px | `text-2xl` 24px | 400 | Subheadline |
| Body | `text-base` 16px | `text-xl` 20px | 400 | Section copy |
| Small | `text-sm` 14px | `text-sm` 14px | 500 | Badges, captions |
| Micro | `text-xs` 12px / `text-[10px]` 10px | same | 500 | Card captions |

- **Tracking**: Default. `tracking-wider uppercase` + `text-[10px]` or `text-xs` for hairline eyebrow labels on demo cards.
- **Line-height**: `leading-tight` on display/H2, `leading-relaxed` on lead/body.
- **Gradient text**: only on the second line of Display headlines. Never on body or CTAs.
- **Numerals (OMR)**: `DM Mono` from [`assets/css/cardify-overrides.css`](../assets/css/cardify-overrides.css) for all OMR amounts (BHD house rule). `OMR 5.000` always 3-decimal.

## 4. Spacing & Layout

- **Container**: `max-w-7xl mx-auto px-4 sm:px-6 lg:px-8` everywhere.
- **Section padding**: `py-20` (compact), `py-24` (normal), `py-32` (marquee, hero / journey / features / CTA).
- **Vertical rhythm inside a section**: header block → content block. Header has `mb-16` or `mb-20`.
- **Grid gaps**: `gap-6` for card grids, `gap-8` for step grids, `gap-16` for 2-col feature rows.
- **Radii**:
  - `rounded-full` → pills, badges, avatars
  - `rounded-xl` (12px) → form chips, small cards
  - `rounded-2xl` (16px) → primary buttons, icon tiles, feature cards
  - `rounded-3xl` (24px) → step cards, hero preview, pricing card, large feature frames
- **Shadows (layered, colored for CTAs)**
  - Standard: `shadow-lg`, `shadow-xl`, `shadow-2xl`
  - Colored glow: `shadow-xl shadow-blue-500/30` on primary CTA, `shadow-blue-500/40` on hover
  - Step icon tiles: `shadow-lg shadow-{color}-500/30` matching the step color
  - Card hover: `shadow-2xl` + `-translate-y-1` (buttons) or `-translate-y-2` + `scale-[1.02]` (cards)

## 5. Motion System

All timing uses `cubic-bezier(0.4, 0, 0.2, 1)` unless noted. Durations 200–800ms. Animations are additive, page is fully readable with JS disabled (content is visible by default, GSAP only animates entrance).

### 5.1 CSS keyframes (define once, reuse)

```css
@keyframes gradientBG {
  0%   { background-position:   0% 50%; }
  50%  { background-position: 100% 50%; }
  100% { background-position:   0% 50%; }
}
.animated-bg {
  background: linear-gradient(-45deg, #667eea, #764ba2, #6B8DD6, #8E37D7);
  background-size: 400% 400%;
  animation: gradientBG 15s ease infinite;
}

@keyframes float {
  0%, 100% { transform: translateY(0)     rotate(0); }
  50%      { transform: translateY(-20px) rotate(2deg); }
}
.float         { animation: float 6s ease-in-out infinite; }
.float-delayed { animation: float 6s ease-in-out infinite; animation-delay: -3s; }

@keyframes pulse-ring {
  0%   { transform: scale(0.8); opacity: 1; }
  100% { transform: scale(2);   opacity: 0; }
}
.pulse-ring::before {
  content: ''; position: absolute; inset: 0;
  border-radius: inherit; border: 2px solid currentColor;
  animation: pulse-ring 2s cubic-bezier(0.4, 0, 0.2, 1) infinite;
}

@keyframes shine {
  0%   { transform: translateX(-100%) rotate(30deg); }
  100% { transform: translateX( 100%) rotate(30deg); }
}
.shine { position: relative; overflow: hidden; }
.shine::after {
  content: ''; position: absolute; top: -50%; left: -50%;
  width: 200%; height: 200%;
  background: linear-gradient(to right,
    rgba(255,255,255,0) 0%,
    rgba(255,255,255,.3) 50%,
    rgba(255,255,255,0) 100%);
  transform: rotate(30deg);
  animation: shine 3s infinite;
}

@keyframes blink {
  0%, 50%    { opacity: 1; }
  51%, 100%  { opacity: 0; }
}
.typing-cursor::after { content: '|'; animation: blink 1s infinite; }
```

### 5.2 3D card perspective (used on hero demo)

```css
.card-3d       { perspective: 1000px; }
.card-3d-inner { transition: transform .8s; transform-style: preserve-3d; }
.card-3d:hover .card-3d-inner { transform: rotateY(10deg) rotateX(5deg); }
```

### 5.3 Interactive states

| Element | Rest | Hover |
|---|---|---|
| Primary CTA | `shadow-blue-500/30` | `-translate-y-1`, `shadow-blue-500/40` |
| Arrow icon inside CTA | static | `translate-x-1` |
| Feature card | `border-gray-100` | `border-{accent}-200`, `shadow-xl` |
| Step card | default | `-translate-y-2 scale-[1.02]`, `border-{accent}-500`, `shadow-{accent}-500/25` |
| Step number tile | default | `scale-110` (via `group-hover:`) |
| Nav link | `text-gray-600` | `text-blue-600` |
| Interactive cursor | default | `scale-105` |

### 5.4 GSAP + ScrollTrigger choreography

Required libs:

```html
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>
```

Four reveal classes, all use `toggleActions: "play none none reverse"` and trigger at `top 85%`:

| Class | Animation | Ease |
|---|---|---|
| `.reveal-up` | `y: 60 → 0`, opacity 0 → 1, stagger `i * 0.05s` | `power3.out`, 0.8s |
| `.reveal-left` | `x: -60 → 0`, opacity 0 → 1 | `power3.out`, 0.8s |
| `.reveal-right` | `x: 60 → 0`, opacity 0 → 1 | `power3.out`, 0.8s |
| `.reveal-scale` | `scale: 0.8 → 1`, opacity 0 → 1 | `back.out(1.7)`, 0.8s |

**Golden rule**: always `gsap.from()`, never `gsap.to()` for entrances. Elements must render at final state in CSS, so a JS or network failure still shows content. No `opacity: 0` in stylesheets.

**Scroll progress bar** (top of viewport, 4px tall, brand gradient, z-100):

```js
gsap.to("#scrollProgress", {
  scaleX: 1, ease: "none",
  scrollTrigger: { trigger: "body", start: "top top", end: "bottom bottom", scrub: 0.3 }
});
```

**Journey progress** (4-step line fills as user scrolls through the section):

```js
ScrollTrigger.create({
  trigger: "#journey",
  start: "top center", end: "bottom center",
  onUpdate: (self) => {
    document.getElementById('journeyProgress').style.width =
      Math.min(self.progress * 100, 100) + '%';
  }
});
```

**Navbar** switches from `bg-white/90 backdrop-blur-xl` to `bg-white shadow-lg` after `scrollY > 100`.

**Smooth anchor scroll** with 80px offset for fixed nav.

## 6. Component Library

### 6.1 Status badge (eyebrow pill)

```html
<div class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-blue-50 to-purple-50 rounded-full border border-blue-100">
  <span class="relative flex h-2 w-2">
    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
    <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
  </span>
  <span class="text-blue-700 font-medium text-sm">Supporting Omani SME Companies</span>
  <span class="text-xs px-2 py-0.5 bg-red-500 text-white rounded-full font-bold">FREE</span>
</div>
```

Variants: `bg-blue-50 text-blue-600`, `bg-green-50 text-green-600`, `bg-red-50 text-red-600`, `bg-purple-50 text-purple-600`, `bg-amber-50 text-amber-600`, each paired with a Font Awesome icon.

### 6.2 Primary CTA

```html
<a class="group inline-flex items-center justify-center gap-3 px-8 py-4
          bg-blue-600 hover:bg-blue-700 text-white text-lg font-semibold
          rounded-2xl shadow-xl shadow-blue-500/30 transition-all
          hover:-translate-y-1 hover:shadow-2xl hover:shadow-blue-500/40">
  <span>Start Creating, It's Free</span>
  <i class="fa-solid fa-arrow-right group-hover:translate-x-1 transition-transform"></i>
</a>
```

Secondary CTA uses `bg-white border-2 border-gray-200 hover:border-blue-300` with a colored Font Awesome icon, same radius/padding.

### 6.3 Benefit pill row

Three `rounded-full` pills directly under CTA, each `bg-{color}-50 text-{color}-700`, `fa-check-circle` + label.

### 6.4 Step card (4-step journey)

Structure per step:
- Colored numeric tile: `w-20 h-20 bg-gradient-to-br from-{accent}-500 to-{accent}-600 rounded-2xl` with the digit in `text-3xl font-black text-white`.
- Green hover-check badge at top-right, `opacity-0 group-hover:opacity-100`.
- Title `text-2xl font-bold`, 3-line description.
- Accent metadata row: icon + text in `text-{accent}-600 font-semibold`.
- Mini illustration in a `bg-gray-50 rounded-xl p-4` footer block (abstract shapes, never real UI).

Accents by step: Blue (Register), Purple (Design), Pink (Add employees), Green (Generate/Print). Transition delay staggers `0s, 0.1s, 0.2s, 0.3s`.

### 6.5 Feature row (alternating 2-col)

- Left 50% or right 50% copy block: eyebrow pill → `text-4xl/5xl font-black` headline with a single colored word → `text-xl` lede → 4-item `fa-check` bulleted list.
- Opposite side: a supporting visual in a `rounded-3xl p-8 shadow-xl` frame with a pale gradient fill. Include one absolute-positioned `float`ing badge outside the frame for depth.
- Alternate `reveal-left` / `reveal-right` per row. On mobile, flip `order-2/order-1` so the visual comes after the copy.

### 6.6 Feature grid (6 tiles)

`grid md:grid-cols-2 lg:grid-cols-3 gap-6`. Each tile: `bg-white rounded-2xl p-6 border border-gray-100 hover:border-{accent}-200 hover:shadow-xl`. Icon in `w-12 h-12 bg-{accent}-100 rounded-xl` tile, `group-hover:scale-110`. Stagger with inline `transition-delay: 0.05 * i` on each card.

### 6.7 Pricing card

White `rounded-3xl p-8 shadow-2xl border border-gray-100`, centered. Header pill ("Simple Pricing") → hero price `text-3xl font-black` `OMR 0` → "Platform access forever". Body: 5 line items in `bg-gray-50 rounded-xl`, one final blue-bordered line for the paid item. Single full-width `bg-blue-600` CTA at the bottom. Decorative blurred blobs absolute-positioned outside the card corners.

### 6.8 Testimonial

Single centered blockquote, `text-2xl sm:text-3xl font-bold`, curly quotes. Below: initial avatar circle `w-12 h-12 bg-blue-600` with first letter, name in `font-bold`, role in `text-gray-500 text-sm`. Keep it short, one sentence.

### 6.9 Final CTA (mesh gradient)

Full-bleed section, `animated-bg` class with 15s gradient loop, `bg-black/10` overlay. White text. White-on-white secondary CTA, `bg-white hover:bg-gray-100 text-gray-900 rounded-2xl`. Three `fa-check-circle` assurance items in `text-white/70 text-sm` underneath.

### 6.10 Scroll indicator (hero only)

Mouse icon, `w-6 h-10 border-2 border-gray-300 rounded-full` with a 1.5px dot that `animate-bounce`s. Pair with the text "Scroll to explore".

## 7. Iconography

- **Library**: [Font Awesome 6.5.1](https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css) solid. Load via CDN. No Duotone mixed in on marketing pages.
- **SVG exception**: the Omani "made in Oman" flag icon is always inline SVG (stroke currentColor) at `w-4 h-4` or `w-7 h-7` to pick up parent color.
- **Sizes**: `text-xs` in chips, `text-xl` in feature tiles, `text-2xl` in framed visuals, `text-3xl` in large decor.
- **Semantic pairing**:
  - Free / pricing → `fa-gift`, `fa-tag`, `fa-hand-holding-dollar`, `fa-infinity`
  - Speed → `fa-bolt`, `fa-clock`, `fa-rocket`
  - Print → `fa-print`, `fa-store`, `fa-truck-fast`, `fa-cart-shopping`
  - Trust → `fa-check`, `fa-check-circle`, `fa-star`
  - Design → `fa-palette`, `fa-wand-magic-sparkles`, `fa-layer-group`
  - Identity → `fa-building`, `fa-users`, `fa-door-open`
  - Motion → `fa-arrow-right`, `fa-play-circle`, `fa-route`
  - Scan → `fa-qrcode`, `fa-chart-line`, `fa-language`

## 8. Content Choreography

The intro is a 7-act narrative. Keep this order on any long-form public page.

1. **Hero** (min-h-screen)
   - Trust badge + FREE stamp
   - Gradient display headline, 2 lines, promise + audience
   - Lede, 1 sentence, explains the "free platform, pay for prints" model
   - Dual CTA (primary + "See how it works" anchor)
   - 3 benefit pills
   - Before/After visual: one template → three cards, with a pulsing arrow between

2. **Journey** (py-32, gray-50 to white)
   - Eyebrow, headline, lede
   - Horizontal progress line (desktop) or vertical stack (mobile)
   - 4 step cards, each with color, number tile, title, description, meta, mini-illustration
   - Progress line fills as user scrolls past

3. **Feature 1, Print integration** (green)
   - Copy left, print-shop card right
   - 4 check bullets
   - Floating "Fast Delivery" badge outside the frame

4. **Feature 2, Omani SMEs** (red)
   - Copy right, Omani-themed value card left (red→white→green mesh)
   - 4 check bullets: bilingual, AI Arabic, local print, local support
   - 3 mini icon tiles inside the card: Built for Oman / 30-sec signup / Local support

5. **Feature 3, Free platform** (blue)
   - Copy left, pricing card right
   - 2 stat tiles (Unlimited, Pay Per Print)
   - Pricing card lists 4 FREE lines + 1 "Pay per order" line

6. **Feature grid** (py-24, gray-50)
   - 6 tiles: Multi-Department, Smart QR, Bilingual, Batch Generation, Self-Service, Analytics

7. **Testimonial → Final CTA → Footer**
   - 1 customer quote
   - Animated mesh CTA block with 3 reassurance checks
   - 4-column dark footer with "Made in Oman" lockup

## 9. Platform Mental Model (what to say, not just how)

These are the canonical product claims. Keep copy consistent with these across every surface.

| Claim | Truth |
|---|---|
| Free to use | Platform access, unlimited employees, unlimited templates, unlimited digital cards, QR codes, analytics are all OMR 0 forever. |
| Pay only for printing | Revenue is per physical print order through verified print shops. |
| One template, many cards | Admin designs 1 card template, system generates N personalised cards for N employees. |
| Self-service | Employees can request cards through a company-branded portal. Admin approves + generates. |
| Bilingual | Full Arabic + English RTL, with AI-assisted Arabic translation. |
| Local network | Verified Omani print shops with star ratings and delivery across Oman. |
| Trackable | QR codes on every card, scan analytics in dashboard. |
| Fast | 2 min signup, 5 min to first card. |

## 10. Accessibility Baseline

- Color contrast: body gray-600 on white = 7.5:1. Gradient text needs a solid-color fallback at `<sm` breakpoints.
- Focus rings: do not remove. Tailwind default is fine, optionally upgrade to `focus-visible:ring-2 ring-blue-500 ring-offset-2`.
- Motion: wrap decorative animations (`.float`, `.animated-bg`, `.shine`, `.pulse-ring`) in `@media (prefers-reduced-motion: no-preference)`. Essential animations (step progress, scroll progress) can stay.
- Alt text: every `<img>` has one. Decorative blur blobs are `<div>`, correct.
- Keyboard: all CTAs are `<a>` or `<button>`, hover transforms use `transition-all` so they also animate on `:focus-visible`.
- RTL: when Arabic is active, mirror feature-row `order-1/order-2` swaps, flip `translate-x` on stacked card demo, add `.is-rtl` on the page root.

## 11. Implementation Recipe for New Pages

1. Start from [intro.php](../intro.php) skeleton (nav, scroll progress bar, footer).
2. Load Inter, Font Awesome 6.5.1, GSAP + ScrollTrigger.
3. Keep `<body class="bg-white antialiased overflow-x-hidden">`.
4. Copy the 4 `.reveal-*` classes and the full GSAP initialisation block. Do not add opacity-0 in CSS.
5. Pick sections from §8. Drop in components from §6.
6. Match accent colors to section purpose (blue/purple/pink/green/red/amber).
7. Register the page in `$pageMap` in [company_admin.php](../company_admin.php) if it's an admin surface.
8. Use `cardify-dl-btn` + DM Mono for OMR amounts (house rule, trumps Tailwind blue utility soup on billing surfaces).

## 12. What the Intro Page Does Not Do (and why)

- **No dark mode.** Marketing pages stay light-only for brand punch.
- **No video background.** Blur blobs + mesh gradient carry the motion budget.
- **No pricing tiers.** One "OMR 0" hero price, one "Pay per order" line. Any attempt to add tiers defeats the free-platform promise.
- **No client logo wall.** Replaced by 1 named testimonial from the CEO of an actual customer (BHD Group). Higher signal than a logo soup.
- **No chat widget.** Local support is promised in copy, delivered by WhatsApp (Dardasha) not a live widget.
- **No cookie banner by default.** Add only when regulation demands it, keep it `fixed bottom-4 left-4 rounded-2xl` to match the radius system.
