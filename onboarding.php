<?php
/**
 * Cardify Onboarding (simplified, single step).
 *
 * Drop your logo, the system auto-extracts a palette and applies it as
 * your tenant theme. No company info form, no template picker.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Auth.php';

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

$pageTitle = t('onboarding_home.page_title_general');
$showNavigation = false;
?>
<!DOCTYPE html>
<html lang="en" dir="<?= htmlspecialchars(currentDirection() ?? 'ltr') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Helvetica Neue', sans-serif; background: #f3f4f6; }
  .upload-zone { border: 2px dashed #cbd5e1; border-radius: 18px; transition: all 0.2s ease; }
  .upload-zone:hover, .upload-zone.drag-over { border-color: #2d13ea; background: #f5f3ff; }
  .swatch { width: 36px; height: 36px; border-radius: 10px; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.06), 0 4px 10px rgba(0,0,0,0.08); }
  .palette-row { transition: opacity 0.3s ease; }
  .palette-row.idle { opacity: 0; }
  .palette-row.shown { opacity: 1; }
  .preview-card { border-radius: 14px; overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,0.10); }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-6">

<div class="w-full max-w-3xl">

  <div class="text-center mb-8">
    <h1 class="text-3xl font-bold text-gray-900 mb-2">Set up your brand</h1>
    <p class="text-gray-500">Drop your logo. Cardify reads the colours and builds your theme automatically.</p>
  </div>

  <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-8 md:p-10">

    <!-- Upload -->
    <label for="logo-input"
      id="upload-zone"
      class="upload-zone block cursor-pointer text-center px-8 py-12 mb-6">

      <div id="upload-empty">
        <div class="w-16 h-16 rounded-full bg-blue-50 flex items-center justify-center mx-auto mb-4">
          <i class="fa-solid fa-cloud-arrow-up text-2xl" style="color: #2d13ea;"></i>
        </div>
        <p class="text-gray-700 font-semibold">Drop your logo here</p>
        <p class="text-gray-400 text-sm mt-1">or tap to browse</p>
        <p class="text-gray-300 text-xs mt-3">PNG, JPG, SVG, WebP, up to 5 MB</p>
      </div>

      <div id="upload-preview" class="hidden">
        <img id="logo-preview" class="max-h-32 mx-auto mb-4">
        <p class="text-sm text-gray-600" id="upload-status">
          <span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Reading colours...</span>
        </p>
      </div>
    </label>
    <input type="file" id="logo-input" accept="image/png,image/jpeg,image/jpg,image/svg+xml,image/gif,image/webp" class="hidden">

    <!-- Detected palette -->
    <div id="palette" class="palette-row idle">
      <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider mb-3">Your palette</h3>
      <div class="flex items-center gap-4 mb-6">
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-primary" style="background:#cccccc"></div>
          <div>
            <div class="text-xs text-gray-500">Primary</div>
            <div class="text-sm font-mono font-semibold" id="hex-primary">-</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-secondary" style="background:#cccccc"></div>
          <div>
            <div class="text-xs text-gray-500">Secondary</div>
            <div class="text-sm font-mono font-semibold" id="hex-secondary">-</div>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <div class="swatch" id="sw-accent" style="background:#cccccc"></div>
          <div>
            <div class="text-xs text-gray-500">Accent</div>
            <div class="text-sm font-mono font-semibold" id="hex-accent">-</div>
          </div>
        </div>
      </div>

      <!-- Preview card -->
      <div id="preview" class="preview-card mb-6" style="background: linear-gradient(135deg, var(--p) 0%, color-mix(in srgb, var(--p) 70%, black) 100%); padding: 28px;">
        <div class="flex items-center justify-between mb-8">
          <img id="preview-logo" class="h-7" style="filter: brightness(0) invert(1);">
          <div style="background: var(--s); color: #fff; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700;">Live preview</div>
        </div>
        <div style="color: #fff;">
          <div style="font-size: 22px; font-weight: 800; letter-spacing: -0.4px;">Eng. Ahmed Al Balushi</div>
          <div style="font-size: 12px; opacity: 0.85; margin-top: 2px;">Senior Cloud Solutions Architect</div>
          <div style="display: flex; gap: 8px; margin-top: 18px;">
            <div style="background: var(--s); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 12px; font-weight: 700;">Call</div>
            <div style="background: rgba(255,255,255,0.15); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 12px; font-weight: 700;">WhatsApp</div>
            <div style="background: rgba(255,255,255,0.15); color: #fff; padding: 8px 16px; border-radius: 999px; font-size: 12px; font-weight: 700;">Email</div>
          </div>
        </div>
      </div>

      <button id="apply-btn"
              class="w-full py-3.5 text-white font-semibold rounded-xl transition-all flex items-center justify-center gap-2"
              style="background: #2d13ea;">
        Apply theme
        <i class="fa-solid fa-arrow-right"></i>
      </button>
    </div>
  </div>
</div>

<script>
const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>';
const basePath = <?= json_encode(getBasePath()) ?>;
const adminBase = <?= json_encode(getBasePath() . 'admin/') ?>;

let lastFile = null;
let extracted = null;

const zone = document.getElementById('upload-zone');
const input = document.getElementById('logo-input');
const empty = document.getElementById('upload-empty');
const preview = document.getElementById('upload-preview');
const status = document.getElementById('upload-status');
const palette = document.getElementById('palette');
const previewCard = document.getElementById('preview');
const applyBtn = document.getElementById('apply-btn');

input.addEventListener('change', e => {
  const f = e.target.files[0];
  if (f) handleFile(f);
});

zone.addEventListener('dragover', e => {
  e.preventDefault(); zone.classList.add('drag-over');
});
zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
zone.addEventListener('drop', e => {
  e.preventDefault(); zone.classList.remove('drag-over');
  const f = e.dataTransfer.files[0];
  if (f) handleFile(f);
});

async function handleFile(file) {
  if (file.size > 5 * 1024 * 1024) {
    alert('File is too large. Max 5 MB.');
    return;
  }
  lastFile = file;

  // Local preview
  const reader = new FileReader();
  reader.onload = ev => {
    document.getElementById('logo-preview').src = ev.target.result;
    document.getElementById('preview-logo').src = ev.target.result;
  };
  reader.readAsDataURL(file);

  empty.classList.add('hidden');
  preview.classList.remove('hidden');
  status.innerHTML = '<span class="text-blue-600"><i class="fa-solid fa-spinner fa-spin mr-1"></i> Reading colours...</span>';

  // Server extraction + save
  const fd = new FormData();
  fd.append('logo', file);
  try {
    const res = await fetch(basePath + 'admin/apply_theme.php', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    });
    const data = await res.json();
    if (!res.ok || data.error) {
      status.innerHTML = '<span class="text-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i> ' + (data.error || ('HTTP ' + res.status)) + '</span>';
      return;
    }
    extracted = data;
    showPalette(data);
    status.innerHTML = '<span class="text-green-600"><i class="fa-solid fa-circle-check mr-1"></i> Logo saved, palette ready.</span>';
  } catch (err) {
    status.innerHTML = '<span class="text-red-600">' + err.message + '</span>';
  }
}

function showPalette(data) {
  document.getElementById('sw-primary').style.background = data.primary;
  document.getElementById('hex-primary').textContent = data.primary;
  document.getElementById('sw-secondary').style.background = data.secondary;
  document.getElementById('hex-secondary').textContent = data.secondary;
  document.getElementById('sw-accent').style.background = data.accent;
  document.getElementById('hex-accent').textContent = data.accent;
  previewCard.style.setProperty('--p', data.primary);
  previewCard.style.setProperty('--s', data.secondary);
  applyBtn.style.background = data.primary;
  palette.classList.remove('idle'); palette.classList.add('shown');
}

applyBtn.addEventListener('click', () => {
  // Theme is already saved server-side at upload time; the click just navigates.
  if (extracted && extracted.tenant_url) {
    window.location.href = adminBase;
  } else {
    window.location.href = adminBase;
  }
});
</script>
</body>
</html>
