<?php
/**
 * Cardify, Design Showcase
 *
 * Single-page design-system catalog. Shows the real Tailwind / Flowbite
 * tokens, colors, components and patterns Cardify uses across the site,
 * with click-to-copy hex values, live modal / drawer / toast demos, and
 * a sticky table of contents.
 *
 * Route: cardify.om/design-showcase (via .htaccess rewrite) or
 *        cardify.om/design-showcase.php
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

$pageTitle = 'Design System, Cardify';
$pageDescription = 'The Cardify design language: colors, typography, components, and patterns used across cardify.om.';
$canonicalUrl = 'https://cardify.om/design-showcase';
$showNavigation = true;
$metaRobots = 'noindex, nofollow'; // internal reference page
$bodyClass = 'bg-white';

// ----- data -----
$brandSwatches = [
    ['name' => 'blue-50',  'hex' => '#eff6ff', 'bg' => 'bg-blue-50',  'text' => 'text-gray-900', 'use' => 'Soft tint / hover surface'],
    ['name' => 'blue-100', 'hex' => '#dbeafe', 'bg' => 'bg-blue-100', 'text' => 'text-gray-900', 'use' => 'Chip background'],
    ['name' => 'blue-200', 'hex' => '#bfdbfe', 'bg' => 'bg-blue-200', 'text' => 'text-gray-900', 'use' => 'Dividers, accents'],
    ['name' => 'blue-300', 'hex' => '#93c5fd', 'bg' => 'bg-blue-300', 'text' => 'text-gray-900', 'use' => 'Illustration fill'],
    ['name' => 'blue-400', 'hex' => '#60a5fa', 'bg' => 'bg-blue-400', 'text' => 'text-white',    'use' => 'Secondary emphasis'],
    ['name' => 'blue-500', 'hex' => '#3b82f6', 'bg' => 'bg-blue-500', 'text' => 'text-white',    'use' => 'Hover tint on CTAs'],
    ['name' => 'blue-600', 'hex' => '#2563eb', 'bg' => 'bg-blue-600', 'text' => 'text-white',    'use' => 'Primary / CTA / link'],
    ['name' => 'blue-700', 'hex' => '#1d4ed8', 'bg' => 'bg-blue-700', 'text' => 'text-white',    'use' => 'Hover / pressed'],
    ['name' => 'blue-800', 'hex' => '#1e40af', 'bg' => 'bg-blue-800', 'text' => 'text-white',    'use' => 'Heading accent'],
    ['name' => 'blue-900', 'hex' => '#1e3a8a', 'bg' => 'bg-blue-900', 'text' => 'text-white',    'use' => 'Deep brand / footer'],
];

$supporting = [
    ['name' => 'amber-500',   'hex' => '#f59e0b', 'bg' => 'bg-amber-500',   'text' => 'text-gray-900', 'use' => 'Logo accent, highlights'],
    ['name' => 'indigo-600',  'hex' => '#4f46e5', 'bg' => 'bg-indigo-600',  'text' => 'text-white',    'use' => 'Gradient partner (blue→indigo)'],
    ['name' => 'emerald-500', 'hex' => '#10b981', 'bg' => 'bg-emerald-500', 'text' => 'text-white',    'use' => 'Print-ready, success echo'],
    ['name' => 'purple-600',  'hex' => '#9333ea', 'bg' => 'bg-purple-600',  'text' => 'text-white',    'use' => 'NFC / premium accent'],
];

$grayScale = [
    ['name' => 'gray-50',  'hex' => '#f9fafb', 'bg' => 'bg-gray-50',  'text' => 'text-gray-900', 'use' => 'Page bg'],
    ['name' => 'gray-100', 'hex' => '#f3f4f6', 'bg' => 'bg-gray-100', 'text' => 'text-gray-900', 'use' => 'Subtle bg / hover'],
    ['name' => 'gray-200', 'hex' => '#e5e7eb', 'bg' => 'bg-gray-200', 'text' => 'text-gray-900', 'use' => 'Borders'],
    ['name' => 'gray-300', 'hex' => '#d1d5db', 'bg' => 'bg-gray-300', 'text' => 'text-gray-900', 'use' => 'Input borders'],
    ['name' => 'gray-400', 'hex' => '#9ca3af', 'bg' => 'bg-gray-400', 'text' => 'text-white',    'use' => 'Placeholder text'],
    ['name' => 'gray-500', 'hex' => '#6b7280', 'bg' => 'bg-gray-500', 'text' => 'text-white',    'use' => 'Secondary text'],
    ['name' => 'gray-600', 'hex' => '#4b5563', 'bg' => 'bg-gray-600', 'text' => 'text-white',    'use' => 'Body secondary'],
    ['name' => 'gray-700', 'hex' => '#374151', 'bg' => 'bg-gray-700', 'text' => 'text-white',    'use' => 'Body primary'],
    ['name' => 'gray-800', 'hex' => '#1f2937', 'bg' => 'bg-gray-800', 'text' => 'text-white',    'use' => 'Subheading'],
    ['name' => 'gray-900', 'hex' => '#111827', 'bg' => 'bg-gray-900', 'text' => 'text-white',    'use' => 'Heading / footer'],
];

$semantic = [
    ['name' => 'success', 'hex' => '#16a34a', 'bg' => 'bg-green-600', 'chip' => 'bg-green-50 text-green-700',   'desc' => 'Paid, live, published'],
    ['name' => 'warning', 'hex' => '#d97706', 'bg' => 'bg-amber-600', 'chip' => 'bg-amber-50 text-amber-700',   'desc' => 'Pending review, draft'],
    ['name' => 'danger',  'hex' => '#dc2626', 'bg' => 'bg-red-600',   'chip' => 'bg-red-50 text-red-700',       'desc' => 'Failed, blocked, error'],
    ['name' => 'info',    'hex' => '#2563eb', 'bg' => 'bg-blue-600',  'chip' => 'bg-blue-50 text-blue-700',     'desc' => 'Neutral info, brand'],
];

$spacing = [
    ['token' => 'p-1',  'px' => 4],
    ['token' => 'p-2',  'px' => 8],
    ['token' => 'p-3',  'px' => 12],
    ['token' => 'p-4',  'px' => 16],
    ['token' => 'p-6',  'px' => 24],
    ['token' => 'p-8',  'px' => 32],
    ['token' => 'p-10', 'px' => 40],
    ['token' => 'p-12', 'px' => 48],
];

$radius = [
    ['token' => 'rounded',      'name' => 'base', 'px' => '4px',   'use' => 'Tiny accents'],
    ['token' => 'rounded-lg',   'name' => 'lg',   'px' => '8px',   'use' => 'Chips, small buttons'],
    ['token' => 'rounded-xl',   'name' => 'xl',   'px' => '12px',  'use' => 'Buttons, inputs'],
    ['token' => 'rounded-2xl',  'name' => '2xl',  'px' => '16px',  'use' => 'Cards, tiles'],
    ['token' => 'rounded-3xl',  'name' => '3xl',  'px' => '24px',  'use' => 'Hero panels'],
    ['token' => 'rounded-full', 'name' => 'full', 'px' => '9999px','use' => 'Pills, avatars, toggles'],
];

$shadows = [
    ['token' => 'shadow-sm', 'name' => 'sm', 'desc' => 'Subtle lift on flat surfaces'],
    ['token' => 'shadow-md', 'name' => 'md', 'desc' => 'Standard card elevation'],
    ['token' => 'shadow-lg', 'name' => 'lg', 'desc' => 'Hover lift / dropdown'],
    ['token' => 'shadow-xl', 'name' => 'xl', 'desc' => 'Modal / hero'],
    ['token' => 'shadow-2xl','name' => '2xl','desc' => 'Prominent float / overlay'],
    ['token' => 'shadow-blue-600/30', 'name' => 'brand glow', 'desc' => 'Primary CTA shadow tint'],
];

// Font Awesome icons actually used across Cardify
$icons = [
    ['i' => 'fa-solid fa-id-card',        'n' => 'id-card'],
    ['i' => 'fa-solid fa-qrcode',         'n' => 'qrcode'],
    ['i' => 'fa-brands fa-whatsapp',      'n' => 'whatsapp'],
    ['i' => 'fa-solid fa-wifi',           'n' => 'wifi (nfc)'],
    ['i' => 'fa-solid fa-envelope',       'n' => 'envelope'],
    ['i' => 'fa-solid fa-phone',          'n' => 'phone'],
    ['i' => 'fa-solid fa-user',           'n' => 'user'],
    ['i' => 'fa-solid fa-users',          'n' => 'users'],
    ['i' => 'fa-solid fa-sitemap',        'n' => 'sitemap'],
    ['i' => 'fa-solid fa-print',          'n' => 'print'],
    ['i' => 'fa-solid fa-share-nodes',    'n' => 'share'],
    ['i' => 'fa-solid fa-palette',        'n' => 'palette'],
    ['i' => 'fa-solid fa-globe',          'n' => 'globe'],
    ['i' => 'fa-solid fa-chart-line',     'n' => 'chart-line'],
    ['i' => 'fa-solid fa-gauge-high',     'n' => 'gauge'],
    ['i' => 'fa-solid fa-download',       'n' => 'download'],
    ['i' => 'fa-solid fa-plus',           'n' => 'plus'],
    ['i' => 'fa-solid fa-pen-to-square',  'n' => 'edit'],
    ['i' => 'fa-solid fa-trash',          'n' => 'trash'],
    ['i' => 'fa-solid fa-arrow-right',    'n' => 'arrow-right'],
    ['i' => 'fa-solid fa-check',          'n' => 'check'],
    ['i' => 'fa-solid fa-magnifying-glass','n' => 'search'],
    ['i' => 'fa-solid fa-gear',           'n' => 'gear'],
    ['i' => 'fa-solid fa-bell',           'n' => 'bell'],
];

// Sample employees (digital cards), Cardify-idiomatic, not invoices
$sampleEmployees = [
    ['name' => 'Ahmed Al-Balushi', 'title' => 'CEO',              'dept' => 'Leadership', 'status' => 'published', 'views' => '1,248'],
    ['name' => 'Fatima Al-Harthy', 'title' => 'Head of Sales',    'dept' => 'Sales',      'status' => 'published', 'views' => '892'],
    ['name' => 'Salim Al-Rashdi',  'title' => 'Project Manager',  'dept' => 'Delivery',   'status' => 'draft',     'views' => ','],
    ['name' => 'Layla Al-Kindi',   'title' => 'Marketing Lead',   'dept' => 'Marketing',  'status' => 'review',    'views' => '32'],
];

$sections = [
    ['id' => 'foundations', 'label' => 'Foundations'],
    ['id' => 'colors',      'label' => 'Color system'],
    ['id' => 'typography',  'label' => 'Typography'],
    ['id' => 'spacing',     'label' => 'Spacing & radius'],
    ['id' => 'shadows',     'label' => 'Shadows & motion'],
    ['id' => 'icons',       'label' => 'Icons'],
    ['id' => 'buttons',     'label' => 'Buttons'],
    ['id' => 'forms',       'label' => 'Form controls'],
    ['id' => 'chips',       'label' => 'Chips & status'],
    ['id' => 'alerts',      'label' => 'Alerts & banners'],
    ['id' => 'cards',       'label' => 'Cards & surfaces'],
    ['id' => 'stats',       'label' => 'Stats & KPI'],
    ['id' => 'navigation',  'label' => 'Navigation'],
    ['id' => 'data',        'label' => 'Data display'],
    ['id' => 'feedback',    'label' => 'Feedback & loaders'],
    ['id' => 'overlays',    'label' => 'Overlays'],
    ['id' => 'patterns',    'label' => 'Cardify patterns'],
    ['id' => 'rtl',         'label' => 'RTL preview'],
    ['id' => 'dos',         'label' => "Do / Don't"],
];

require_once INCLUDES_DIR . '/ui-header.php';
?>

<style>
  .swatch-btn { background: transparent; border: 0; padding: 0; cursor: pointer; }
  .swatch-copy {
    display: inline-flex; align-items: center; gap: 4px;
    border: 1px solid #e5e7eb; background: #fff;
    border-radius: 6px; padding: 2px 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 10px; color: #374151;
    transition: all .15s;
    cursor: pointer;
  }
  .swatch-copy:hover { border-color: #2563eb; color: #2563eb; }
  .swatch-copy.copied { border-color: #16a34a; color: #16a34a; }
  .anchor-link:hover { color: #2563eb; }
  .sticky-toc a.active { color: #2563eb; font-weight: 600; }
  [x-cloak] { display: none !important; }
  @keyframes toastIn {
    from { opacity: 0; transform: translateY(8px) translateX(-50%); }
    to   { opacity: 1; transform: translateY(0) translateX(-50%); }
  }
  .toast-in { animation: toastIn .18s ease-out; }
  .otp-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none; }
  /* shimmer skeleton */
  @keyframes shimmer {
    0% { background-position: -500px 0 }
    100% { background-position: 500px 0 }
  }
  .shimmer {
    background: linear-gradient(90deg, #f3f4f6 0%, #e5e7eb 50%, #f3f4f6 100%);
    background-size: 1000px 100%;
    animation: shimmer 1.6s infinite linear;
  }
</style>

<script>
  // Registered before Alpine boots so <div x-data="showcase()"> can resolve it.
  // Defined as a plain function (not Alpine.data) to avoid ordering issues
  // with the deferred Alpine CDN script.
  window.showcase = function () {
    var FOCUSABLE = [
      'a[href]',
      'button:not([disabled])',
      'textarea',
      'input:not([type=hidden]):not([disabled])',
      'select',
      '[tabindex]:not([tabindex="-1"])'
    ].join(',');
    return {
      copied: '',
      tab: 'overview',
      // Single source of truth, at most one overlay is visible at a time.
      overlay: null,
      _lastTrigger: null,
      get modalOpen()  { return this.overlay === 'modal'; },
      set modalOpen(v) { this.overlay = v ? 'modal' : (this.overlay === 'modal' ? null : this.overlay); },
      get drawerOpen()  { return this.overlay === 'drawer'; },
      set drawerOpen(v) { this.overlay = v ? 'drawer' : (this.overlay === 'drawer' ? null : this.overlay); },
      openOverlay: function (name, ev) {
        this._lastTrigger = (ev && ev.currentTarget) || document.activeElement;
        this.overlay = name;
      },
      closeOverlays: function () {
        this.overlay = null;
        var t = this._lastTrigger;
        this._lastTrigger = null;
        this.$nextTick(function () { try { t && t.focus && t.focus(); } catch (_) {} });
      },
      focusFirst: function (container) {
        if (!container) return;
        this.$nextTick(function () {
          var el = container.querySelector(FOCUSABLE);
          if (el) { try { el.focus(); } catch (_) {} }
        });
      },
      trapTab: function (ev, container) {
        if (ev.key !== 'Tab' || !container) return;
        var nodes = Array.prototype.slice.call(container.querySelectorAll(FOCUSABLE))
          .filter(function (n) { return n.offsetParent !== null; });
        if (!nodes.length) return;
        var first = nodes[0], last = nodes[nodes.length - 1];
        if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      },
      toast: null,
      rtl: false,
      copy: function (v) {
        if (!v) return;
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(v);
          } else {
            var ta = document.createElement('textarea');
            ta.value = v; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (_) {}
            document.body.removeChild(ta);
          }
          this.copied = v;
          var self = this;
          setTimeout(function () { self.copied = ''; }, 1200);
        } catch (e) {}
      },
      showToast: function (kind) {
        this.toast = kind;
        var self = this;
        setTimeout(function () { self.toast = null; }, 2400);
      }
    };
  };
</script>

<div class="pt-24 pb-20 bg-gradient-to-b from-gray-50 to-white min-h-screen"
     x-data="showcase()"
     x-effect="document.body.classList.toggle('overflow-hidden', overlay !== null)"
     @keydown.escape.window="closeOverlays()">

  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

    <!-- HERO -->
    <header class="max-w-3xl mb-12">
      <div class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold uppercase tracking-wider text-blue-700">
        <i class="fa-solid fa-palette text-[10px]"></i>
        Cardify Design System
      </div>
      <h1 class="mt-4 text-4xl md:text-5xl font-extrabold text-gray-900 tracking-tight">
        The visual language behind <span class="text-blue-600">Cardify</span>
      </h1>
      <p class="mt-4 text-lg text-gray-600">
        Every color, component, and pattern Cardify uses across the site, live and click-to-copy.
        Built on Tailwind 3 + Flowbite + Font Awesome 6. Inter for body, system for numbers.
      </p>
      <div class="mt-5 flex flex-wrap items-center gap-3">
        <a href="https://github.com/zaabi1995/cardify.om" target="_blank" rel="noreferrer"
           class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 text-sm font-semibold hover:border-blue-500 hover:text-blue-600 transition">
          <i class="fa-brands fa-github"></i> View on GitHub
        </a>
        <button type="button" @click="rtl = !rtl"
                class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-gray-300 bg-white text-gray-700 text-sm font-semibold hover:border-blue-500 hover:text-blue-600 transition">
          <i class="fa-solid fa-language"></i>
          <span x-text="rtl ? 'Preview LTR' : 'Preview RTL'"></span>
        </button>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-xs font-semibold">Tailwind 3</span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-xs font-semibold">Flowbite</span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-semibold">Font Awesome 6</span>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-3 py-1 text-xs font-semibold">Alpine.js</span>
      </div>
    </header>

    <div class="lg:grid lg:grid-cols-[220px_1fr] lg:gap-12">

      <!-- Sticky TOC -->
      <aside class="hidden lg:block">
        <nav class="sticky top-24 space-y-0.5 border-l border-gray-200 pl-4 sticky-toc">
          <?php foreach ($sections as $sec): ?>
            <a href="#<?= $sec['id'] ?>" class="block text-sm text-gray-500 hover:text-blue-600 py-1 transition"><?= htmlspecialchars($sec['label']) ?></a>
          <?php endforeach; ?>
        </nav>
      </aside>

      <!-- Content -->
      <div :dir="rtl ? 'rtl' : 'ltr'" class="space-y-16">

        <!-- 1. FOUNDATIONS -->
        <section id="foundations" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Foundations</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Three principles guide everything we ship at Cardify.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach ([
              ['i' => 'fa-solid fa-eye', 't' => 'Clarity first', 'd' => 'Each screen has one primary action. Numbers, names, and statuses are readable at arm\'s length on a phone.'],
              ['i' => 'fa-solid fa-bolt', 't' => 'Fast by default', 'd' => 'Sub-second interactions, no layout shift, no spinners for sync operations. If it\'s slow we show progress.'],
              ['i' => 'fa-solid fa-shield-halved', 't' => 'Trustworthy', 'd' => 'Oman-first (OMR, +968, AR/EN). Payment status, publish state, and ownership are never ambiguous.'],
            ] as $p): ?>
              <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-600">
                  <i class="<?= $p['i'] ?>"></i>
                </div>
                <h3 class="mt-3 text-lg font-bold text-gray-900"><?= $p['t'] ?></h3>
                <p class="mt-1 text-sm text-gray-500"><?= $p['d'] ?></p>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- 2. COLORS -->
        <section id="colors" class="scroll-mt-24 space-y-6">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Color system</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Click any chip to copy the hex or Tailwind class. Cardify blue (<code class="font-mono text-xs text-blue-700">#2563eb</code>) is the primary brand color; amber is reserved for the logo accent.</p>
          </header>

          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Brand blue</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
              <?php foreach ($brandSwatches as $s): ?>
                <div class="rounded-xl overflow-hidden border border-gray-200 bg-white">
                  <div class="h-20 <?= $s['bg'] ?> <?= $s['text'] ?> flex items-end p-2.5 text-xs font-semibold"><?= $s['name'] ?></div>
                  <div class="px-3 py-2 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-[11px] font-mono text-gray-700"><?= $s['hex'] ?></span>
                      <button type="button" class="swatch-copy" :class="{ copied: copied === '<?= $s['hex'] ?>' }" @click="copy('<?= $s['hex'] ?>')">
                        <i class="fa-solid text-[9px]" :class="copied === '<?= $s['hex'] ?>' ? 'fa-check' : 'fa-copy'"></i><?= $s['hex'] ?>
                      </button>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-[10px] text-gray-500 truncate" title="<?= $s['use'] ?>"><?= $s['use'] ?></span>
                      <button type="button" class="swatch-copy" :class="{ copied: copied === '<?= $s['bg'] ?>' }" @click="copy('<?= $s['bg'] ?>')">
                        <i class="fa-solid text-[9px]" :class="copied === '<?= $s['bg'] ?>' ? 'fa-check' : 'fa-copy'"></i><?= $s['bg'] ?>
                      </button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Supporting palette</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
              <?php foreach ($supporting as $s): ?>
                <div class="rounded-xl overflow-hidden border border-gray-200 bg-white">
                  <div class="h-20 <?= $s['bg'] ?> <?= $s['text'] ?> flex items-end p-2.5 text-xs font-semibold"><?= $s['name'] ?></div>
                  <div class="px-3 py-2 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-[11px] font-mono text-gray-700"><?= $s['hex'] ?></span>
                      <button type="button" class="swatch-copy" :class="{ copied: copied === '<?= $s['hex'] ?>' }" @click="copy('<?= $s['hex'] ?>')">
                        <i class="fa-solid text-[9px]" :class="copied === '<?= $s['hex'] ?>' ? 'fa-check' : 'fa-copy'"></i><?= $s['hex'] ?>
                      </button>
                    </div>
                    <span class="text-[10px] text-gray-500 truncate block" title="<?= $s['use'] ?>"><?= $s['use'] ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Neutral grays</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3">
              <?php foreach ($grayScale as $s): ?>
                <div class="rounded-xl overflow-hidden border border-gray-200 bg-white">
                  <div class="h-20 <?= $s['bg'] ?> <?= $s['text'] ?> flex items-end p-2.5 text-xs font-semibold"><?= $s['name'] ?></div>
                  <div class="px-3 py-2 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                      <span class="text-[11px] font-mono text-gray-700"><?= $s['hex'] ?></span>
                      <button type="button" class="swatch-copy" :class="{ copied: copied === '<?= $s['hex'] ?>' }" @click="copy('<?= $s['hex'] ?>')">
                        <i class="fa-solid text-[9px]" :class="copied === '<?= $s['hex'] ?>' ? 'fa-check' : 'fa-copy'"></i><?= $s['hex'] ?>
                      </button>
                    </div>
                    <span class="text-[10px] text-gray-500 truncate block"><?= $s['use'] ?></span>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-3">Semantic</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
              <?php foreach ($semantic as $s): ?>
                <div class="bg-white rounded-2xl border border-gray-200 p-4 space-y-2 shadow-sm">
                  <div class="h-10 rounded-lg <?= $s['bg'] ?>"></div>
                  <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-gray-900 capitalize"><?= $s['name'] ?></span>
                    <span class="text-[11px] font-mono text-gray-500"><?= $s['hex'] ?></span>
                  </div>
                  <span class="inline-flex items-center rounded-full <?= $s['chip'] ?> px-2.5 py-0.5 text-xs font-semibold">chip</span>
                  <p class="text-xs text-gray-500"><?= $s['desc'] ?></p>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </section>

        <!-- 3. TYPOGRAPHY -->
        <section id="typography" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Typography</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Inter for body + headings (Google Fonts, weights 300–900). Monospace for numbers and code.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <span class="text-[10px] font-mono text-gray-500">Display · Inter · 700–900</span>
              <p class="mt-3 text-5xl font-extrabold text-gray-900 tracking-tight">Display 5xl</p>
              <p class="mt-2 text-4xl font-extrabold text-gray-900 tracking-tight">Display 4xl</p>
              <p class="mt-2 text-3xl font-bold text-gray-900">Display 3xl</p>
              <p class="mt-2 text-2xl font-bold text-gray-900">Display 2xl</p>
              <p class="mt-2 text-xl font-semibold text-gray-900">Display xl</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <span class="text-[10px] font-mono text-gray-500">Body · Inter · 400–600</span>
              <p class="mt-3 text-lg text-gray-900">Body lg, section leads, intros</p>
              <p class="mt-2 text-base text-gray-700">Body base, the default for running copy. High legibility at all sizes.</p>
              <p class="mt-2 text-sm text-gray-500">Body sm, secondary info, helper text, captions.</p>
              <p class="mt-2 text-xs text-gray-500">Body xs, metadata, timestamps, table footnotes.</p>
              <p class="mt-3 text-xs font-bold uppercase tracking-widest text-blue-600">Overline, eyebrows</p>
              <p class="mt-3"><code class="font-mono text-xs bg-gray-100 text-gray-900 px-2 py-1 rounded">code inline</code></p>
            </div>
          </div>
        </section>

        <!-- 4. SPACING & RADIUS -->
        <section id="spacing" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Spacing &amp; radius</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Cardify uses Tailwind's default 4px grid. Cards use <code class="font-mono text-xs">rounded-2xl</code>; buttons use <code class="font-mono text-xs">rounded-lg</code> or <code class="font-mono text-xs">rounded-xl</code>.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Spacing scale</h3>
              <div class="space-y-2">
                <?php foreach ($spacing as $s): ?>
                  <div class="flex items-center gap-3">
                    <span class="text-xs font-mono text-gray-500 w-14"><?= $s['token'] ?></span>
                    <div class="h-3 bg-blue-500 rounded" style="width: <?= $s['px'] ?>px;"></div>
                    <span class="text-xs text-gray-500"><?= $s['px'] ?>px</span>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Border radius</h3>
              <div class="grid grid-cols-2 gap-3">
                <?php foreach ($radius as $r): ?>
                  <div class="flex items-center gap-3">
                    <div class="h-10 w-10 bg-blue-100 border border-blue-300 <?= $r['token'] ?>"></div>
                    <div>
                      <div class="text-xs font-bold text-gray-900"><?= $r['name'] ?></div>
                      <div class="text-[11px] font-mono text-gray-500"><?= $r['token'] ?> · <?= $r['px'] ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </section>

        <!-- 5. SHADOWS & MOTION -->
        <section id="shadows" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Shadows &amp; motion</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Subtle by default. Brand glow on primary CTAs only.</p>
          </header>
          <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <?php foreach ($shadows as $s): ?>
              <div class="bg-white rounded-2xl p-6 border border-gray-200 <?= $s['token'] ?>">
                <div class="text-xs font-mono text-gray-500"><?= $s['token'] ?></div>
                <div class="mt-1 text-lg font-bold text-gray-900"><?= $s['name'] ?></div>
                <p class="mt-1 text-sm text-gray-500"><?= $s['desc'] ?></p>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm mt-4">
            <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Motion</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
              <div><span class="font-mono text-xs text-gray-500">duration-150</span><div class="text-gray-800">Hover, focus rings</div></div>
              <div><span class="font-mono text-xs text-gray-500">duration-200</span><div class="text-gray-800">Buttons, inputs</div></div>
              <div><span class="font-mono text-xs text-gray-500">duration-300</span><div class="text-gray-800">Modals, drawers</div></div>
            </div>
          </div>
        </section>

        <!-- 6. ICONS -->
        <section id="icons" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Icons</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Font Awesome 6 Solid by default; Brands for social platforms. Click any icon to copy its class.</p>
          </header>
          <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <div class="grid grid-cols-3 sm:grid-cols-5 md:grid-cols-8 gap-3">
              <?php foreach ($icons as $ic): ?>
                <button type="button" class="swatch-btn flex flex-col items-center gap-1 p-2 rounded-lg hover:bg-gray-100 transition"
                        @click="copy('<?= $ic['i'] ?>')"
                        title="Copy class: <?= $ic['i'] ?>">
                  <i class="<?= $ic['i'] ?> text-xl text-gray-700"></i>
                  <span class="text-[10px] font-mono text-gray-500 truncate w-full text-center"><?= $ic['n'] ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <p class="mt-4 text-xs text-gray-500">Full library at <a href="https://fontawesome.com/icons" target="_blank" rel="noreferrer" class="text-blue-600 font-semibold">fontawesome.com</a>.</p>
          </div>
        </section>

        <!-- 7. BUTTONS -->
        <section id="buttons" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Buttons</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Primary uses <code class="font-mono text-xs">bg-blue-600</code> with a soft glow. Secondary is a white card with a gray border. Ghost is text-only.</p>
          </header>
          <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Variants</h3>
              <div class="flex flex-wrap gap-3 items-center">
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition-all">Primary action</button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition">Secondary</button>
                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 text-gray-700 hover:text-blue-600 font-semibold transition">Ghost</button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition">Danger</button>
                <button type="button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white font-semibold rounded-lg opacity-50 cursor-not-allowed">Disabled</button>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">With icons</h3>
              <div class="flex flex-wrap gap-3 items-center">
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition"><i class="fa-solid fa-plus text-xs"></i> New card</button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition"><i class="fa-solid fa-download text-xs"></i> Download vCard</button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition">Continue <i class="fa-solid fa-arrow-right text-xs"></i></button>
                <button type="button" class="inline-flex items-center gap-2 px-4 py-2 text-gray-700 hover:text-blue-600 font-semibold transition"><i class="fa-solid fa-pen-to-square text-xs"></i> Edit</button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition"><i class="fa-solid fa-trash text-xs"></i> Delete</button>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Loading &amp; icon-only</h3>
              <div class="flex flex-wrap gap-3 items-center">
                <button type="button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 text-white font-semibold rounded-lg opacity-75 cursor-wait"><i class="fa-solid fa-spinner fa-spin text-xs"></i> Saving…</button>
                <button type="button" disabled class="inline-flex items-center gap-2 px-5 py-2.5 bg-white text-gray-700 font-semibold rounded-lg border border-gray-300 cursor-wait"><i class="fa-solid fa-spinner fa-spin text-xs"></i> Loading</button>
                <button type="button" aria-label="Settings" class="inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-300 bg-white text-gray-700 hover:border-blue-500 hover:text-blue-600 transition"><i class="fa-solid fa-gear"></i></button>
                <a href="#" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-gradient-to-r from-blue-600 to-indigo-700 text-white font-semibold shadow-lg shadow-blue-600/30 hover:from-blue-700 hover:to-indigo-800 transition">Get started free <i class="fa-solid fa-arrow-right text-xs"></i></a>
              </div>
            </div>
          </div>
        </section>

        <!-- 8. FORMS -->
        <section id="forms" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Form controls</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Inputs use <code class="font-mono text-xs">border-gray-300</code> with a blue focus ring. Error state flips the border to red. Labels sit above the input, 4px gap.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-4 shadow-sm">
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Email address</label>
                <div class="relative">
                  <i class="fa-solid fa-envelope text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 text-sm"></i>
                  <input type="email" class="w-full pl-10 pr-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition" placeholder="you@company.om">
                </div>
              </div>
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Phone (Oman)</label>
                <div class="flex gap-2">
                  <span class="inline-flex items-center justify-center px-3 py-2.5 border border-gray-300 rounded-lg bg-gray-50 text-gray-700 font-mono text-sm">+968</span>
                  <input type="tel" inputmode="tel" class="flex-1 px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition" placeholder="9XXX XXXX">
                </div>
              </div>
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Error state</label>
                <input type="email" value="invalid@" class="w-full px-3 py-2.5 border border-red-400 rounded-lg focus:border-red-500 focus:ring-2 focus:ring-red-500/20 focus:outline-none transition">
                <p class="mt-1 text-xs text-red-600 inline-flex items-center gap-1"><i class="fa-solid fa-triangle-exclamation"></i> Enter a valid email address.</p>
              </div>
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Disabled</label>
                <input value="Read only value" disabled class="w-full px-3 py-2.5 border border-gray-300 rounded-lg bg-gray-100 text-gray-500 cursor-not-allowed">
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 space-y-4 shadow-sm">
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Bio</label>
                <textarea class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition min-h-[96px] resize-y" placeholder="Short intro shown under the name on your digital card…"></textarea>
              </div>
              <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">Department</label>
                <select class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition bg-white">
                  <option>Sales</option><option>Marketing</option><option>Engineering</option><option>Leadership</option>
                </select>
              </div>
              <div class="flex items-start gap-3">
                <input id="agree-showcase" type="checkbox" checked class="w-4 h-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500/30 mt-0.5">
                <label for="agree-showcase" class="text-sm text-gray-700">Send me weekly card scan analytics.</label>
              </div>
              <div class="space-y-2">
                <label class="block text-sm font-semibold text-gray-700 mb-1">Card theme</label>
                <?php foreach (['Light', 'Dark', 'Brand gradient'] as $i => $o): ?>
                  <label class="flex items-center gap-3 text-sm text-gray-700">
                    <input type="radio" name="pm-showcase" <?= $i === 0 ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500/30">
                    <?= $o ?>
                  </label>
                <?php endforeach; ?>
              </div>
              <div x-data="{ on: true }" class="flex items-center justify-between gap-3 pt-2 border-t border-gray-100">
                <div>
                  <div class="text-sm font-semibold text-gray-900">Public profile</div>
                  <div class="text-xs text-gray-500">Show this card on your company's Cardify page.</div>
                </div>
                <button type="button" role="switch" :aria-checked="on" @click="on = !on"
                        :class="on ? 'bg-blue-600' : 'bg-gray-300'"
                        class="relative inline-flex h-6 w-11 items-center rounded-full transition">
                  <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition"
                        :class="on ? 'translate-x-5' : 'translate-x-0.5'"></span>
                </button>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 md:col-span-2 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">One-time code (OTP)</h3>
              <div x-data="{ digits: ['','','','','',''] }" class="flex gap-2 flex-wrap" dir="ltr">
                <template x-for="(d, i) in digits" :key="i">
                  <input maxlength="1" inputmode="numeric" x-model="digits[i]"
                         @input="digits[i] = digits[i].replace(/\D/g, '').slice(-1)"
                         class="otp-input w-10 h-12 rounded-xl border border-gray-300 bg-white text-center font-mono text-lg font-bold text-gray-900 transition">
                </template>
              </div>
              <p class="text-xs text-gray-500 mt-3">Six-digit code sent via WhatsApp or email.</p>
            </div>
          </div>
        </section>

        <!-- 9. CHIPS -->
        <section id="chips" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Chips &amp; status</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Status pills use the tinted-bg + strong-text combo. Keep to two words max.</p>
          </header>
          <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm flex flex-wrap gap-2 items-center">
            <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 text-blue-700 px-2.5 py-1 text-xs font-semibold">Brand</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-check text-[10px]"></i> Published</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-clock text-[10px]"></i> Pending</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 text-red-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-circle-xmark text-[10px]"></i> Unpaid</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-2.5 py-1 text-xs font-semibold">Draft</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-purple-50 text-purple-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-wifi text-[10px]"></i> NFC ready</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 text-indigo-700 px-2.5 py-1 text-xs font-semibold">Team plan</span>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-900 text-white px-2.5 py-1 text-xs font-semibold">New</span>
          </div>
        </section>

        <!-- 10. ALERTS -->
        <section id="alerts" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Alerts &amp; banners</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Four tones; icon + title + body; dismissible.</p>
          </header>
          <div class="space-y-3">
            <?php foreach ([
              ['i' => 'fa-circle-info', 'bg' => 'bg-blue-50', 'border' => 'border-blue-200', 'text' => 'text-blue-700', 'title' => 'NFC cards now available', 'body' => 'Order a printed NFC-enabled business card directly from any Oman print shop partner.'],
              ['i' => 'fa-circle-check', 'bg' => 'bg-green-50', 'border' => 'border-green-200', 'text' => 'text-green-700', 'title' => 'Card published', 'body' => 'Ahmed\'s card is live at cardify.om/acme/ahmed.'],
              ['i' => 'fa-triangle-exclamation', 'bg' => 'bg-amber-50', 'border' => 'border-amber-200', 'text' => 'text-amber-700', 'title' => 'Trial ends in 3 days', 'body' => 'Upgrade to Team plan to keep your 24 published cards online.'],
              ['i' => 'fa-circle-xmark', 'bg' => 'bg-red-50', 'border' => 'border-red-200', 'text' => 'text-red-700', 'title' => 'Payment failed', 'body' => 'Paymob returned a decline. Try a different card or switch to bank transfer.'],
            ] as $a): ?>
              <div class="rounded-xl border <?= $a['border'] ?> <?= $a['bg'] ?> p-4 gap-3" x-data="{ show: true }" :class="show ? 'flex' : 'hidden'">
                <i class="fa-solid <?= $a['i'] ?> <?= $a['text'] ?> text-lg flex-shrink-0 mt-0.5"></i>
                <div class="flex-1">
                  <div class="font-bold text-sm <?= $a['text'] ?>"><?= $a['title'] ?></div>
                  <p class="text-sm text-gray-700 mt-0.5"><?= $a['body'] ?></p>
                </div>
                <button type="button" @click="show = false" aria-label="Dismiss" class="text-gray-400 hover:text-gray-700 transition">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- 11. CARDS -->
        <section id="cards" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Cards &amp; surfaces</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Default is white with <code class="font-mono text-xs">rounded-2xl</code>, <code class="font-mono text-xs">border-gray-200</code>, subtle <code class="font-mono text-xs">shadow-sm</code>.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-lg font-bold text-gray-900">Default card</h3>
              <p class="mt-1 text-sm text-gray-500">16px radius, 1px gray-200 border, small shadow. Used across dashboards, pricing tiles, feature lists.</p>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-lg">
              <h3 class="text-lg font-bold text-gray-900">Elevated card</h3>
              <p class="mt-1 text-sm text-gray-500">Larger shadow. Used for pop-overs, hero highlights, and modals.</p>
            </div>
            <!-- Digital business card preview -->
            <div class="md:col-span-2 rounded-2xl overflow-hidden shadow-xl max-w-md mx-auto w-full" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);">
              <div class="p-6 text-white">
                <div class="flex items-center gap-4">
                  <div class="w-16 h-16 rounded-full bg-gradient-to-br from-amber-400 to-amber-500 flex items-center justify-center text-white text-2xl font-bold shadow-lg">A</div>
                  <div class="flex-1">
                    <div class="text-xl font-bold">Ahmed Al-Balushi</div>
                    <div class="text-sm text-blue-100">CEO · Acme Trading LLC</div>
                  </div>
                </div>
                <div class="mt-5 space-y-2 text-sm">
                  <div class="flex items-center gap-3"><i class="fa-solid fa-phone w-4 text-blue-200"></i><span>+968 9123 4567</span></div>
                  <div class="flex items-center gap-3"><i class="fa-solid fa-envelope w-4 text-blue-200"></i><span>ahmed@acme.om</span></div>
                  <div class="flex items-center gap-3"><i class="fa-solid fa-globe w-4 text-blue-200"></i><span>acme.om</span></div>
                </div>
                <div class="mt-6 flex gap-2">
                  <button type="button" class="flex-1 px-4 py-2 rounded-lg bg-white text-blue-700 font-semibold text-sm hover:bg-blue-50 transition">Save contact</button>
                  <button type="button" aria-label="Share" class="w-10 h-10 rounded-lg bg-white/20 text-white hover:bg-white/30 transition"><i class="fa-solid fa-share-nodes"></i></button>
                </div>
              </div>
              <div class="px-6 pb-5 text-center text-[11px] text-blue-200 font-mono">cardify.om/acme/ahmed</div>
            </div>
            <!-- Empty state -->
            <div class="md:col-span-2 border-2 border-dashed border-gray-300 rounded-2xl p-10 text-center bg-white">
              <i class="fa-solid fa-id-card text-4xl text-gray-400"></i>
              <h3 class="mt-3 text-lg font-bold text-gray-900">No cards yet</h3>
              <p class="mt-1 text-sm text-gray-500 max-w-prose mx-auto">Add your first employee to generate a digital business card with a live QR and shareable URL.</p>
              <button type="button" class="mt-4 inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition"><i class="fa-solid fa-plus text-xs"></i> Add employee</button>
            </div>
          </div>
        </section>

        <!-- 12. STATS -->
        <section id="stats" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Stats &amp; KPI</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Label (small uppercase) → big value → delta chip.</p>
          </header>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php foreach ([
              ['l' => 'Total cards',    'v' => '24',     'd' => '4 departments',    'tr' => '+3',  'tone' => 'text-green-600'],
              ['l' => 'Card views',     'v' => '12,840', 'd' => 'last 30 days',     'tr' => '+42%','tone' => 'text-green-600'],
              ['l' => 'vCard saves',    'v' => '1,892',  'd' => '14.7% of views',   'tr' => '+8%', 'tone' => 'text-green-600'],
              ['l' => 'Print orders',   'v' => 'OMR 148.500', 'd' => '2 in queue',  'tr' => '+1',  'tone' => 'text-blue-600'],
            ] as $s): ?>
              <div class="bg-white rounded-2xl border border-gray-200 p-5 shadow-sm">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500"><?= $s['l'] ?></div>
                <div class="mt-2 text-2xl font-extrabold text-gray-900 tracking-tight"><?= $s['v'] ?></div>
                <div class="mt-1 flex items-center justify-between text-xs">
                  <span class="text-gray-500"><?= $s['d'] ?></span>
                  <span class="font-bold <?= $s['tone'] ?>"><?= $s['tr'] ?></span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>

        <!-- 13. NAVIGATION -->
        <section id="navigation" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Navigation</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Pill tabs for dashboards, underline tabs for detail views, breadcrumbs for deep navigation.</p>
          </header>
          <div class="space-y-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Tabs, pill</h3>
              <div class="inline-flex p-1 bg-gray-100 rounded-xl gap-1">
                <?php foreach (['overview', 'cards', 'analytics', 'orders'] as $t): ?>
                  <button type="button" @click="tab = '<?= $t ?>'"
                          :class="tab === '<?= $t ?>' ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                          class="px-3 py-1.5 text-sm font-semibold rounded-lg transition capitalize"><?= $t ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Tabs, underline</h3>
              <div class="border-b border-gray-200 flex gap-6">
                <?php foreach (['Profile', 'Socials', 'Analytics'] as $i => $t): ?>
                  <button type="button" class="py-2 text-sm font-semibold transition <?= $i === 0 ? 'text-blue-600 border-b-2 border-blue-600' : 'text-gray-500 hover:text-gray-900' ?>"><?= $t ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Breadcrumbs</h3>
              <nav class="flex items-center gap-1.5 text-sm text-gray-500 flex-wrap">
                <a class="hover:text-blue-600 cursor-pointer">Dashboard</a>
                <i class="fa-solid fa-chevron-right text-[10px]" :class="rtl ? 'rotate-180' : ''"></i>
                <a class="hover:text-blue-600 cursor-pointer">Employees</a>
                <i class="fa-solid fa-chevron-right text-[10px]" :class="rtl ? 'rotate-180' : ''"></i>
                <span class="text-gray-900 font-semibold">Ahmed Al-Balushi</span>
              </nav>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Pagination</h3>
              <div class="flex items-center justify-between flex-wrap gap-3">
                <span class="text-sm text-gray-500">Showing <b class="text-gray-900">1–10</b> of <b class="text-gray-900">84</b></span>
                <div class="inline-flex items-center gap-1">
                  <button type="button" class="w-8 h-8 rounded-lg border border-gray-300 text-gray-700 inline-flex items-center justify-center hover:border-blue-500 hover:text-blue-600 transition bg-white"><i class="fa-solid fa-chevron-left text-xs"></i></button>
                  <?php foreach ([1, 2, 3, 4, 5] as $n): ?>
                    <button type="button" class="w-8 h-8 rounded-lg text-sm font-semibold inline-flex items-center justify-center transition border <?= $n === 1 ? 'bg-blue-600 text-white border-blue-600' : 'bg-white text-gray-700 border-gray-300 hover:border-blue-500 hover:text-blue-600' ?>"><?= $n ?></button>
                  <?php endforeach; ?>
                  <span class="px-1 text-gray-500">…</span>
                  <button type="button" class="w-8 h-8 rounded-lg border border-gray-300 text-gray-700 inline-flex items-center justify-center hover:border-blue-500 hover:text-blue-600 transition bg-white"><i class="fa-solid fa-chevron-right text-xs"></i></button>
                </div>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Stepper, card onboarding</h3>
              <ol class="flex items-center gap-2 flex-wrap">
                <?php foreach ([
                  ['n' => 1, 't' => 'Create account', 'done' => true],
                  ['n' => 2, 't' => 'Add employees', 'done' => true],
                  ['n' => 3, 't' => 'Pick theme', 'current' => true],
                  ['n' => 4, 't' => 'Order print'],
                ] as $i => $s):
                  $done = !empty($s['done']); $cur = !empty($s['current']);
                ?>
                  <li class="flex items-center gap-2">
                    <span class="w-7 h-7 rounded-full inline-flex items-center justify-center text-xs font-bold border-2 <?= $done ? 'bg-blue-600 border-blue-600 text-white' : ($cur ? 'bg-white border-blue-600 text-blue-600' : 'bg-white border-gray-300 text-gray-400') ?>">
                      <?= $done ? '<i class="fa-solid fa-check text-[10px]"></i>' : $s['n'] ?>
                    </span>
                    <span class="text-sm font-semibold <?= $done || $cur ? 'text-gray-900' : 'text-gray-400' ?>"><?= $s['t'] ?></span>
                    <?php if ($s['n'] < 4): ?>
                      <div class="w-8 h-0.5 <?= $done ? 'bg-blue-600' : 'bg-gray-200' ?>"></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>
          </div>
        </section>

        <!-- 14. DATA -->
        <section id="data" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Data display</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Clean tables, activity timelines, and description lists.</p>
          </header>
          <div class="space-y-4">
            <!-- Table: employees -->
            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden shadow-sm">
              <div class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-gray-50 border-b border-gray-200 text-gray-500">
                    <tr>
                      <th class="text-left font-bold uppercase tracking-wide text-xs px-5 py-3">Employee</th>
                      <th class="text-left font-bold uppercase tracking-wide text-xs px-5 py-3">Department</th>
                      <th class="text-left font-bold uppercase tracking-wide text-xs px-5 py-3">Status</th>
                      <th class="text-right font-bold uppercase tracking-wide text-xs px-5 py-3">Views</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-200">
                    <?php foreach ($sampleEmployees as $e):
                      $statusChip = [
                        'published' => '<span class="inline-flex items-center gap-1.5 rounded-full bg-green-50 text-green-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-check text-[10px]"></i> Published</span>',
                        'draft'     => '<span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 text-gray-700 px-2.5 py-1 text-xs font-semibold">Draft</span>',
                        'review'    => '<span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-clock text-[10px]"></i> In review</span>',
                      ][$e['status']];
                      $initial = strtoupper(substr($e['name'], 0, 1));
                    ?>
                      <tr class="hover:bg-gray-50 transition">
                        <td class="px-5 py-3">
                          <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-600 to-indigo-700 text-white flex items-center justify-center text-xs font-bold"><?= $initial ?></div>
                            <div>
                              <div class="font-semibold text-gray-900"><?= htmlspecialchars($e['name']) ?></div>
                              <div class="text-xs text-gray-500"><?= htmlspecialchars($e['title']) ?></div>
                            </div>
                          </div>
                        </td>
                        <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($e['dept']) ?></td>
                        <td class="px-5 py-3"><?= $statusChip ?></td>
                        <td class="px-5 py-3 text-right font-mono text-gray-900"><?= htmlspecialchars($e['views']) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Timeline: card activity -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-4">Activity timeline, card scans</h3>
              <ol class="relative border-l-2 border-gray-200 ml-3 space-y-6 pl-6">
                <?php foreach ([
                  ['t' => 'Card created', 'd' => 'Ahmed Al-Balushi · /acme/ahmed', 'when' => '02 Apr 2026', 'done' => true],
                  ['t' => 'First scan',    'd' => 'Muscat, Oman · Apple iPhone 15', 'when' => '04 Apr 2026', 'done' => true],
                  ['t' => 'vCard saved',   'd' => '+968 9123 ••• 67 via WhatsApp QR', 'when' => '05 Apr 2026', 'done' => true],
                  ['t' => 'NFC tap',       'd' => 'Card handed to a client at trade show', 'when' => '09 Apr 2026', 'done' => true],
                  ['t' => 'Print order',   'd' => '100 × NFC-enabled cards · Matte finish', 'when' => '11 Apr 2026', 'current' => true],
                  ['t' => 'Delivery',      'd' => 'Scheduled with Al Noor Print Oman', 'when' => ',', 'future' => true],
                ] as $s):
                  $done = !empty($s['done']); $cur = !empty($s['current']);
                  $dot = $done ? 'bg-blue-600' : ($cur ? 'bg-amber-500' : 'bg-gray-300');
                ?>
                  <li class="relative">
                    <span class="absolute -left-[30px] top-0 w-5 h-5 rounded-full inline-flex items-center justify-center text-white ring-4 ring-white <?= $dot ?>">
                      <?php if ($done): ?><i class="fa-solid fa-check text-[9px]"></i><?php else: ?><span class="w-1.5 h-1.5 rounded-full bg-white"></span><?php endif; ?>
                    </span>
                    <div class="flex items-baseline justify-between flex-wrap gap-2">
                      <div class="text-sm font-semibold text-gray-900"><?= $s['t'] ?></div>
                      <div class="text-xs text-gray-500"><?= $s['when'] ?></div>
                    </div>
                    <div class="text-xs text-gray-500 mt-0.5"><?= $s['d'] ?></div>
                  </li>
                <?php endforeach; ?>
              </ol>
            </div>

            <!-- Description list -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Description list, company profile</h3>
              <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <?php foreach ([
                  ['Company', 'Acme Trading LLC'],
                  ['Slug',    '/acme/'],
                  ['CR number', '1234567'],
                  ['Plan',    'Team (24 cards)'],
                  ['Custom domain', 'cards.acme.om'],
                  ['Billing cycle', 'Monthly · Paymob'],
                ] as $kv): [$k,$v] = $kv; ?>
                  <div class="flex justify-between border-b border-gray-100 pb-2">
                    <dt class="text-gray-500"><?= $k ?></dt>
                    <dd class="text-gray-900 font-semibold"><?= $v ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          </div>
        </section>

        <!-- 15. FEEDBACK -->
        <section id="feedback" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Feedback &amp; loaders</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Progress bars for long operations, skeletons for first-load, toasts for async results.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Progress</h3>
              <div class="space-y-3">
                <div>
                  <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Uploading photos</span><span>62%</span></div>
                  <div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-blue-600 rounded-full" style="width: 62%;"></div></div>
                </div>
                <div>
                  <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Cards used</span><span>18 of 24</span></div>
                  <div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-amber-500 rounded-full" style="width: 75%;"></div></div>
                </div>
                <div>
                  <div class="flex justify-between text-xs text-gray-500 mb-1"><span>Print queue</span><span>100%</span></div>
                  <div class="h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-green-500 rounded-full" style="width: 100%;"></div></div>
                </div>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Skeleton loader</h3>
              <div class="space-y-2">
                <div class="h-4 w-2/3 shimmer rounded"></div>
                <div class="h-3 w-full shimmer rounded"></div>
                <div class="h-3 w-5/6 shimmer rounded"></div>
                <div class="h-3 w-4/6 shimmer rounded"></div>
                <div class="flex gap-2 pt-2">
                  <div class="h-8 w-24 shimmer rounded-xl"></div>
                  <div class="h-8 w-20 shimmer rounded-xl"></div>
                </div>
              </div>
            </div>
            <div class="bg-white rounded-2xl border border-gray-200 p-6 md:col-span-2 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Toast</h3>
              <div class="flex flex-wrap gap-3">
                <button type="button" @click="showToast('success')" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Show success</button>
                <button type="button" @click="showToast('warning')" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Show warning</button>
                <button type="button" @click="showToast('error')"   class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Show error</button>
                <button type="button" @click="showToast('info')"    class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Show info</button>
              </div>
            </div>
          </div>
        </section>

        <!-- 16. OVERLAYS -->
        <section id="overlays" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Overlays</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Modals for destructive confirmations, drawers for filters, tooltips for icon buttons.</p>
          </header>
          <div class="flex flex-wrap gap-3">
            <button type="button" @click="openOverlay('modal', $event)" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition text-sm">Open modal</button>
            <button type="button" @click="openOverlay('drawer', $event)" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Open drawer</button>
            <span class="relative inline-flex group">
              <button type="button" aria-label="Info" class="inline-flex items-center justify-center w-10 h-10 rounded-xl border border-gray-300 bg-white text-gray-700 hover:border-blue-500 hover:text-blue-600 transition"><i class="fa-solid fa-circle-info"></i></button>
              <span class="absolute left-1/2 -translate-x-1/2 bottom-full mb-2 whitespace-nowrap rounded-md bg-gray-900 text-white text-xs px-2 py-1 opacity-0 group-hover:opacity-100 transition pointer-events-none">Tooltip on hover</span>
            </span>
          </div>
        </section>

        <!-- 17. CARDIFY PATTERNS -->
        <section id="patterns" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Cardify patterns</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">High-level compositions of the primitives above, as used in production.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Pricing tile -->
            <div class="bg-white rounded-2xl border-2 border-blue-600 p-6 shadow-lg relative">
              <span class="absolute top-4 right-4 px-2.5 py-1 rounded-full bg-blue-600 text-white text-[10px] font-bold uppercase tracking-wide">Popular</span>
              <h3 class="text-xs font-bold uppercase tracking-wide text-blue-600">Team plan</h3>
              <div class="mt-2 flex items-baseline gap-1">
                <span class="text-4xl font-extrabold text-gray-900">9</span>
                <span class="text-lg font-semibold text-gray-700">OMR</span>
                <span class="text-sm text-gray-500">/ month</span>
              </div>
              <p class="mt-1 text-sm text-gray-500">Up to 24 employee cards. All features.</p>
              <ul class="mt-4 space-y-2 text-sm">
                <?php foreach (['Unlimited QR scans','NFC print orders','Custom domain','Analytics dashboard','Apple + Google Wallet'] as $f): ?>
                  <li class="flex items-start gap-2 text-gray-700"><i class="fa-solid fa-check text-green-600 text-xs mt-1"></i><span><?= $f ?></span></li>
                <?php endforeach; ?>
              </ul>
              <button type="button" class="mt-5 w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition">Get Started Free</button>
            </div>
            <!-- QR share tile -->
            <div class="bg-gradient-to-br from-gray-900 to-gray-800 rounded-2xl p-6 text-white shadow-xl">
              <h3 class="text-xs font-bold uppercase tracking-wide text-blue-300">Share this card</h3>
              <div class="mt-4 flex items-center gap-4">
                <div class="w-24 h-24 rounded-xl bg-white flex items-center justify-center shrink-0">
                  <i class="fa-solid fa-qrcode text-5xl text-gray-900"></i>
                </div>
                <div class="flex-1">
                  <div class="text-sm text-blue-200">Scan with any phone</div>
                  <div class="mt-1 font-mono text-xs text-white/90 break-all">cardify.om/acme/ahmed</div>
                  <div class="mt-3 flex gap-2">
                    <button type="button" aria-label="WhatsApp" class="w-9 h-9 rounded-lg bg-white/10 text-white hover:bg-white/20 transition"><i class="fa-brands fa-whatsapp"></i></button>
                    <button type="button" aria-label="Email"    class="w-9 h-9 rounded-lg bg-white/10 text-white hover:bg-white/20 transition"><i class="fa-solid fa-envelope"></i></button>
                    <button type="button" aria-label="Copy"     class="w-9 h-9 rounded-lg bg-white/10 text-white hover:bg-white/20 transition"><i class="fa-solid fa-copy"></i></button>
                    <button type="button" aria-label="Download" class="w-9 h-9 rounded-lg bg-white/10 text-white hover:bg-white/20 transition"><i class="fa-solid fa-download"></i></button>
                  </div>
                </div>
              </div>
            </div>
            <!-- OTP sign-in pattern -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">OTP sign-in</h3>
              <p class="text-sm text-gray-700">We sent a 6-digit code to <b>+968 9123 4567</b>.</p>
              <div class="flex gap-2 mt-3" dir="ltr">
                <?php foreach (['3','9','1','2','5','8'] as $d): ?>
                  <div class="w-10 h-12 rounded-xl border border-gray-300 bg-white flex items-center justify-center font-mono text-lg font-bold text-gray-900"><?= $d ?></div>
                <?php endforeach; ?>
              </div>
              <p class="text-xs text-gray-500 mt-3">Didn't get it? <a class="text-blue-600 font-semibold cursor-pointer">Resend via WhatsApp</a> or <a class="text-blue-600 font-semibold cursor-pointer">email</a>.</p>
            </div>
            <!-- Print-shop order row -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
              <h3 class="text-xs font-bold uppercase tracking-wide text-gray-500 mb-3">Print order line</h3>
              <div class="flex items-start gap-4">
                <div class="w-16 h-16 rounded-xl bg-gray-100 border border-gray-200 flex items-center justify-center">
                  <i class="fa-solid fa-id-card text-2xl text-gray-400"></i>
                </div>
                <div class="flex-1">
                  <div class="flex items-start justify-between gap-3">
                    <div>
                      <div class="text-sm font-bold text-gray-900">NFC-enabled Business Card · Matte</div>
                      <div class="text-xs text-gray-500 mt-0.5">Qty 100 · OMR 1.250 each · Al Noor Print Oman</div>
                    </div>
                    <div class="text-right">
                      <div class="font-mono text-sm font-bold text-gray-900">OMR 125.000</div>
                      <div class="text-xs text-gray-500">incl. VAT</div>
                    </div>
                  </div>
                  <div class="mt-2 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-700 px-2.5 py-1 text-xs font-semibold"><i class="fa-solid fa-clock text-[10px]"></i> In production</span>
                    <span class="text-xs text-gray-500">ETA 14 Apr</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- 18. RTL -->
        <section id="rtl" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">RTL preview</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Cardify's public profile pages render bilingually. Toggle RTL in the hero to preview the page in Arabic direction.</p>
          </header>
          <div class="bg-white rounded-2xl border border-gray-200 p-6 shadow-sm">
            <div dir="rtl" class="space-y-3">
              <h3 class="text-xl font-bold text-gray-900 text-right">بطاقات العمل الرقمية لفريقك</h3>
              <p class="text-sm text-gray-700 text-right">صمّم مرة واحدة، ووزّع على كل الموظفين. اطبع من أقرب متجر عماني.</p>
              <div class="flex justify-end gap-3">
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition">
                  <span>ابدأ الآن</span>
                  <i class="fa-solid fa-arrow-right rotate-180 text-xs"></i>
                </button>
                <button type="button" class="inline-flex items-center gap-2 px-5 py-2.5 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition">إلغاء</button>
              </div>
            </div>
          </div>
        </section>

        <!-- 19. DO/DON'T -->
        <section id="dos" class="scroll-mt-24 space-y-4">
          <header>
            <h2 class="text-2xl md:text-3xl font-extrabold text-gray-900">Do / Don't</h2>
            <p class="mt-1 text-sm text-gray-500 max-w-prose">Quick rules to keep Cardify on-brand.</p>
          </header>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="rounded-2xl border border-green-200 bg-green-50/50 p-6">
              <h3 class="text-lg font-bold text-green-700 inline-flex items-center gap-2"><i class="fa-solid fa-circle-check"></i> Do</h3>
              <ul class="mt-3 space-y-2 text-sm text-gray-700 list-disc pl-5">
                <li>Use <code class="font-mono text-xs">bg-blue-600</code> for primary CTAs.</li>
                <li>Display OMR amounts with 3 decimal places, <code class="font-mono text-xs">OMR 125.000</code>.</li>
                <li>Use <code class="font-mono text-xs">rounded-2xl</code> for cards and <code class="font-mono text-xs">rounded-lg</code> for buttons.</li>
                <li>Use Font Awesome Brands for social icons (WhatsApp, LinkedIn).</li>
                <li>Prefix Oman phones with <code class="font-mono text-xs">+968</code>.</li>
              </ul>
            </div>
            <div class="rounded-2xl border border-red-200 bg-red-50/50 p-6">
              <h3 class="text-lg font-bold text-red-700 inline-flex items-center gap-2"><i class="fa-solid fa-circle-xmark"></i> Don't</h3>
              <ul class="mt-3 space-y-2 text-sm text-gray-700 list-disc pl-5">
                <li>Don't mix <code class="font-mono text-xs">#2563eb</code> with other blues (no teal, no cyan).</li>
                <li>Don't use sharp <code class="font-mono text-xs">rounded</code> or fully square corners on interactive surfaces.</li>
                <li>Don't fabricate phone numbers or prices in mocks, use the real sample data in this showcase.</li>
                <li>Don't ship emoji in UI copy. Use Font Awesome icons instead.</li>
                <li>Don't place plain email addresses on public pages, always use <code class="font-mono text-xs">mailto:</code> with neutral text.</li>
              </ul>
            </div>
          </div>
          <div class="bg-white rounded-2xl border border-gray-200 p-6 text-sm text-gray-600 shadow-sm">
            Source files: <code class="font-mono text-xs text-gray-900">design-showcase.php</code> · <code class="font-mono text-xs text-gray-900">includes/ui-header.php</code> · <code class="font-mono text-xs text-gray-900">assets/techwind/css/tailwind.min.css</code> · <code class="font-mono text-xs text-gray-900">assets/flowbite/app.css</code>
          </div>
        </section>

      </div>
    </div>
  </div>

  <!-- MODAL -->
  <div x-cloak
       :class="modalOpen ? 'flex' : 'hidden'"
       role="dialog" aria-modal="true" aria-labelledby="showcase-modal-title"
       x-effect="if (modalOpen) focusFirst($refs.modalPanel)"
       @keydown.tab="trapTab($event, $refs.modalPanel)"
       class="fixed inset-0 z-[60] items-center justify-center p-4 bg-gray-900/50" @click="closeOverlays()">
    <div x-ref="modalPanel" tabindex="-1" @click.stop class="bg-white rounded-2xl shadow-2xl border border-gray-200 max-w-md w-full p-6">
      <h3 id="showcase-modal-title" class="text-xl font-bold text-gray-900">Delete this card?</h3>
      <p class="mt-2 text-sm text-gray-500">Ahmed Al-Balushi's digital card at <code class="font-mono text-xs">/acme/ahmed</code> will stop working immediately. Any printed QR codes pointing to this URL will show a "card not found" page.</p>
      <div class="mt-5 flex justify-end gap-2">
        <button type="button" @click="closeOverlays()" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-gray-50 text-gray-700 font-semibold rounded-lg border border-gray-300 transition text-sm">Keep card</button>
        <button type="button" @click="closeOverlays(); showToast('error')" class="inline-flex items-center gap-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-lg transition text-sm">Delete card</button>
      </div>
    </div>
  </div>

  <!-- DRAWER -->
  <div x-cloak
       :class="drawerOpen ? 'flex' : 'hidden'"
       role="dialog" aria-modal="true" aria-labelledby="showcase-drawer-title"
       x-effect="if (drawerOpen) focusFirst($refs.drawerPanel)"
       @keydown.tab="trapTab($event, $refs.drawerPanel)"
       class="fixed inset-0 z-[60] justify-end bg-gray-900/50" @click="closeOverlays()">
    <div x-ref="drawerPanel" tabindex="-1" @click.stop class="bg-white w-full max-w-sm h-full border-l border-gray-200 p-6 shadow-2xl overflow-y-auto">
      <div class="flex items-center justify-between">
        <h3 id="showcase-drawer-title" class="text-lg font-bold text-gray-900">Filter cards</h3>
        <button type="button" @click="closeOverlays()" aria-label="Close" class="text-gray-500 hover:text-gray-900 transition"><i class="fa-solid fa-xmark text-lg"></i></button>
      </div>
      <div class="mt-4 space-y-4">
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Status</label>
          <select class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition bg-white">
            <option>All</option><option>Published</option><option>Draft</option><option>In review</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Department</label>
          <select class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition bg-white">
            <option>All</option><option>Sales</option><option>Marketing</option><option>Engineering</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-1">Created after</label>
          <input type="date" class="w-full px-3 py-2.5 border border-gray-300 rounded-lg focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20 focus:outline-none transition">
        </div>
        <button type="button" @click="closeOverlays(); showToast('success')" class="w-full inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg shadow-lg shadow-blue-600/30 transition">Apply filters</button>
      </div>
    </div>
  </div>

  <!-- TOAST -->
  <div x-cloak x-show="toast" class="fixed z-[70] bottom-6 left-1/2 -translate-x-1/2 toast-in">
    <template x-if="toast === 'success'">
      <div class="bg-white border border-green-200 rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3 max-w-sm">
        <i class="fa-solid fa-circle-check text-green-600 text-lg"></i>
        <span class="text-sm text-gray-900">Changes saved successfully.</span>
      </div>
    </template>
    <template x-if="toast === 'warning'">
      <div class="bg-white border border-amber-200 rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3 max-w-sm">
        <i class="fa-solid fa-triangle-exclamation text-amber-600 text-lg"></i>
        <span class="text-sm text-gray-900">Review this before continuing.</span>
      </div>
    </template>
    <template x-if="toast === 'error'">
      <div class="bg-white border border-red-200 rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3 max-w-sm">
        <i class="fa-solid fa-circle-xmark text-red-600 text-lg"></i>
        <span class="text-sm text-gray-900">Something went wrong. Try again.</span>
      </div>
    </template>
    <template x-if="toast === 'info'">
      <div class="bg-white border border-blue-200 rounded-xl shadow-2xl px-4 py-3 flex items-center gap-3 max-w-sm">
        <i class="fa-solid fa-circle-info text-blue-600 text-lg"></i>
        <span class="text-sm text-gray-900">New card view recorded.</span>
      </div>
    </template>
  </div>

</div>

<script>
// Highlight sticky TOC entry for the visible section
(function() {
  const tocLinks = Array.from(document.querySelectorAll('.sticky-toc a'));
  const sectionIds = tocLinks.map(a => a.getAttribute('href').slice(1));
  const sectionEls = sectionIds.map(id => document.getElementById(id)).filter(Boolean);
  if (!sectionEls.length || !('IntersectionObserver' in window)) return;

  const setActive = (id) => {
    tocLinks.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + id));
  };

  const observer = new IntersectionObserver((entries) => {
    const visible = entries.filter(e => e.isIntersecting).sort((a, b) => a.target.offsetTop - b.target.offsetTop);
    if (visible.length) setActive(visible[0].target.id);
  }, { rootMargin: '-20% 0px -60% 0px', threshold: 0 });

  sectionEls.forEach(el => observer.observe(el));
})();
</script>

<?php require_once INCLUDES_DIR . '/ui-footer.php'; ?>
