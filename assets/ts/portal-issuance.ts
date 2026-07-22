/**
 * Cardify employee portal, progressive-disclosure step machine.
 *
 * Authored in TypeScript, compiled to assets/js/portal-issuance.js
 * (esbuild, IIFE, es2019). Loaded as a classic script AFTER the inline
 * Fabric setup in portal.php, so it reads the live editor bridge from
 * window.__cardifyPortalBridge and all server data from window.CardifyPortal.
 *
 * It regroups the existing form field blocks (#issueFields) into one-question
 * panels, drives the live Fabric card preview, and plays the submit seal. No
 * field is renamed or removed, so the PHP handler contract is unchanged.
 *
 * Build: npx esbuild assets/ts/portal-issuance.ts --bundle --format=iife \
 *          --target=es2019 --minify --outfile=assets/js/portal-issuance.js
 */

interface PortalI18n {
  step_of: string;
  sec: Record<string, string>;
  title: Record<string, string>;
  help: Record<string, string>;
  sum: Record<string, string>;
  photo_added: string;
  lot_unit: string;
  lot_note: string;
  err_email: string;
  err_name: string;
  err_domain: string;
  continue: string;
}

interface PortalConfig {
  i18n: PortalI18n;
  reqDomain: string;
  defaultQty: number;
}

interface PortalBridge {
  readonly frontEditor: any;
  readonly backEditor: any;
  frontTemplate: any;
  backTemplate: any;
  renderCardWithEditor: (editor: any, template: any, data: Record<string, string>, side: string) => Promise<void>;
  scaleCanvasToFit: (id: string, intrinsic?: number) => void;
  previewGenerated: boolean;
  companyName: string;
}

declare global {
  interface Window {
    CardifyPortal: PortalConfig;
    __cardifyPortalBridge: PortalBridge;
    scheduleLivePreview?: () => void;
    confetti?: (opts: Record<string, unknown>) => void;
  }
}

interface Panel {
  key: string;
  panel: HTMLElement;
  err: HTMLElement;
}

(function () {
  const issuance = document.getElementById('issuance');
  if (!issuance) return; // only the request-form view has the flow

  const CFG = window.CardifyPortal;
  const BR = window.__cardifyPortalBridge;
  if (!CFG || !BR) return;

  const I18 = CFG.i18n;
  const REQ_DOMAIN = CFG.reqDomain || '';
  const DEFAULT_QTY = String(CFG.defaultQty || 200);
  const companyName = BR.companyName || '';

  const form = document.getElementById('cardRequestForm') as HTMLFormElement | null;
  const host = document.getElementById('issueFields');
  if (!form || !host) return;

  // Which step each field name belongs to.
  const STEP_ORDER = ['identity', 'name', 'role', 'contact', 'photo', 'confirm'];
  const STEP_NAMES: Record<string, string[]> = {
    identity: ['email'],
    name: ['name_en', 'name_ar'],
    role: ['position_en', 'position_ar', 'position_en_2', 'position_ar_2', 'department_id'],
    contact: ['phone', 'mobile', 'fax', 'website', 'phone_ar', 'mobile_ar', 'website_ar', 'fax_ar',
      'address_en', 'address_2_en', 'address_ar', 'address_2_ar', 'company_en', 'company_ar'],
    photo: ['photo'],
    confirm: [],
  };
  const CONFIRM_IDS = ['qrToggleBlock', 'requestTypeSection', 'requestNotesSection', 'quantitySection', 'submitSection'];
  const nameToStep: Record<string, string> = {};
  Object.keys(STEP_NAMES).forEach((k) => STEP_NAMES[k].forEach((n) => { nameToStep[n] = k; }));

  // Bucket the existing field blocks into steps.
  const buckets: Record<string, HTMLElement[]> = {};
  STEP_ORDER.forEach((k) => (buckets[k] = []));
  Array.from(host.children).forEach((node) => {
    const block = node as HTMLElement;
    const id = block.id || '';
    if (id === 'generatePreviewSection') { block.style.display = 'none'; return; }
    let target: string;
    if (CONFIRM_IDS.indexOf(id) !== -1) {
      target = 'confirm';
    } else {
      const ctrl = block.querySelector('[name]');
      const nm = ctrl ? (ctrl.getAttribute('name') || '') : '';
      target = nameToStep[nm] || 'contact';
    }
    buckets[target].push(block);
  });

  const escMap: Record<string, string> = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' };
  const esc = (s: unknown) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => escMap[c]);
  const stepOf = (n: number, total: number) => I18.step_of.replace(':n', String(n)).replace(':total', String(total));

  // Only steps that actually have content (confirm always shows).
  const activeKeys = STEP_ORDER.filter((k) => k === 'confirm' || buckets[k].length);
  const TOTAL = activeKeys.length;

  // Build the panels inside the form so every field still submits.
  const stepsWrap = document.createElement('div');
  stepsWrap.id = 'issueSteps';
  const panels: Panel[] = [];

  activeKeys.forEach((key, idx) => {
    const panel = document.createElement('div');
    panel.className = 'q';
    panel.setAttribute('data-key', key);

    const kicker = document.createElement('div');
    kicker.className = 'q-kicker';
    kicker.textContent = stepOf(idx + 1, TOTAL) + ' · ' + I18.sec[key];

    const title = document.createElement('div');
    title.className = 'q-title';
    title.textContent = I18.title[key];

    const help = document.createElement('div');
    help.className = 'q-help';
    help.textContent = I18.help[key];

    const fields = document.createElement('div');
    fields.className = 'q-fields';

    if (key === 'confirm') {
      const summary = document.createElement('div');
      summary.className = 'issue-summary';
      summary.id = 'issueSummary';
      const order = document.createElement('div');
      order.className = 'issue-order';
      order.innerHTML =
        '<div><b id="issueOrderQty">' + esc(DEFAULT_QTY) + '</b> <span class="u">' +
        esc(I18.lot_unit) + '</span></div><div class="lot">' + esc(I18.lot_note) + '</div>';
      buckets[key].forEach((b) => fields.appendChild(b));
      const submit = document.getElementById('submitSection');
      if (submit && submit.parentNode === fields) {
        fields.insertBefore(summary, submit);
        fields.insertBefore(order, submit);
      } else {
        fields.appendChild(summary);
        fields.appendChild(order);
      }
    } else {
      buckets[key].forEach((b) => fields.appendChild(b));
    }

    const err = document.createElement('div');
    err.className = 'q-err';
    err.style.cssText = 'color:#dc2626;font-size:12.5px;margin-top:12px;display:none';

    panel.appendChild(kicker);
    panel.appendChild(title);
    if (I18.help[key]) panel.appendChild(help);
    panel.appendChild(fields);
    panel.appendChild(err);
    stepsWrap.appendChild(panel);
    panels.push({ key, panel, err });
  });
  form.appendChild(stepsWrap);

  let step = 0;
  let reached = 0;

  // Segmented progress pips (click a completed one to jump back).
  const flow = issuance.querySelector('.issue-flow');
  const intro = issuance.querySelector('.issue-intro');
  const pipsWrap = document.createElement('div');
  pipsWrap.className = 'issue-pips';
  const pips: HTMLElement[] = [];
  for (let i = 0; i < TOTAL; i++) {
    const pip = document.createElement('button');
    pip.type = 'button';
    pip.className = 'issue-pip';
    pip.setAttribute('aria-label', stepOf(i + 1, TOTAL));
    pip.addEventListener('click', () => { if (i <= reached) show(i); });
    pips.push(pip);
    pipsWrap.appendChild(pip);
  }
  if (flow && intro) flow.insertBefore(pipsWrap, intro.nextSibling);
  else if (flow) flow.insertBefore(pipsWrap, form);

  const ribbon = document.getElementById('issueRibbon');
  const backBtn = document.getElementById('issueBack') as HTMLButtonElement | null;
  const nextBtn = document.getElementById('issueNext') as HTMLButtonElement | null;
  const enterEl = document.getElementById('issueEnter');

  function clearErr(i: number) { if (panels[i]) panels[i].err.style.display = 'none'; }
  function setErr(i: number, msg: string) {
    if (panels[i]) { panels[i].err.textContent = msg; panels[i].err.style.display = 'block'; }
  }

  function paintPips() {
    pips.forEach((p, i) => {
      p.classList.toggle('done', i < step);
      p.classList.toggle('current', i === step);
      p.classList.toggle('clickable', i <= reached);
    });
  }

  function show(i: number) {
    step = i;
    if (i > reached) reached = i;
    panels.forEach((p, idx) => p.panel.classList.toggle('active', idx === i));
    if (ribbon) ribbon.style.width = (TOTAL > 1 ? (i / (TOTAL - 1)) * 100 : 100) + '%';
    paintPips();
    const last = i === TOTAL - 1;
    if (backBtn) backBtn.hidden = i === 0;
    if (nextBtn) nextBtn.style.display = last ? 'none' : 'inline-flex';
    if (enterEl) enterEl.style.visibility = last ? 'hidden' : 'visible';
    if (last) { BR.previewGenerated = true; buildSummary(); }
    // Focus the first control of this panel (never a hidden input).
    const ctrl = panels[i].panel.querySelector('input:not([type=hidden]),select,textarea') as HTMLElement | null;
    if (ctrl) setTimeout(() => { try { ctrl.focus(); } catch (e) { /* noop */ } }, 60);
    scheduleLive();
  }

  function validate(i: number): boolean {
    const key = activeKeys[i];
    clearErr(i);
    if (key === 'identity') {
      const e = document.getElementById('email') as HTMLInputElement | null;
      const v = e ? e.value.trim() : '';
      if (!v) { setErr(i, I18.err_email); return false; }
      if (REQ_DOMAIN) {
        const at = v.lastIndexOf('@');
        const dom = at >= 0 ? v.slice(at + 1).toLowerCase() : '';
        if (dom !== REQ_DOMAIN.toLowerCase()) { setErr(i, I18.err_domain.replace(':domain', REQ_DOMAIN)); return false; }
      }
    }
    if (key === 'name') {
      const n = document.getElementById('name_en') as HTMLInputElement | null;
      if (n && !n.value.trim()) { setErr(i, I18.err_name); return false; }
    }
    return true;
  }

  function next() { if (!validate(step)) return; if (step < TOTAL - 1) show(step + 1); }
  function back() { if (step > 0) show(step - 1); }
  if (nextBtn) nextBtn.addEventListener('click', next);
  if (backBtn) backBtn.addEventListener('click', back);

  // Enter advances (never on a textarea, never an implicit submit before the end).
  form.addEventListener('keydown', function (e: KeyboardEvent) {
    if (e.key !== 'Enter') return;
    const t = e.target as HTMLElement | null;
    if (t && t.tagName === 'TEXTAREA') return;
    if (step < TOTAL - 1) { e.preventDefault(); next(); } else { e.preventDefault(); }
  });

  // ---- review-step summary ----
  const val = (id: string) => {
    const e = document.getElementById(id) as HTMLInputElement | null;
    return e ? e.value.trim() : '';
  };
  function departmentText(): string {
    const sel = document.getElementById('department_id') as HTMLSelectElement | null;
    if (sel && sel.value) return (sel.options[sel.selectedIndex] || ({} as HTMLOptionElement)).text || '';
    const hid = document.querySelector('input[type=hidden][name="department_id"]');
    if (hid && hid.parentElement) {
      const box = hid.parentElement.querySelector('.bg-gray-100');
      if (box) return (box.textContent || '').trim();
    }
    return '';
  }
  function buildSummary() {
    const rows: [string, string][] = [];
    const push = (label: string, v: string) => { if (v) rows.push([label, v]); };
    push(I18.sum.email, val('email'));
    push(I18.sum.name, [val('name_en'), val('name_ar')].filter(Boolean).join(' · '));
    push(I18.sum.title, [val('position_en'), val('position_ar')].filter(Boolean).join(' · '));
    push(I18.sum.department, departmentText());
    push(I18.sum.mobile, val('mobile') || val('phone'));
    const ph = document.getElementById('photo') as HTMLInputElement | null;
    if (ph && ph.files && ph.files.length) push(I18.sum.photo, I18.photo_added);
    const box = document.getElementById('issueSummary');
    if (box) box.innerHTML = rows.map((r) => '<div class="r"><span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b></div>').join('');
    const q = document.getElementById('quantity_requested') as HTMLSelectElement | null;
    const oq = document.getElementById('issueOrderQty');
    if (oq) oq.textContent = q ? q.value : DEFAULT_QTY;
  }

  // ---- live preview: drive the existing Fabric editors from the fields ----
  function collectFormData(): Record<string, string> {
    const d: Record<string, string> = {};
    form!.querySelectorAll('input[name], textarea[name], select[name]').forEach((node) => {
      const el = node as HTMLInputElement;
      const ty = (el.type || '').toLowerCase();
      if (ty === 'file' || ty === 'checkbox' || ty === 'radio' || ty === 'hidden') return;
      d[el.name] = el.value || '';
    });
    if (!d.company_en) d.company_en = companyName;
    return d;
  }

  let liveBusy = false;
  let liveQueued = false;
  let liveTimer: ReturnType<typeof setTimeout> | null = null;

  async function renderLive(): Promise<void> {
    const fe = BR.frontEditor;
    const be = BR.backEditor;
    if (!fe && !be) { BR.previewGenerated = true; return; }
    if (liveBusy) { liveQueued = true; return; }
    liveBusy = true;
    const data = collectFormData();
    try {
      if (fe && BR.frontTemplate) {
        await BR.renderCardWithEditor(fe, BR.frontTemplate, data, 'front');
        const fl = document.getElementById('frontLoading'); if (fl) fl.style.display = 'none';
      }
      if (be && BR.backTemplate) {
        await BR.renderCardWithEditor(be, BR.backTemplate, data, 'back');
        const bl = document.getElementById('backLoading'); if (bl) bl.style.display = 'none';
      }
      BR.previewGenerated = true;
      requestAnimationFrame(() => requestAnimationFrame(() => {
        BR.scaleCanvasToFit('previewFrontCanvas');
        BR.scaleCanvasToFit('previewBackCanvas');
      }));
    } catch (e) {
      console.warn('live preview failed:', e);
    }
    liveBusy = false;
    if (liveQueued) { liveQueued = false; renderLive(); }
  }

  function scheduleLive() { if (liveTimer) clearTimeout(liveTimer); liveTimer = setTimeout(renderLive, 380); }
  window.scheduleLivePreview = scheduleLive;

  form.addEventListener('input', scheduleLive);
  form.addEventListener('change', function (e) {
    scheduleLive();
    const t = e.target as HTMLElement | null;
    if (t && t.id === 'photo') buildSummary();
    if (t && t.id === 'quantity_requested') {
      const oq = document.getElementById('issueOrderQty');
      if (oq) oq.textContent = (t as HTMLSelectElement).value;
    }
  });

  // ---- submit: capture the design, play the cyan seal, then POST ----
  const reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  let sealed = false;
  form.addEventListener('submit', function (e) {
    if (sealed || reduceMotion) return; // let the POST proceed (PNG captured by the inline guard)
    if (!BR.previewGenerated) return;   // the inline guard will block + alert
    e.preventDefault();
    // Capture the rendered card now: the delayed form.submit() below bypasses
    // submit listeners, so the hidden inputs must be set here.
    try {
      const fe = BR.frontEditor;
      const be = BR.backEditor;
      const fi = document.getElementById('preview_front_input') as HTMLInputElement | null;
      const bi = document.getElementById('preview_back_input') as HTMLInputElement | null;
      if (fe && typeof fe.exportPNG === 'function' && fi) fi.value = fe.exportPNG(1);
      if (be && typeof be.exportPNG === 'function' && bi) bi.value = be.exportPNG(1);
    } catch (err) { /* submit without preview image */ }
    const seal = document.getElementById('issueSeal');
    if (seal) { seal.classList.add('on'); seal.setAttribute('aria-hidden', 'false'); }
    if (typeof window.confetti === 'function') {
      try {
        window.confetti({
          particleCount: 90,
          spread: 68,
          startVelocity: 38,
          origin: { y: 0.5 },
          colors: ['#009bc1', '#0086a6', '#26a6c7', '#cfeaf1'],
          disableForReducedMotion: true,
        });
      } catch (err) { /* noop */ }
    }
    setTimeout(function () { sealed = true; form!.submit(); }, 750);
  });

  // Boot: first panel, then a first live render once Fabric settles.
  show(0);
  setTimeout(renderLive, 700);
})();

export {};
