<?php
/**
 * Cardify Onboarding (3 steps with live preview).
 *
 *   1. Brand    - upload logo, palette + favicon auto-extracted, see it live
 *                 across browser tab, portal, and a sample card. Approve.
 *   2. Card     - upload PDF business card, system auto-detects layout,
 *                 fonts, fields and the QR area, then saves as the master
 *                 template for this tenant. Approve.
 *   3. Launch   - tenant URL + invite link to share with the team.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/CardifyConvention.php';

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

$pageTitle = 'Welcome to Cardify';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root {
    --brand-primary:   #2d13ea;
    --brand-secondary: #ff7800;
    --brand-accent:    #d0ea13;
  }
  body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif; background: linear-gradient(180deg, #f5f3ff 0%, #fff 60%); min-height: 100vh; color: #0f172a; }

  /* Stepper */
  .step-rail { display: flex; align-items: center; justify-content: center; gap: 8px; }
  .step-pill { width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; transition: all .25s ease; flex-shrink: 0; }
  .step-pill.active { background: var(--brand-primary); color: #fff; transform: scale(1.06); }
  .step-pill.done { background: #16a34a; color: #fff; }
  .step-pill.idle { background: #e5e7eb; color: #9ca3af; }
  .step-line { width: 56px; height: 2px; background: #e5e7eb; }
  .step-line.done { background: #16a34a; }
  .step-label { font-size: 11px; font-weight: 600; color: #6b7280; }
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

  /* Drop zone */
  .upload-zone { border: 2px dashed #cbd5e1; border-radius: 16px; transition: all 0.2s ease; padding: 28px 20px; text-align: center; cursor: pointer; }
  .upload-zone:hover, .upload-zone.drag-over { border-color: var(--brand-primary); background: #f5f3ff; }

  /* Swatch row */
  .swatch { width: 32px; height: 32px; border-radius: 9px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 4px 8px rgba(0,0,0,0.08); }

  /* Buttons */
  .btn-primary { background: var(--brand-primary); color: #fff; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; transition: filter .15s, transform .12s; }
  .btn-primary:hover { filter: brightness(1.07); }
  .btn-primary:active { transform: translateY(1px); }
  .btn-primary:disabled { background: #c7d2fe; cursor: not-allowed; }
  .btn-secondary { background: #fff; border: 1px solid #e5e7eb; color: #374151; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; }

  /* Live preview pane */
  .preview-stack { display: flex; flex-direction: column; gap: 16px; }
  .preview-label { font-size: 10px; font-weight: 700; color: #94a3b8; letter-spacing: 1.4px; text-transform: uppercase; margin-bottom: 6px; }

  /* Browser frame for tab favicon */
  .browser { background: #fff; border-radius: 12px; box-shadow: 0 8px 30px rgba(15,23,42,0.10); overflow: hidden; border: 1px solid #e5e7eb; }
  .browser-bar { background: #f1f5f9; padding: 8px 12px 0; display: flex; align-items: flex-end; gap: 8px; border-bottom: 1px solid #e5e7eb; }
  .browser-dots { display: flex; gap: 5px; padding-bottom: 9px; }
  .browser-dot { width: 9px; height: 9px; border-radius: 50%; background: #cbd5e1; }
  .browser-dot:nth-child(1) { background: #f87171; }
  .browser-dot:nth-child(2) { background: #fbbf24; }
  .browser-dot:nth-child(3) { background: #34d399; }
  .browser-tab { background: #fff; padding: 7px 14px 7px 11px; border-radius: 8px 8px 0 0; display: flex; align-items: center; gap: 7px; font-size: 12px; color: #0f172a; max-width: 220px; overflow: hidden; }
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

  .url-card { background: #0f172a; color: #fff; border-radius: 11px; padding: 12px 16px; font-family: 'SF Mono', Menlo, monospace; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
  .copy-btn { background: rgba(255,255,255,0.10); padding: 5px 11px; border-radius: 6px; font-size: 10px; font-weight: 700; cursor: pointer; transition: background .15s; flex-shrink: 0; }
  .copy-btn:hover { background: rgba(255,255,255,0.18); }
</style>
</head>
<body class="px-6 py-10">

<div class="max-w-5xl mx-auto">

  <!-- Stepper -->
  <div class="step-rail mb-3">
    <div id="step-pill-1" class="step-pill active">1</div>
    <div id="step-line-1" class="step-line"></div>
    <div id="step-pill-2" class="step-pill idle">2</div>
    <div id="step-line-2" class="step-line"></div>
    <div id="step-pill-3" class="step-pill idle">3</div>
  </div>
  <div class="flex items-center justify-center mb-10" style="gap: 70px;">
    <span id="step-label-1" class="step-label active">Brand</span>
    <span id="step-label-2" class="step-label">Card design</span>
    <span id="step-label-3" class="step-label">Launch</span>
  </div>

  <!-- =========================== STEP 1: BRAND =========================== -->
  <div id="panel-1" class="panel active">

    <div class="grid lg:grid-cols-2 gap-6">

      <!-- Left: upload -->
      <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-7">
        <h1 class="text-xl font-bold text-gray-900 mb-1">Upload your brand</h1>
        <p class="text-gray-500 text-sm mb-5">We extract your colours and a square favicon automatically. Watch the live preview update on the right.</p>

        <label for="logo-input" id="upload-zone" class="upload-zone block">
          <div id="upload-empty">
            <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
              <i class="fa-solid fa-cloud-arrow-up text-lg" style="color: var(--brand-primary);"></i>
            </div>
            <p class="text-gray-700 font-semibold text-sm">Drop your logo</p>
            <p class="text-gray-400 text-xs mt-1">or tap to browse · PNG, JPG, SVG, WebP, 5 MB max</p>
          </div>
          <div id="upload-loaded" class="hidden">
            <img id="logo-thumb" class="max-h-16 mx-auto mb-2">
            <p id="upload-status" class="text-xs"></p>
          </div>
        </label>
        <input type="file" id="logo-input" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/gif,image/webp" class="hidden">

        <div id="palette-row" class="mt-5 hidden">
          <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Palette</div>
          <div class="flex items-center gap-4">
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
            Approve and continue <i class="fa-solid fa-check text-sm"></i>
          </button>
        </div>
      </div>

      <!-- Right: live preview pane -->
      <div class="preview-stack">

        <!-- Browser tab -->
        <div>
          <div class="preview-label">Browser tab</div>
          <div class="browser">
            <div class="browser-bar">
              <div class="browser-dots"><span class="browser-dot"></span><span class="browser-dot"></span><span class="browser-dot"></span></div>
              <div class="browser-tab" id="tab">
                <img id="tab-favicon" alt="" />
                <span class="tab-title" id="tab-title"><?= htmlspecialchars(($company['name'] ?? '') ?: 'Your Company') ?> · Cardify</span>
              </div>
            </div>
            <div class="browser-bar" style="border-bottom: none; padding-bottom: 0;">
              <div class="browser-url" id="tab-url" style="margin-top: 0;"><?= htmlspecialchars($company['slug'] ? $company['slug'] : 'yourcompany') ?>.cardify.om</div>
            </div>
          </div>
        </div>

        <!-- Portal preview -->
        <div>
          <div class="preview-label">Employee portal</div>
          <div class="browser">
            <div class="portal-preview">
              <img id="portal-logo" class="portal-logo" alt="">
              <div id="portal-skel" class="skel" style="height: 28px; width: 120px;"></div>
              <div class="portal-card">
                <div class="portal-card-head">
                  <div class="ico" id="portal-ico">A</div>
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
          <div class="preview-label">Sample card</div>
          <div class="card-preview" id="card-preview" style="background: linear-gradient(135deg, var(--brand-primary) 0%, #1a0a8a 100%);">
            <div class="card-preview-head">
              <img id="card-logo" class="card-preview-logo" alt="" />
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
  </div>

  <!-- =========================== STEP 2: CARD DESIGN =========================== -->
  <div id="panel-2" class="panel">
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-8 max-w-2xl mx-auto">
      <h1 class="text-xl font-bold text-gray-900 mb-1">Upload your card design (PDF)</h1>
      <p class="text-gray-500 text-sm mb-6">Cardify reads the layout, fonts and QR area automatically. Skip if you don't have one yet, your team can use a default.</p>

      <label for="pdf-input" id="pdf-zone" class="upload-zone block mb-4">
        <div id="pdf-empty">
          <div class="w-12 h-12 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
            <i class="fa-solid fa-file-pdf text-lg" style="color: var(--brand-primary);"></i>
          </div>
          <p class="text-gray-700 font-semibold text-sm">Drop your business card PDF</p>
          <p class="text-gray-400 text-xs mt-1">2 pages preferred (front + back) · 25 MB max</p>
        </div>
        <div id="pdf-loaded" class="hidden">
          <p id="pdf-status" class="text-sm"></p>
          <div id="pdf-summary" class="text-xs text-gray-500 mt-2"></div>
        </div>
      </label>
      <input type="file" id="pdf-input" accept="application/pdf,.pdf" class="hidden">

      <div class="flex justify-between mt-6">
        <button class="btn-secondary inline-flex items-center gap-2" data-back="1">
          <i class="fa-solid fa-arrow-left text-sm"></i> Back
        </button>
        <div class="flex gap-3">
          <button id="skip-pdf" class="btn-secondary">Skip</button>
          <button id="approve-pdf" class="btn-primary inline-flex items-center gap-2" disabled>
            Approve and continue <i class="fa-solid fa-check text-sm"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- =========================== STEP 3: LAUNCH =========================== -->
  <div id="panel-3" class="panel">
    <div class="bg-white rounded-3xl border border-gray-100 shadow-sm p-8 max-w-2xl mx-auto text-center">
      <div class="w-14 h-14 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-3">
        <i class="fa-solid fa-rocket text-xl text-green-600"></i>
      </div>
      <h1 class="text-2xl font-bold text-gray-900 mb-1">You're live</h1>
      <p class="text-gray-500 text-sm mb-7">Share the link or invite your first employee.</p>

      <div class="text-left mb-4">
        <label class="text-xs font-semibold text-gray-500">Tenant URL</label>
        <div class="url-card mt-2">
          <span id="tenant-url"></span>
          <button class="copy-btn" id="copy-url">Copy</button>
        </div>
      </div>

      <div class="bg-gray-50 border border-gray-100 rounded-xl p-5 text-left">
        <h3 class="text-sm font-semibold text-gray-800 mb-1">Invite your first employee</h3>
        <p class="text-xs text-gray-500 mb-3">They get a sign-in link to fill in their card.</p>
        <div class="field">
          <input type="email" id="invite-email" placeholder="employee@yourcompany.om">
        </div>
        <button id="btn-invite" class="btn-primary mt-3 inline-flex items-center gap-2 w-full justify-center">
          Send invite <i class="fa-solid fa-paper-plane text-sm"></i>
        </button>
        <p id="invite-status" class="text-xs mt-2 hidden"></p>
      </div>

      <div class="flex justify-between gap-3 mt-7">
        <button class="btn-secondary inline-flex items-center gap-2 flex-shrink-0" data-back="2">
          <i class="fa-solid fa-arrow-left text-sm"></i> Back
        </button>
        <button id="btn-finish" class="btn-primary inline-flex items-center gap-2 flex-grow justify-center">
          Open dashboard <i class="fa-solid fa-arrow-right text-sm"></i>
        </button>
      </div>
    </div>
  </div>

</div>

<script>
const basePath = <?= json_encode(getBasePath()) ?>;
const adminBase = basePath + 'admin/';
const initialSlug = <?= json_encode(($company['slug'] ?? '') ?: '') ?>;
const initialName = <?= json_encode(($company['name'] ?? '') ?: 'Your Company') ?>;

let currentSlug = initialSlug;
let theme = null;

// ---- Step navigation ----
function goToStep(n) {
  for (let i = 1; i <= 3; i++) {
    document.getElementById('panel-' + i).classList.toggle('active', i === n);
    const pill = document.getElementById('step-pill-' + i);
    const label = document.getElementById('step-label-' + i);
    pill.classList.remove('active','done','idle');
    label.classList.remove('active','done');
    if (i < n) { pill.classList.add('done'); label.classList.add('done'); }
    else if (i === n) { pill.classList.add('active'); label.classList.add('active'); }
    else { pill.classList.add('idle'); }
  }
  for (let i = 1; i <= 2; i++) {
    document.getElementById('step-line-' + i).classList.toggle('done', i < n);
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

document.querySelectorAll('[data-back]').forEach(btn =>
  btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.back)))
);

// ---- Live update of preview surfaces from a theme object ----
function applyThemeToPreview(t) {
  document.documentElement.style.setProperty('--brand-primary', t.primary);
  document.documentElement.style.setProperty('--brand-secondary', t.secondary);
  document.documentElement.style.setProperty('--brand-accent', t.accent);

  // Palette swatches
  ['primary','secondary','accent'].forEach(k => {
    const sw = document.getElementById('sw-' + k);
    const hex = document.getElementById('hex-' + k);
    if (sw) sw.style.background = t[k];
    if (hex) hex.textContent = t[k];
  });

  // Browser tab
  if (t.favicon_url) document.getElementById('tab-favicon').src = basePath + t.favicon_url.replace(/^\//,'');
  document.getElementById('tab-title').textContent = (t.company_name || initialName) + ' · Cardify';
  if (t.tenant_url) {
    document.getElementById('tab-url').textContent = t.tenant_url.replace(/^https?:\/\//,'');
  }

  // Portal logo + first letter ico
  const portalLogo = document.getElementById('portal-logo');
  const portalSkel = document.getElementById('portal-skel');
  if (t.logo_url) {
    portalLogo.src = basePath + t.logo_url.replace(/^\//,'');
    portalLogo.style.display = 'block';
    portalSkel.style.display = 'none';
  }
  document.getElementById('portal-ico').textContent = ((t.company_name || initialName).trim()[0] || 'C').toUpperCase();

  // Card preview
  document.getElementById('card-preview').style.background = `linear-gradient(135deg, ${t.primary} 0%, color-mix(in srgb, ${t.primary} 70%, black) 100%)`;
  document.getElementById('card-pill').style.background = t.secondary;
  document.getElementById('pill-call').style.background = t.secondary;
  if (t.logo_url) document.getElementById('card-logo').src = basePath + t.logo_url.replace(/^\//,'');
}

// ---- Step 1: logo upload + theme extract ----
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

async function handleLogo(file) {
  if (file.size > 5 * 1024 * 1024) { alert('File is too large. Max 5 MB.'); return; }

  // Local preview (immediate)
  const reader = new FileReader();
  reader.onload = ev => {
    document.getElementById('logo-thumb').src = ev.target.result;
    document.getElementById('portal-logo').src = ev.target.result;
    document.getElementById('portal-logo').style.display = 'block';
    document.getElementById('portal-skel').style.display = 'none';
    document.getElementById('card-logo').src = ev.target.result;
    document.getElementById('tab-favicon').src = ev.target.result;  // temporary until server creates favicon
  };
  reader.readAsDataURL(file);

  empty.classList.add('hidden');
  loaded.classList.remove('hidden');
  status.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Reading colours and building favicon...</span>';

  // Server extraction
  const fd = new FormData(); fd.append('logo', file);
  try {
    const res = await fetch(adminBase + 'apply_theme.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (!res.ok || data.error) {
      status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + (data.error || ('HTTP ' + res.status)) + '</span>';
      return;
    }
    theme = data;
    document.getElementById('palette-row').classList.remove('hidden');
    applyThemeToPreview(data);
    status.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Saved. Brand applied to portal, browser tab, and sample card.</span>';
    document.getElementById('approve-brand').disabled = false;
  } catch (err) {
    status.innerHTML = '<span class="text-red-600">' + err.message + '</span>';
  }
}

document.getElementById('approve-brand').addEventListener('click', () => goToStep(2));

// ---- Step 2: PDF upload ----
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

async function handlePdf(file) {
  if (file.size > 25 * 1024 * 1024) { alert('PDF is too large. Max 25 MB.'); return; }
  pdfEmpty.classList.add('hidden');
  pdfLoaded.classList.remove('hidden');
  pdfStatus.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Analysing card design...</span>';
  document.getElementById('pdf-summary').textContent = '';

  const fd = new FormData(); fd.append('pdf', file);
  try {
    const res = await fetch(basePath + 'printshop/import_pdf.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (!res.ok || data.error) {
      pdfStatus.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + (data.error || ('HTTP ' + res.status)) + '</span>';
      return;
    }
    const fields = data.pages.reduce((n, p) => n + p.fields.length, 0);
    const qrCount = data.pages.filter(p => p.qr_area).length;
    pdfStatus.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Card design analysed.</span>';
    document.getElementById('pdf-summary').textContent =
      `${data.pages.length} pages · ${fields} fields detected · ${qrCount > 0 ? 'QR area found' : 'no QR placeholder'} · ${data.missing_fonts.length} missing font${data.missing_fonts.length === 1 ? '' : 's'}`;
    document.getElementById('approve-pdf').disabled = false;
  } catch (err) {
    pdfStatus.innerHTML = '<span class="text-red-600">' + err.message + '</span>';
  }
}

document.getElementById('skip-pdf').addEventListener('click', () => goToStep(3));
document.getElementById('approve-pdf').addEventListener('click', () => goToStep(3));

// ---- Step 3: tenant URL + invite ----
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
  const email = document.getElementById('invite-email').value.trim();
  const status = document.getElementById('invite-status');
  status.classList.remove('hidden','text-red-600','text-green-600','text-blue-600');
  if (!email.includes('@')) { status.classList.add('text-red-600'); status.textContent = 'Please enter a valid email.'; return; }
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
      status.textContent = data.error || 'Could not send invite';
    }
  } catch (err) {
    status.classList.add('text-red-600'); status.textContent = err.message;
  }
});

document.getElementById('btn-finish').addEventListener('click', () => {
  window.location.href = adminBase;
});

// On reaching step 3, populate tenant URL
const observer = new MutationObserver(() => {
  if (document.getElementById('panel-3').classList.contains('active')) fillTenantUrl();
});
observer.observe(document.getElementById('panel-3'), { attributes: true, attributeFilter: ['class'] });
</script>
</body>
</html>
