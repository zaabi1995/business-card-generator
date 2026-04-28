<?php
/**
 * Cardify Onboarding (3 steps).
 *
 *   1. Identify    - company name + admin email; slug auto-derived from email
 *   2. Brand       - upload logo, palette auto-extracted, live preview
 *   3. Finish      - tenant URL + invite first employee, jump to dashboard
 *
 * Each step renders inline. Navigation between steps is purely client-side
 * after the initial server-side auth check; data is committed to the DB at
 * the end of step 2 (apply_theme.php) and step 3 (invite_first.php).
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
$company = $db->fetchOne("SELECT name, slug, admin_email, email_domain FROM companies WHERE id = :id", ['id' => $companyId]);
$company = $company ?: ['name' => '', 'slug' => $companySlug ?: '', 'admin_email' => '', 'email_domain' => ''];

$theme = $db->fetchOne("SELECT primary_color, secondary_color, logo_path FROM company_themes WHERE company_id = :id", ['id' => $companyId]);
$theme = $theme ?: ['primary_color' => '#2d13ea', 'secondary_color' => '#ff7800', 'logo_path' => ''];

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
  body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif; background: linear-gradient(180deg, #f5f3ff 0%, #fff 60%); min-height: 100vh; }
  .step-pill { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; transition: all .25s ease; }
  .step-pill.active { background: #2d13ea; color: #fff; transform: scale(1.05); }
  .step-pill.done { background: #16a34a; color: #fff; }
  .step-pill.idle { background: #e5e7eb; color: #9ca3af; }
  .step-line { flex: 1; height: 2px; background: #e5e7eb; margin: 0 10px; max-width: 60px; }
  .step-line.done { background: #16a34a; }
  .step-label { font-size: 11px; font-weight: 600; color: #6b7280; }
  .step-label.active { color: #2d13ea; }
  .step-label.done { color: #16a34a; }

  .panel { display: none; }
  .panel.active { display: block; animation: fade .25s ease both; }
  @keyframes fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

  .field input, .field select { width: 100%; padding: 12px 14px; font-size: 14px; border: 1px solid #d1d5db; border-radius: 10px; background: #fff; transition: border .15s; }
  .field input:focus { outline: none; border-color: #2d13ea; box-shadow: 0 0 0 3px rgba(45,19,234,0.1); }
  .field label { display: block; font-size: 12px; font-weight: 600; color: #374151; margin-bottom: 6px; }
  .field .hint { font-size: 11px; color: #9ca3af; margin-top: 5px; }
  .pill-domain { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: #ede9fe; color: #5b21b6; border-radius: 999px; font-size: 12px; font-weight: 700; font-family: 'SF Mono', Menlo, monospace; }

  .upload-zone { border: 2px dashed #cbd5e1; border-radius: 18px; transition: all 0.2s ease; padding: 32px 24px; text-align: center; cursor: pointer; }
  .upload-zone:hover, .upload-zone.drag-over { border-color: #2d13ea; background: #f5f3ff; }

  .swatch { width: 36px; height: 36px; border-radius: 10px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 4px 10px rgba(0,0,0,0.08); }

  .preview-card { border-radius: 16px; padding: 26px; box-shadow: 0 8px 30px rgba(45,19,234,0.18); color: #fff; }
  .preview-pill-call { padding: 8px 18px; border-radius: 999px; font-size: 12px; font-weight: 700; }

  .btn-primary { background: #2d13ea; color: #fff; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; transition: filter .15s; }
  .btn-primary:hover { filter: brightness(1.06); }
  .btn-primary:disabled { background: #c7d2fe; cursor: not-allowed; }
  .btn-secondary { background: #fff; border: 1px solid #e5e7eb; color: #374151; padding: 13px 22px; border-radius: 11px; font-weight: 600; font-size: 14px; }

  .url-card { background: #0f172a; color: #fff; border-radius: 12px; padding: 14px 18px; font-family: 'SF Mono', Menlo, monospace; font-size: 14px; display: flex; align-items: center; justify-content: space-between; }
  .url-card .copy-btn { background: rgba(255,255,255,0.10); padding: 6px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background .15s; }
  .url-card .copy-btn:hover { background: rgba(255,255,255,0.20); }
</style>
</head>
<body class="flex items-center justify-center p-6 py-12">

<div class="w-full max-w-xl">

  <!-- Stepper -->
  <div class="flex items-center justify-center mb-8">
    <div id="step-pill-1" class="step-pill active">1</div>
    <div id="step-line-1" class="step-line"></div>
    <div id="step-pill-2" class="step-pill idle">2</div>
    <div id="step-line-2" class="step-line"></div>
    <div id="step-pill-3" class="step-pill idle">3</div>
  </div>

  <!-- Step labels -->
  <div class="flex items-center justify-center mb-8" style="gap: 60px;">
    <span id="step-label-1" class="step-label active">Identify</span>
    <span id="step-label-2" class="step-label">Brand</span>
    <span id="step-label-3" class="step-label">Finish</span>
  </div>

  <!-- ====================== STEP 1: IDENTIFY ====================== -->
  <div id="panel-1" class="panel active bg-white rounded-3xl border border-gray-100 shadow-sm p-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Tell us about your company</h1>
    <p class="text-gray-500 text-sm mb-7">We will use your email to derive your subdomain.</p>

    <div class="space-y-4">
      <div class="field">
        <label>Company name</label>
        <input type="text" id="f-name" value="<?= htmlspecialchars($company['name'] ?? '') ?>" placeholder="e.g. Otech, Acme Holding">
      </div>
      <div class="field">
        <label>Admin email</label>
        <input type="email" id="f-email" value="<?= htmlspecialchars($company['admin_email'] ?? '') ?>" placeholder="you@yourcompany.om">
        <div class="hint">Your subdomain is auto-derived from this. Example: admin@otech.om gives otech.cardify.om.</div>
      </div>
      <div>
        <label class="text-xs font-semibold text-gray-500">Your subdomain</label>
        <div class="mt-2"><span class="pill-domain"><i class="fa-solid fa-globe"></i><span id="slug-preview"><?= htmlspecialchars(($company['slug'] ?? '') ?: 'yourcompany') ?>.cardify.om</span></span></div>
      </div>
    </div>

    <div class="flex justify-end mt-8">
      <button id="btn-next-1" class="btn-primary inline-flex items-center gap-2">
        Continue <i class="fa-solid fa-arrow-right text-sm"></i>
      </button>
    </div>
  </div>

  <!-- ====================== STEP 2: BRAND ====================== -->
  <div id="panel-2" class="panel bg-white rounded-3xl border border-gray-100 shadow-sm p-8">
    <h1 class="text-2xl font-bold text-gray-900 mb-1">Drop your logo</h1>
    <p class="text-gray-500 text-sm mb-7">Cardify reads the colours and builds your theme automatically.</p>

    <label for="logo-input" id="upload-zone" class="upload-zone block">
      <div id="upload-empty">
        <div class="w-14 h-14 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-3">
          <i class="fa-solid fa-cloud-arrow-up text-xl" style="color: #2d13ea;"></i>
        </div>
        <p class="text-gray-700 font-semibold">Drop your logo here</p>
        <p class="text-gray-400 text-sm mt-1">or tap to browse</p>
        <p class="text-gray-300 text-xs mt-2">PNG, JPG, SVG, WebP, up to 5 MB</p>
      </div>
      <div id="upload-preview" class="hidden">
        <img id="logo-img" class="max-h-24 mx-auto mb-3">
        <p id="upload-status" class="text-sm">
          <span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Reading colours...</span>
        </p>
      </div>
    </label>
    <input type="file" id="logo-input" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/gif,image/webp" class="hidden">

    <div id="palette-row" class="mt-6 hidden">
      <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Your palette</h3>
      <div class="flex items-center gap-5 mb-5">
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-primary"></div>
          <div><div class="text-xs text-gray-500">Primary</div><div class="text-sm font-mono font-semibold" id="hex-primary"></div></div>
        </div>
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-secondary"></div>
          <div><div class="text-xs text-gray-500">Secondary</div><div class="text-sm font-mono font-semibold" id="hex-secondary"></div></div>
        </div>
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-accent"></div>
          <div><div class="text-xs text-gray-500">Accent</div><div class="text-sm font-mono font-semibold" id="hex-accent"></div></div>
        </div>
      </div>

      <div id="preview-card" class="preview-card" style="background: linear-gradient(135deg, #2d13ea 0%, #1a0a8a 100%);">
        <div class="flex items-center justify-between mb-7">
          <img id="preview-logo" class="h-7" style="filter: brightness(0) invert(1);">
          <span id="preview-pill" class="preview-pill-call" style="background: #ff7800;">Live preview</span>
        </div>
        <div style="font-size: 21px; font-weight: 800;">Eng. Ahmed Al Balushi</div>
        <div style="font-size: 12px; opacity: 0.85; margin-top: 3px;">Senior Cloud Solutions Architect</div>
        <div class="flex gap-2 mt-5">
          <span class="preview-pill-call" id="pill-call">Call</span>
          <span class="preview-pill-call" style="background: rgba(255,255,255,0.15);">WhatsApp</span>
          <span class="preview-pill-call" style="background: rgba(255,255,255,0.15);">Email</span>
        </div>
      </div>
    </div>

    <div class="flex justify-between mt-8">
      <button class="btn-secondary inline-flex items-center gap-2" data-back="1">
        <i class="fa-solid fa-arrow-left text-sm"></i> Back
      </button>
      <button id="btn-next-2" class="btn-primary inline-flex items-center gap-2" disabled>
        Continue <i class="fa-solid fa-arrow-right text-sm"></i>
      </button>
    </div>
  </div>

  <!-- ====================== STEP 3: FINISH ====================== -->
  <div id="panel-3" class="panel bg-white rounded-3xl border border-gray-100 shadow-sm p-8">
    <div class="text-center mb-7">
      <div class="w-14 h-14 rounded-full bg-green-50 flex items-center justify-center mx-auto mb-3">
        <i class="fa-solid fa-check text-xl text-green-600"></i>
      </div>
      <h1 class="text-2xl font-bold text-gray-900 mb-1">You're all set</h1>
      <p class="text-gray-500 text-sm">Your tenant is live. Share the link or invite an employee.</p>
    </div>

    <div class="space-y-4 mb-7">
      <div>
        <label class="text-xs font-semibold text-gray-500">Your tenant URL</label>
        <div class="url-card mt-2">
          <span id="tenant-url"></span>
          <button class="copy-btn" id="copy-url">Copy</button>
        </div>
      </div>

      <div class="bg-gray-50 border border-gray-100 rounded-xl p-5">
        <h3 class="text-sm font-semibold text-gray-800 mb-1">Invite your first employee</h3>
        <p class="text-xs text-gray-500 mb-3">They get a sign-in link to fill in their card details.</p>
        <div class="field">
          <input type="email" id="invite-email" placeholder="employee@yourcompany.om">
        </div>
        <button id="btn-invite" class="btn-primary mt-3 inline-flex items-center gap-2 w-full justify-center">
          Send invite <i class="fa-solid fa-paper-plane text-sm"></i>
        </button>
        <p id="invite-status" class="text-xs mt-2 hidden"></p>
      </div>
    </div>

    <div class="flex justify-between gap-3">
      <button class="btn-secondary inline-flex items-center gap-2 flex-shrink-0" data-back="2">
        <i class="fa-solid fa-arrow-left text-sm"></i> Back
      </button>
      <button id="btn-finish" class="btn-primary inline-flex items-center gap-2 flex-grow justify-center">
        Open dashboard <i class="fa-solid fa-arrow-right text-sm"></i>
      </button>
    </div>
  </div>

</div>

<script>
const basePath = <?= json_encode(getBasePath()) ?>;
const adminBase = basePath + 'admin/';
const slugFromCompany = <?= json_encode(($company['slug'] ?? '') ?: '') ?>;
let currentSlug = slugFromCompany;
let extracted = null;

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

document.querySelectorAll('[data-back]').forEach(btn => {
  btn.addEventListener('click', () => goToStep(parseInt(btn.dataset.back)));
});

// ---- Step 1: live slug preview ----
const fName = document.getElementById('f-name');
const fEmail = document.getElementById('f-email');
const slugPreview = document.getElementById('slug-preview');

function deriveSlug(email) {
  if (!email || !email.includes('@')) return '';
  let part = email.split('@')[1].toLowerCase();
  if (part.includes('.')) part = part.split('.')[0];
  return part.replace(/[^a-z0-9]/g, '');
}
function refreshSlug() {
  const slug = deriveSlug(fEmail.value) || (slugFromCompany || 'yourcompany');
  slugPreview.textContent = slug + '.cardify.om';
  currentSlug = slug;
}
fEmail.addEventListener('input', refreshSlug);

document.getElementById('btn-next-1').addEventListener('click', async () => {
  if (!fName.value.trim() || !fEmail.value.includes('@')) {
    alert('Please enter your company name and admin email.');
    return;
  }
  // Persist company info
  const fd = new FormData();
  fd.append('name', fName.value.trim());
  fd.append('admin_email', fEmail.value.trim());
  try {
    await fetch(adminBase + 'save_company.php', { method: 'POST', credentials: 'same-origin', body: fd });
  } catch(e) { /* non-blocking */ }
  goToStep(2);
});

// ---- Step 2: logo upload + palette ----
const zone = document.getElementById('upload-zone');
const input = document.getElementById('logo-input');
input.addEventListener('change', e => {
  if (e.target.files[0]) handleLogo(e.target.files[0]);
});
zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) handleLogo(e.dataTransfer.files[0]);
});

async function handleLogo(file) {
  if (file.size > 5 * 1024 * 1024) { alert('File is too large. Max 5 MB.'); return; }
  const reader = new FileReader();
  reader.onload = ev => {
    document.getElementById('logo-img').src = ev.target.result;
    document.getElementById('preview-logo').src = ev.target.result;
  };
  reader.readAsDataURL(file);
  document.getElementById('upload-empty').classList.add('hidden');
  document.getElementById('upload-preview').classList.remove('hidden');
  const status = document.getElementById('upload-status');
  status.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i>Reading colours...</span>';

  const fd = new FormData();
  fd.append('logo', file);
  try {
    const res = await fetch(adminBase + 'apply_theme.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (!res.ok || data.error) {
      status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>' + (data.error || 'HTTP ' + res.status) + '</span>';
      return;
    }
    extracted = data;
    showPalette(data);
    status.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i>Palette ready.</span>';
    document.getElementById('btn-next-2').disabled = false;
  } catch (err) {
    status.innerHTML = '<span class="text-red-600">' + err.message + '</span>';
  }
}

function showPalette(d) {
  document.getElementById('palette-row').classList.remove('hidden');
  ['primary','secondary','accent'].forEach(k => {
    document.getElementById('sw-' + k).style.background = d[k];
    document.getElementById('hex-' + k).textContent = d[k];
  });
  document.getElementById('preview-card').style.background = `linear-gradient(135deg, ${d.primary} 0%, color-mix(in srgb, ${d.primary} 70%, black) 100%)`;
  document.getElementById('preview-pill').style.background = d.secondary;
  document.getElementById('pill-call').style.background = d.secondary;
}

document.getElementById('btn-next-2').addEventListener('click', () => {
  document.getElementById('tenant-url').textContent = (extracted && extracted.tenant_url) || ('https://' + currentSlug + '.cardify.om/');
  goToStep(3);
});

// ---- Step 3: invite first employee + finish ----
document.getElementById('copy-url').addEventListener('click', () => {
  const url = document.getElementById('tenant-url').textContent;
  navigator.clipboard.writeText(url).then(() => {
    document.getElementById('copy-url').textContent = 'Copied';
    setTimeout(() => document.getElementById('copy-url').textContent = 'Copy', 1500);
  });
});

document.getElementById('btn-invite').addEventListener('click', async () => {
  const email = document.getElementById('invite-email').value.trim();
  const status = document.getElementById('invite-status');
  status.classList.remove('hidden', 'text-red-600', 'text-green-600');
  if (!email.includes('@')) {
    status.classList.add('text-red-600');
    status.textContent = 'Please enter a valid email.';
    return;
  }
  status.classList.add('text-blue-600');
  status.textContent = 'Sending...';
  const fd = new FormData();
  fd.append('email', email);
  try {
    const res = await fetch(adminBase + 'invite_first.php', { method: 'POST', credentials: 'same-origin', body: fd });
    const data = await res.json();
    if (data.ok) {
      status.classList.remove('text-blue-600','text-red-600');
      status.classList.add('text-green-600');
      status.textContent = 'Invite sent to ' + email;
    } else {
      status.classList.remove('text-blue-600','text-green-600');
      status.classList.add('text-red-600');
      status.textContent = data.error || 'Could not send invite';
    }
  } catch (err) {
    status.classList.add('text-red-600');
    status.textContent = err.message;
  }
});

document.getElementById('btn-finish').addEventListener('click', () => {
  window.location.href = adminBase;
});

// Initial state
refreshSlug();
</script>
</body>
</html>
