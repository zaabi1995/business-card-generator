<?php
/**
 * Cardify Onboarding (3 steps, full best-practices).
 *
 *   1. Brand        - upload logo, palette + favicon auto-extracted, see it
 *                     live across browser tab, portal, sample card. Approve.
 *   2. Card design  - upload PDF business card, system auto-detects layout,
 *                     fonts, fields, QR area. Approve or skip.
 *   3. Launch       - tenant URL + invite link.
 *
 * Best practices applied:
 *   - Resume on refresh (current step persisted in company_onboarding)
 *   - Inline error UI (no alert()), per-field error slots
 *   - ARIA roles: progressbar, region, status; live announcements
 *   - Keyboard navigation: Tab, Shift+Tab, Enter to advance, Esc to back
 *   - Focus management: heading focus on each step transition
 *   - Mobile-responsive grid (collapses 2-col -> stacked under 1024px)
 *   - "Save and finish later" CTA always available
 *   - Reassurance copy on every step ("You can change this later")
 *   - Try sample logo affordance
 *   - Inline help tooltips
 *   - Skeleton placeholders during async work
 *   - i18n / RTL ready (currentDirection() respected)
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';
require_once INCLUDES_DIR . '/Onboarding.php';

if (!Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'login.php');
    exit;
}

$currentRole = Auth::getCurrentRole();
if (!in_array($currentRole, ['company_admin', 'company', 'admin', 'super_admin'], true)) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$companyId = getCurrentCompanyId();
$companySlug = getCurrentCompanySlug();
if (!$companyId) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

$db = Database::getInstance();
$company = $db->fetchOne("SELECT name, slug, admin_email FROM companies WHERE id = :id", ['id' => $companyId]);
$company = $company ?: ['name' => '', 'slug' => $companySlug ?: '', 'admin_email' => ''];

$theme = $db->fetchOne("SELECT primary_color, secondary_color, logo_path, favicon_path FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
$theme = $theme ?: ['primary_color' => '#2d13ea', 'secondary_color' => '#ff7800', 'logo_path' => null, 'favicon_path' => null];

$onboardingState = Onboarding::get($companyId);
$resumeStep = max(1, min(Onboarding::TOTAL_STEPS, (int)($onboardingState['step'] ?? 0) + 1));
if (!empty($onboardingState['completed_at'])) {
    $resumeStep = Onboarding::TOTAL_STEPS;
}

$dir = function_exists('currentDirection') ? currentDirection() : 'ltr';
$pageTitle = 'Welcome to Cardify';
?>
<!DOCTYPE html>
<html lang="en" dir="<?= htmlspecialchars($dir) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root {
    --brand-primary:   <?= htmlspecialchars($theme['primary_color']) ?>;
    --brand-secondary: <?= htmlspecialchars($theme['secondary_color']) ?>;
    --brand-accent:    <?= htmlspecialchars($theme['primary_color']) ?>;
  }
  body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif; background: linear-gradient(180deg, #f5f3ff 0%, #fff 60%); min-height: 100vh; color: #0f172a; }
  *:focus-visible { outline: 2px solid var(--brand-primary); outline-offset: 2px; border-radius: 6px; }

  /* Top bar with Save & finish later */
  .topbar { display: flex; align-items: center; justify-content: space-between; max-width: 1200px; margin: 0 auto; padding: 18px 24px 0; }
  .topbar-brand { font-size: 14px; font-weight: 700; color: var(--brand-primary); display: inline-flex; align-items: center; gap: 8px; }
  .topbar-brand .dot { width: 8px; height: 8px; background: var(--brand-secondary); border-radius: 2px; transform: rotate(45deg); }
  .save-exit { font-size: 13px; color: #6b7280; font-weight: 500; padding: 7px 14px; border-radius: 8px; transition: all .15s; }
  .save-exit:hover { color: #0f172a; background: rgba(0,0,0,0.04); }

  /* Stepper */
  .step-rail { display: flex; align-items: center; justify-content: center; gap: 8px; }
  .step-pill { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; transition: all .25s ease; flex-shrink: 0; }
  .step-pill.active { background: var(--brand-primary); color: #fff; transform: scale(1.06); box-shadow: 0 2px 8px rgba(45,19,234,0.30); }
  .step-pill.done { background: #16a34a; color: #fff; }
  .step-pill.idle { background: #e5e7eb; color: #9ca3af; }
  .step-line { width: 56px; height: 2px; background: #e5e7eb; transition: background .3s; }
  .step-line.done { background: #16a34a; }
  .step-label { font-size: 11px; font-weight: 600; color: #6b7280; transition: color .25s; }
  .step-label.active { color: var(--brand-primary); }
  .step-label.done { color: #16a34a; }

  /* Panels */
  .panel { display: none; }
  .panel.active { display: block; animation: fade .3s ease both; }
  @keyframes fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

  /* Forms */
  .field input, .field select { width: 100%; padding: 12px 14px; font-size: 14px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; transition: border .15s, box-shadow .15s; }
  .field input:focus { outline: none; border-color: var(--brand-primary); box-shadow: 0 0 0 3px rgba(45,19,234,0.10); }
  .field label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; }
  .field .hint { font-size: 11px; color: #9ca3af; margin-top: 5px; }
  .field .err { font-size: 12px; color: #dc2626; margin-top: 6px; display: none; }
  .field.has-error .err { display: block; }
  .field.has-error input { border-color: #dc2626; }

  /* Inline error strip */
  .err-strip { background: #fef2f2; border-left: 3px solid #dc2626; padding: 10px 14px; border-radius: 0 8px 8px 0; font-size: 13px; color: #991b1b; display: none; align-items: center; gap: 8px; }
  .err-strip.shown { display: flex; animation: slideIn .25s ease; }
  @keyframes slideIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: none; } }

  /* Drop zone */
  .upload-zone { border: 2px dashed #cbd5e1; border-radius: 16px; transition: all 0.2s ease; padding: 28px 20px; text-align: center; cursor: pointer; }
  .upload-zone:hover, .upload-zone.drag-over, .upload-zone:focus-within { border-color: var(--brand-primary); background: #f5f3ff; }
  .upload-zone.has-error { border-color: #dc2626; background: #fef2f2; }

  /* Swatch row */
  .swatch { width: 32px; height: 32px; border-radius: 9px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 4px 8px rgba(0,0,0,0.08); }

  /* Buttons */
  .btn-primary { background: var(--brand-primary); color: #fff; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; transition: filter .15s, transform .12s; cursor: pointer; }
  .btn-primary:hover:not(:disabled) { filter: brightness(1.07); }
  .btn-primary:active:not(:disabled) { transform: translateY(1px); }
  .btn-primary:disabled { background: #c7d2fe; cursor: not-allowed; }
  .btn-secondary { background: #fff; border: 1px solid #e5e7eb; color: #374151; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; cursor: pointer; }
  .btn-secondary:hover { background: #f9fafb; }

  /* Live preview pane */
  .preview-stack { display: flex; flex-direction: column; gap: 16px; }
  .preview-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.4px; text-transform: uppercase; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
  .preview-label .dot { width: 5px; height: 5px; background: #16a34a; border-radius: 50%; animation: pulse 2s ease-in-out infinite; }
  @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

  /* Browser frame for tab favicon */
  .browser { background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(15,23,42,0.10); overflow: hidden; border: 1px solid #e5e7eb; }
  .browser-bar { background: #f1f5f9; padding: 8px 12px 0; display: flex; align-items: flex-end; gap: 8px; border-bottom: 1px solid #e5e7eb; }
  .browser-dots { display: flex; gap: 5px; padding-bottom: 9px; }
  .browser-dot { width: 9px; height: 9px; border-radius: 50%; }
  .browser-dot:nth-child(1) { background: #f87171; }
  .browser-dot:nth-child(2) { background: #fbbf24; }
  .browser-dot:nth-child(3) { background: #34d399; }
  .browser-tab { background: #fff; padding: 7px 14px 7px 11px; border-radius: 8px 8px 0 0; display: flex; align-items: center; gap: 7px; font-size: 12px; color: #0f172a; max-width: 240px; overflow: hidden; }
  .browser-tab img { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; background: #e5e7eb; }
  .browser-tab .tab-title { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-weight: 500; }
  .browser-url { flex: 1; background: #fff; border-radius: 7px; padding: 5px 10px; font-size: 11px; color: #6b7280; margin-bottom: 9px; font-family: 'SF Mono', Menlo, monospace; }

  /* Portal preview */
  .portal-preview { background: #fff; padding: 22px; min-height: 110px; }
  .portal-logo { max-height: 28px; max-width: 120px; display: block; }
  .portal-card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px 16px; margin-top: 14px; max-width: 280px; }
  .portal-card-head { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
  .portal-card-head .ico { width: 32px; height: 32px; border-radius: 9px; background: var(--brand-primary); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 14px; }
  .portal-card-head h4 { font-size: 13px; font-weight: 700; color: #0f172a; margin: 0; }
  .portal-card-head p { font-size: 11px; color: #64748b; margin: 1px 0 0 0; }
  .portal-cta { background: var(--brand-primary); color: #fff; padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; text-align: center; }

  /* Card preview */
  .card-preview { border-radius: 12px; padding: 22px; color: #fff; box-shadow: 0 8px 24px rgba(15,23,42,0.12); }
  .card-preview-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
  .card-preview-logo { max-height: 22px; max-width: 110px; filter: brightness(0) invert(1); }
  .card-preview-pill { padding: 4px 11px; border-radius: 999px; font-size: 10px; font-weight: 700; }
  .card-preview .name { font-size: 18px; font-weight: 800; letter-spacing: -0.3px; }
  .card-preview .pos { font-size: 11px; opacity: 0.85; margin-top: 2px; }
  .card-preview .pills { display: flex; gap: 6px; margin-top: 14px; }
  .card-preview .pill { padding: 5px 12px; border-radius: 999px; font-size: 11px; font-weight: 700; }

  /* Skeleton placeholders */
  .skel { background: linear-gradient(90deg, #f1f5f9 0%, #e2e8f0 50%, #f1f5f9 100%); background-size: 200% 100%; animation: skel 1.4s ease-in-out infinite; border-radius: 6px; }
  @keyframes skel { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

  /* Tooltip */
  .help { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; border-radius: 50%; background: #f1f5f9; color: #6b7280; font-size: 10px; margin-left: 5px; cursor: help; vertical-align: middle; }
  .help:hover { background: #e0e7ff; color: var(--brand-primary); }
  .help[data-tip]:hover::after { content: attr(data-tip); position: absolute; transform: translate(8px, -50%); background: #0f172a; color: #fff; padding: 7px 11px; border-radius: 6px; font-size: 11px; font-weight: 500; white-space: nowrap; max-width: 240px; white-space: normal; width: 220px; z-index: 50; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }

  /* Reassurance text */
  .reassurance { font-size: 11px; color: #6b7280; display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; background: #f8fafc; border-radius: 6px; }
  .reassurance i { color: #16a34a; }

  .url-card { background: #0f172a; color: #fff; border-radius: 11px; padding: 12px 16px; font-family: 'SF Mono', Menlo, monospace; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .copy-btn { background: rgba(255,255,255,0.10); padding: 5px 11px; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; transition: background .15s; flex-shrink: 0; }
  .copy-btn:hover { background: rgba(255,255,255,0.18); }

  /* Sample link */
  .sample-link { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--brand-primary); font-weight: 600; cursor: pointer; }
  .sample-link:hover { text-decoration: underline; }

  /* Mobile: stack the 2-column layout */
  @media (max-width: 1023px) {
    .grid-2col { grid-template-columns: 1fr !important; }
    .step-line { width: 32px; }
    .step-rail-labels { gap: 32px !important; }
  }

  /* Visually hidden but accessible to screen readers */
  .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
</style>
</head>
<body>

<!-- Top bar with brand + Save and finish later -->
<header class="topbar">
  <div class="topbar-brand">
    <span>Cardify</span>
    <span class="dot"></span>
    <span style="color: #6b7280; font-weight: 500; font-size: 12px;">Setup</span>
  </div>
  <a href="<?= htmlspecialchars(getBasePath() . 'admin/') ?>" class="save-exit" id="save-exit">
    <i class="fa-solid fa-arrow-right-from-bracket fa-flip-horizontal mr-1" aria-hidden="true"></i>
    Save and finish later
  </a>
</header>

<main class="max-w-5xl mx-auto px-6 py-8">

  <!-- Stepper -->
  <div class="step-rail mb-3" role="progressbar" aria-valuemin="1" aria-valuemax="3" aria-valuenow="<?= (int)$resumeStep ?>" aria-label="Onboarding progress">
    <div id="step-pill-1" class="step-pill <?= $resumeStep === 1 ? 'active' : ($resumeStep > 1 ? 'done' : 'idle') ?>" aria-current="<?= $resumeStep === 1 ? 'step' : 'false' ?>">1</div>
    <div id="step-line-1" class="step-line <?= $resumeStep > 1 ? 'done' : '' ?>"></div>
    <div id="step-pill-2" class="step-pill <?= $resumeStep === 2 ? 'active' : ($resumeStep > 2 ? 'done' : 'idle') ?>" aria-current="<?= $resumeStep === 2 ? 'step' : 'false' ?>">2</div>
    <div id="step-line-2" class="step-line <?= $resumeStep > 2 ? 'done' : '' ?>"></div>
    <div id="step-pill-3" class="step-pill <?= $resumeStep === 3 ? 'active' : 'idle' ?>" aria-current="<?= $resumeStep === 3 ? 'step' : 'false' ?>">3</div>
  </div>
  <div class="flex items-center justify-center mb-10 step-rail-labels" style="gap: 70px;">
    <span id="step-label-1" class="step-label <?= $resumeStep === 1 ? 'active' : ($resumeStep > 1 ? 'done' : '') ?>">Brand</span>
    <span id="step-label-2" class="step-label <?= $resumeStep === 2 ? 'active' : ($resumeStep > 2 ? 'done' : '') ?>">Card design</span>
    <span id="step-label-3" class="step-label <?= $resumeStep === 3 ? 'active' : '' ?>">Launch</span>
  </div>

  <!-- ARIA live region for screen reader step announcements -->
  <div id="aria-status" class="sr-only" role="status" aria-live="polite"></div>

  <!-- =========================== STEP 1: BRAND =========================== -->
  <section id="panel-1" class="panel <?= $resumeStep === 1 ? 'active' : '' ?>" role="region" aria-label="Step 1 of 3, Brand">

    <div class="grid grid-2col gap-6" style="grid-template-columns: 1fr 1fr;">

      <!-- Left: upload -->
      <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-7">
        <h1 class="text-xl font-bold text-gray-900 mb-1" tabindex="-1" id="step1-heading">Upload your brand</h1>
        <p class="text-gray-500 text-sm mb-3">We extract your colours and a square favicon automatically. Watch the live preview update on the right.</p>
        <span class="reassurance mb-5"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Your logo stays private. You can change this later.</span>

        <div id="step1-error" class="err-strip mb-4" role="alert">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
          <span id="step1-error-msg">Something went wrong</span>
        </div>

        <label for="logo-input" id="upload-zone" class="upload-zone block mt-4" tabindex="0" role="button" aria-label="Upload your logo, drop a file or press Enter">
          <div id="upload-empty">
            <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
              <i class="fa-solid fa-cloud-arrow-up text-lg" style="color: var(--brand-primary);" aria-hidden="true"></i>
            </div>
            <p class="text-gray-700 font-semibold text-sm">Drop your logo</p>
            <p class="text-gray-400 text-xs mt-1">or tap to browse · PNG, JPG, SVG, WebP, 5 MB max</p>
            <a href="#" id="try-sample" class="sample-link mt-3 inline-flex">
              <i class="fa-regular fa-image text-xs"></i> Try with a sample logo
            </a>
          </div>
          <div id="upload-loaded" class="hidden">
            <img id="logo-thumb" class="max-h-16 mx-auto mb-2" alt="Your uploaded logo">
            <p id="upload-status" class="text-xs" role="status"></p>
          </div>
        </label>
        <input type="file" id="logo-input" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/gif,image/webp" class="hidden" aria-describedby="logo-help">
        <span id="logo-help" class="sr-only">Supported formats: PNG, JPG, SVG, WebP. Maximum size 5 megabytes.</span>

        <div id="palette-row" class="mt-5 hidden" role="region" aria-label="Detected brand palette">
          <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 flex items-center">
            Palette
            <span class="help" data-tip="Primary is your main brand colour. Secondary is your accent. We pick these from the most prominent saturated colours in your logo." aria-label="What is a palette?">i</span>
          </div>
          <div class="flex flex-wrap items-center gap-4">
            <div class="flex items-center gap-2">
              <div class="swatch" id="sw-primary"></div>
              <div><div class="text-xs text-gray-500">Primary</div><div class="text-xs font-mono font-semibold" id="hex-primary"></div></div>
            </div>
            <div class="flex items-center gap-2">
              <div class="swatch" id="sw-secondary"></div>
              <div><div class="text-xs text-gray-500">Secondary</div><div class="text-xs font-mono font-semibold" id="hex-secondary"></div></div>
            </div>
            <div class="flex items-center gap-2">
              <div class="swatch" id="sw-accent"></div>
              <div><div class="text-xs text-gray-500">Accent</div><div class="text-xs font-mono font-semibold" id="hex-accent"></div></div>
            </div>
          </div>
        </div>

        <div class="mt-7 flex justify-end">
          <button id="approve-brand" class="btn-primary inline-flex items-center gap-2" disabled>
            Approve and continue <i class="fa-solid fa-arrow-right text-sm" aria-hidden="true"></i>
          </button>
        </div>
      </div>

      <!-- Right: live preview pane -->
      <div class="preview-stack">

        <!-- Browser tab -->
        <div>
          <div class="preview-label"><span class="dot"></span> Browser tab</div>
          <div class="browser">
            <div class="browser-bar">
              <div class="browser-dots"><span class="browser-dot"></span><span class="browser-dot"></span><span class="browser-dot"></span></div>
              <div class="browser-tab" id="tab">
                <img id="tab-favicon" alt="" <?= $theme['favicon_path'] ? 'src="' . htmlspecialchars(getBasePath() . ltrim($theme['favicon_path'], '/')) . '"' : '' ?>>
                <span class="tab-title" id="tab-title"><?= htmlspecialchars(($company['name'] ?? '') ?: 'Your Company') ?> · Cardify</span>
              </div>
            </div>
            <div class="browser-bar" style="border-bottom: none; padding-bottom: 0;">
              <div class="browser-url" id="tab-url" style="margin-top: 0;"><?= htmlspecialchars(($company['slug'] ?? '') ?: 'yourcompany') ?>.cardify.om</div>
            </div>
          </div>
        </div>

        <!-- Portal preview -->
        <div>
          <div class="preview-label"><span class="dot"></span> Employee portal</div>
          <div class="browser">
            <div class="portal-preview">
              <?php if ($theme['logo_path']): ?>
                <img id="portal-logo" class="portal-logo" src="<?= htmlspecialchars(getBasePath() . ltrim($theme['logo_path'], '/')) ?>" alt="">
              <?php else: ?>
                <img id="portal-logo" class="portal-logo" alt="" style="display:none;">
                <div id="portal-skel" class="skel" style="height: 28px; width: 120px;"></div>
              <?php endif; ?>
              <div class="portal-card">
                <div class="portal-card-head">
                  <div class="ico" id="portal-ico"><?= htmlspecialchars(strtoupper(substr(($company['name'] ?? '') ?: 'C', 0, 1))) ?></div>
                  <div>
                    <h4>Request your business card</h4>
                    <p>One-time form, takes a minute.</p>
                  </div>
                </div>
                <div class="portal-cta" id="portal-cta">Get started</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Card preview -->
        <div>
          <div class="preview-label"><span class="dot"></span> Sample card</div>
          <div class="card-preview" id="card-preview" style="background: linear-gradient(135deg, var(--brand-primary) 0%, color-mix(in srgb, var(--brand-primary) 70%, black) 100%);">
            <div class="card-preview-head">
              <img id="card-logo" class="card-preview-logo" alt="" <?= $theme['logo_path'] ? 'src="' . htmlspecialchars(getBasePath() . ltrim($theme['logo_path'], '/')) . '"' : '' ?>>
              <span class="card-preview-pill" id="card-pill" style="background: var(--brand-secondary);">Live</span>
            </div>
            <div class="name">Eng. Ahmed Al Balushi</div>
            <div class="pos">Senior Cloud Solutions Architect</div>
            <div class="pills">
              <span class="pill" id="pill-call" style="background: var(--brand-secondary);">Call</span>
              <span class="pill" style="background: rgba(255,255,255,0.15);">WhatsApp</span>
              <span class="pill" style="background: rgba(255,255,255,0.15);">Email</span>
            </div>
          </div>
        </div>

      </div>
    </div>
  </section>

  <!-- =========================== STEP 2: CARD DESIGN =========================== -->
  <section id="panel-2" class="panel <?= $resumeStep === 2 ? 'active' : '' ?>" role="region" aria-label="Step 2 of 3, Card design">
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-8 max-w-2xl mx-auto">
      <h1 class="text-xl font-bold text-gray-900 mb-1" tabindex="-1" id="step2-heading">Upload your card design (PDF)</h1>
      <p class="text-gray-500 text-sm mb-3">Cardify reads the layout, fonts and QR area automatically. Skip if you don't have one yet, your team can use a default.</p>
      <span class="reassurance mb-6"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Skip is fine. You can upload a PDF later from the template editor.</span>

      <div id="step2-error" class="err-strip mb-4 mt-4" role="alert">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span id="step2-error-msg">Something went wrong</span>
      </div>

      <label for="pdf-input" id="pdf-zone" class="upload-zone block mb-4 mt-4" tabindex="0" role="button" aria-label="Upload your business card PDF">
        <div id="pdf-empty">
          <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
            <i class="fa-solid fa-file-pdf text-lg" style="color: var(--brand-primary);" aria-hidden="true"></i>
          </div>
          <p class="text-gray-700 font-semibold text-sm">Drop your business card PDF</p>
          <p class="text-gray-400 text-xs mt-1">2 pages preferred (front + back) · 25 MB max</p>
          <a href="<?= htmlspecialchars(getBasePath() . 'uploads/docs/Cardify-PDF-Design-Guide.pdf') ?>" target="_blank" class="sample-link mt-3 inline-flex">
            <i class="fa-solid fa-circle-info text-xs"></i> How to prepare your PDF
          </a>
        </div>
        <div id="pdf-loaded" class="hidden">
          <p id="pdf-status" class="text-sm" role="status"></p>
          <div id="pdf-summary" class="text-xs text-gray-500 mt-2"></div>
        </div>
      </label>
      <input type="file" id="pdf-input" accept="application/pdf,.pdf" class="hidden">

      <div class="flex justify-between mt-6 flex-wrap gap-3">
        <button class="btn-secondary inline-flex items-center gap-2" data-back="1">
          <i class="fa-solid fa-arrow-left text-sm" aria-hidden="true"></i> Back
        </button>
        <div class="flex gap-3">
          <button id="skip-pdf" class="btn-secondary">Skip</button>
          <button id="approve-pdf" class="btn-primary inline-flex items-center gap-2" disabled>
            Approve and continue <i class="fa-solid fa-check text-sm" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    </div>
  </section>

  <!-- =========================== STEP 3: LAUNCH =========================== -->
  <section id="panel-3" class="panel <?= $resumeStep === 3 ? 'active' : '' ?>" role="region" aria-label="Step 3 of 3, Launch">
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-8 max-w-2xl mx-auto text-center">
      <div class="w-14 h-14 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-3">
        <i class="fa-solid fa-rocket text-xl text-green-600" aria-hidden="true"></i>
      </div>
      <h1 class="text-2xl font-bold text-gray-900 mb-1" tabindex="-1" id="step3-heading">You're live</h1>
      <p class="text-gray-500 text-sm mb-7">Share the link or invite your first employee.</p>

      <div id="step3-error" class="err-strip mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
        <span id="step3-error-msg">Something went wrong</span>
      </div>

      <div class="text-left mb-4">
        <label class="text-xs font-semibold text-gray-500 flex items-center">
          Tenant URL
          <span class="help" data-tip="This is the unique address for your team. Share it with employees so they can request their cards.">i</span>
        </label>
        <div class="url-card mt-2">
          <span id="tenant-url"></span>
          <button class="copy-btn" id="copy-url" aria-label="Copy URL to clipboard">Copy</button>
        </div>
      </div>

      <div class="bg-gray-50 border border-gray-100 rounded-xl p-5 text-left">
        <h3 class="text-sm font-semibold text-gray-800 mb-1">Invite your first employee</h3>
        <p class="text-xs text-gray-500 mb-3">They get a sign-in link to fill in their card.</p>
        <div class="field" id="field-invite">
          <label for="invite-email" class="sr-only">Employee email</label>
          <input type="email" id="invite-email" placeholder="employee@yourcompany.om" autocomplete="email">
          <div class="err" id="invite-err">Please enter a valid email.</div>
        </div>
        <button id="btn-invite" class="btn-primary mt-3 inline-flex items-center gap-2 w-full justify-center">
          Send invite <i class="fa-solid fa-paper-plane text-sm" aria-hidden="true"></i>
        </button>
        <p id="invite-status" class="text-xs mt-2 hidden" role="status"></p>
      </div>

      <div class="flex justify-between gap-3 mt-7 flex-wrap">
        <button class="btn-secondary inline-flex items-center gap-2 flex-shrink-0" data-back="2">
          <i class="fa-solid fa-arrow-left text-sm" aria-hidden="true"></i> Back
        </button>
        <button id="btn-finish" class="btn-primary inline-flex items-center gap-2 flex-grow justify-center">
          Open dashboard <i class="fa-solid fa-arrow-right text-sm" aria-hidden="true"></i>
        </button>
      </div>
    </div>
  </section>

</main>

<script>
const basePath = <?= json_encode(getBasePath()) ?>;
const adminBase = basePath + 'admin/';
const initialSlug = <?= json_encode(($company['slug'] ?? '') ?: '') ?>;
const initialName = <?= json_encode(($company['name'] ?? '') ?: 'Your Company') ?>;
const initialState = <?= json_encode([
    'theme'   => $theme,
    'company' => $company,
    'step'    => $resumeStep,
]) ?>;

let currentSlug = initialSlug;
let theme = initialState.theme || null;
let currentStep = initialState.step || 1;

const ariaStatus = document.getElementById('aria-status');

// ---- Step navigation ----
function goToStep(n, { record = true } = {}) {
  for (let i = 1; i <= 3; i++) {
    document.getElementById('panel-' + i).classList.toggle('active', i === n);
    const pill = document.getElementById('step-pill-' + i);
    const label = document.getElementById('step-label-' + i);
    pill.classList.remove('active','done','idle');
    label.classList.remove('active','done');
    pill.removeAttribute('aria-current');
    if (i < n) { pill.classList.add('done'); label.classList.add('done'); }
    else if (i === n) { pill.classList.add('active'); label.classList.add('active'); pill.setAttribute('aria-current','step'); }
    else { pill.classList.add('idle'); }
  }
  for (let i = 1; i <= 2; i++) {
    document.getElementById('step-line-' + i).classList.toggle('done', i < n);
  }
  document.querySelector('[role="progressbar"]').setAttribute('aria-valuenow', n);

  // Focus the heading of the new step (so screen readers announce it,
  // and keyboard users land on the right place).
  const heading = document.getElementById('step' + n + '-heading');
  if (heading) {
    heading.focus({ preventScroll: false });
    ariaStatus.textContent = 'Step ' + n + ' of 3';
  }

  currentStep = n;
  if (record) recordStep(n);

  if (n === 3) fillTenantUrl();

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('[data-back]').forEach(btn =>
  btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.back)))
);

// Persist current step server-side so close-and-return resumes here
async function recordStep(n) {
  try {
    const fd = new FormData();
    fd.append('step', n);
    await fetch(adminBase + 'record_step.php', { method: 'POST', credentials: 'same-origin', body: fd });
  } catch (e) { /* non-blocking */ }
}

// ---- Inline error helper ----
function showError(panel, msg) {
  const el = document.getElementById('step' + panel + '-error');
  const msgEl = document.getElementById('step' + panel + '-error-msg');
  if (msg) { msgEl.textContent = msg; el.classList.add('shown'); }
  else el.classList.remove('shown');
}
function clearAllErrors() {
  ['1','2','3'].forEach(p => showError(p, null));
}

// ---- Live update of preview surfaces from a theme object ----
function applyThemeToPreview(t) {
  document.documentElement.style.setProperty('--brand-primary', t.primary);
  document.documentElement.style.setProperty('--brand-secondary', t.secondary);
  document.documentElement.style.setProperty('--brand-accent', t.accent);

  ['primary','secondary','accent'].forEach(k => {
    const sw = document.getElementById('sw-' + k);
    const hex = document.getElementById('hex-' + k);
    if (sw) sw.style.background = t[k];
    if (hex) hex.textContent = t[k];
  });

  if (t.favicon_url) document.getElementById('tab-favicon').src = basePath + t.favicon_url.replace(/^\//,'');
  document.getElementById('tab-title').textContent = (t.company_name || initialName) + ' · Cardify';
  if (t.tenant_url) document.getElementById('tab-url').textContent = t.tenant_url.replace(/^https?:\/\//,'');

  const portalLogo = document.getElementById('portal-logo');
  const portalSkel = document.getElementById('portal-skel');
  if (t.logo_url) {
    portalLogo.src = basePath + t.logo_url.replace(/^\//,'');
    portalLogo.style.display = 'block';
    if (portalSkel) portalSkel.style.display = 'none';
  }
  document.getElementById('portal-ico').textContent = ((t.company_name || initialName).trim()[0] || 'C').toUpperCase();

  document.getElementById('card-preview').style.background = `linear-gradient(135deg, ${t.primary} 0%, color-mix(in srgb, ${t.primary} 70%, black) 100%)`;
  document.getElementById('card-pill').style.background = t.secondary;
  document.getElementById('pill-call').style.background = t.secondary;
  if (t.logo_url) document.getElementById('card-logo').src = basePath + t.logo_url.replace(/^\//,'');
}

// Apply server-stored theme on first load (so refresh shows the saved palette)
if (theme && theme.primary_color) {
  applyThemeToPreview({
    primary: theme.primary_color, secondary: theme.secondary_color,
    accent: theme.primary_color, logo_url: theme.logo_path, favicon_url: theme.favicon_path,
    company_name: initialName,
  });
  if (theme.logo_path) document.getElementById('approve-brand').disabled = false;
}

// ---- Step 1: logo upload ----
const zone = document.getElementById('upload-zone');
const input = document.getElementById('logo-input');
const empty = document.getElementById('upload-empty');
const loaded = document.getElementById('upload-loaded');
const status = document.getElementById('upload-status');

input.addEventListener('change', e => { if (e.target.files[0]) handleLogo(e.target.files[0]); });
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) handleLogo(e.dataTransfer.files[0]);
});
zone.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); }
});

document.getElementById('try-sample').addEventListener('click', async e => {
  e.preventDefault();
  // Fetch the bundled sample logo and treat it as if the user uploaded it.
  try {
    const res = await fetch(basePath + 'assets/images/sample-logo.png');
    if (!res.ok) throw new Error('Sample not available');
    const blob = await res.blob();
    const file = new File([blob], 'sample-logo.png', { type: 'image/png' });
    handleLogo(file);
  } catch (err) {
    showError('1', 'Could not load sample logo. Try uploading your own.');
  }
});

async function handleLogo(file) {
  clearAllErrors();
  if (file.size > 5 * 1024 * 1024) {
    showError('1', 'File is too large. Maximum 5 MB.');
    return;
  }
  const allowed = ['image/png','image/jpeg','image/jpg','image/svg+xml','image/gif','image/webp'];
  if (file.type && !allowed.includes(file.type)) {
    showError('1', 'Unsupported format. Please upload PNG, JPG, SVG, or WebP.');
    return;
  }

  const reader = new FileReader();
  reader.onload = ev => {
    document.getElementById('logo-thumb').src = ev.target.result;
    document.getElementById('portal-logo').src = ev.target.result;
    document.getElementById('portal-logo').style.display = 'block';
    const skel = document.getElementById('portal-skel'); if (skel) skel.style.display = 'none';
    document.getElementById('card-logo').src = ev.target.result;
    document.getElementById('tab-favicon').src = ev.target.result;
  };
  reader.readAsDataURL(file);

  empty.classList.add('hidden');
  loaded.classList.remove('hidden');
  status.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Reading colours and building favicon...</span>';

  const fd = new FormData(); fd.append('logo', file);
  try {
    const res = await fetch(adminBase + 'apply_theme.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (!res.ok || data.error) {
      const msg = humanError(data.error) || 'Upload failed (HTTP ' + res.status + ')';
      showError('1', msg);
      status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + msg + '</span>';
      return;
    }
    theme = data;
    document.getElementById('palette-row').classList.remove('hidden');
    applyThemeToPreview(data);
    status.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Saved. Brand applied to portal, browser tab and sample card.</span>';
    document.getElementById('approve-brand').disabled = false;
  } catch (err) {
    showError('1', 'Network error: ' + err.message + '. Try again.');
  }
}

document.getElementById('approve-brand').addEventListener('click', () => goToStep(2));

// ---- Step 2: PDF ----
const pdfZone = document.getElementById('pdf-zone');
const pdfInput = document.getElementById('pdf-input');
const pdfEmpty = document.getElementById('pdf-empty');
const pdfLoaded = document.getElementById('pdf-loaded');
const pdfStatus = document.getElementById('pdf-status');

pdfInput.addEventListener('change', e => { if (e.target.files[0]) handlePdf(e.target.files[0]); });
pdfZone.addEventListener('dragover', e => { e.preventDefault(); pdfZone.classList.add('drag-over'); });
pdfZone.addEventListener('dragleave', () => pdfZone.classList.remove('drag-over'));
pdfZone.addEventListener('drop', e => {
  e.preventDefault(); pdfZone.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) handlePdf(e.dataTransfer.files[0]);
});
pdfZone.addEventListener('keydown', e => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pdfInput.click(); }
});

async function handlePdf(file) {
  clearAllErrors();
  if (file.size > 25 * 1024 * 1024) { showError('2', 'PDF too large. Max 25 MB.'); return; }
  if (file.type !== 'application/pdf') { showError('2', 'Please upload a PDF file.'); return; }

  pdfEmpty.classList.add('hidden');
  pdfLoaded.classList.remove('hidden');
  pdfStatus.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Analysing card design...</span>';
  document.getElementById('pdf-summary').textContent = '';

  const fd = new FormData(); fd.append('pdf', file); fd.append('csrf_token', '<?= generateCSRFToken() ?>');
  try {
    const res = await fetch(basePath + 'printshop/import_pdf.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (!res.ok || data.error) {
      const msg = humanError(data.error) || 'Could not analyse the PDF (HTTP ' + res.status + ')';
      showError('2', msg);
      pdfStatus.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + msg + '</span>';
      return;
    }
    const fields = data.pages.reduce((n, p) => n + p.fields.length, 0);
    const qrCount = data.pages.filter(p => p.qr_area).length;
    pdfStatus.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Card design analysed.</span>';
    document.getElementById('pdf-summary').textContent =
      `${data.pages.length} pages · ${fields} fields detected · ${qrCount > 0 ? 'QR area found' : 'no QR placeholder'} · ${data.missing_fonts.length} missing font${data.missing_fonts.length === 1 ? '' : 's'}`;
    document.getElementById('approve-pdf').disabled = false;
  } catch (err) {
    showError('2', 'Network error: ' + err.message + '. Try again.');
  }
}

document.getElementById('skip-pdf').addEventListener('click', () => goToStep(3));
document.getElementById('approve-pdf').addEventListener('click', () => goToStep(3));

// ---- Step 3 ----
function fillTenantUrl() {
  const url = (theme && theme.tenant_url) || ('https://' + (currentSlug || 'yourcompany') + '.cardify.om/');
  document.getElementById('tenant-url').textContent = url;
}

document.getElementById('copy-url').addEventListener('click', () => {
  const url = document.getElementById('tenant-url').textContent;
  navigator.clipboard.writeText(url).then(() => {
    const btn = document.getElementById('copy-url');
    btn.textContent = 'Copied'; setTimeout(() => btn.textContent = 'Copy', 1500);
  });
});

document.getElementById('btn-invite').addEventListener('click', async () => {
  clearAllErrors();
  const email = document.getElementById('invite-email').value.trim();
  const status = document.getElementById('invite-status');
  const field = document.getElementById('field-invite');
  field.classList.remove('has-error');
  status.classList.remove('hidden','text-red-600','text-green-600','text-blue-600');
  if (!email.includes('@') || !email.includes('.')) {
    field.classList.add('has-error');
    return;
  }
  status.classList.add('text-blue-600'); status.textContent = 'Sending...';
  const fd = new FormData(); fd.append('email', email);
  try {
    const res = await fetch(adminBase + 'invite_first.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (data.ok) {
      status.classList.remove('text-blue-600'); status.classList.add('text-green-600');
      status.textContent = 'Invite sent to ' + email;
    } else {
      status.classList.remove('text-blue-600'); status.classList.add('text-red-600');
      status.textContent = humanError(data.error) || 'Could not send invite';
    }
  } catch (err) {
    status.classList.add('text-red-600'); status.textContent = err.message;
  }
});

document.getElementById('btn-finish').addEventListener('click', () => {
  recordStep(3);
  window.location.href = adminBase;
});

// ---- Keyboard shortcuts (Esc to go back) ----
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    if (currentStep > 1) goToStep(currentStep - 1);
  }
});

// ---- Translate machine error codes to friendly copy ----
function humanError(code) {
  return ({
    'no_pdf_uploaded':       'No PDF was selected.',
    'pdf_too_large':         'PDF is over the 25 MB limit.',
    'not_a_pdf':             'That file is not a PDF.',
    'pdf_corrupt':           'The PDF could not be read. Try re-exporting it.',
    'too_many_pages':        'PDF has too many pages, business cards should be 1-2 pages.',
    'no_logo_uploaded':      'No logo was selected.',
    'unsupported_format':    'Unsupported logo format. Please upload PNG, JPG, SVG, or WebP.',
    'logo_too_large':        'Logo is over the 5 MB limit.',
    'cannot_save_logo':      'We could not save your logo. Try again.',
    'no_company_context':    'Could not identify your company. Please sign in again.',
    'forbidden':             'You do not have permission to do that.',
    'invalid_input':         'Some required information is missing.',
    'invalid_email':         'That email address looks wrong.',
    'could_not_create_employee': 'Could not create the employee. They may already exist.',
  })[code] || null;
}

// On first load: announce step + record it (so resumed sessions update the timestamp)
ariaStatus.textContent = 'Step ' + currentStep + ' of 3';
recordStep(currentStep);
</script>
</body>
</html>
