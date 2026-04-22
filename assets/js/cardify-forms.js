/**
 * Cardify shared form helpers (Category M, actions 341-355).
 *
 * Exposes window.cardifyForms with:
 *
 *   validators: ready-to-use field validators returning
 *     { ok: bool, message: string | null }.
 *
 *   attachBlurValidation(form, rules)
 *     rules: { fieldName: validatorFn | [validatorFn, ...] }
 *     Validates each field on blur and on input after first blur.
 *     Writes error text into sibling .cardify-field__error; toggles
 *     aria-invalid on the input.
 *
 *   watchUnsavedChanges(form, opts)
 *     Tracks dirty state vs initial snapshot, shows a native
 *     beforeunload prompt when the user tries to navigate away.
 *     Call .markClean() on successful save to silence the warning.
 *
 *   confirmTyped(text, message)
 *     Opens a cardify-modal asking the user to type a literal word
 *     (usually DELETE) before the Promise resolves true.
 *     Resolves false on cancel.
 *
 *   requiredIndicator(label)
 *     Wraps a label with the standard red asterisk + sr-only text.
 *
 *   passwordStrength(value)
 *     Returns { score 0..4, label string }.
 *
 * Pairs with cardify-components.css classes: .cardify-field,
 * .cardify-field__error, .cardify-modal, .cardify-btn, etc.
 */
(function () {
    const validators = {
        required(v)   { return { ok: !!(v && String(v).trim()), message: 'Required' }; },
        minLen(n)     { return (v) => ({ ok: (v || '').length >= n, message: `At least ${n} characters` }); },
        maxLen(n)     { return (v) => ({ ok: (v || '').length <= n, message: `At most ${n} characters` }); },
        email(v) {
            if (!v) return { ok: true, message: null };
            const ok = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v).trim());
            return { ok, message: ok ? null : 'That email looks off' };
        },
        url(v) {
            if (!v) return { ok: true, message: null };
            const val = String(v).trim();
            try {
                const u = new URL(/^https?:\/\//.test(val) ? val : 'https://' + val);
                return { ok: !!u.host, message: null };
            } catch (e) {
                return { ok: false, message: 'Enter a valid URL' };
            }
        },
        phoneE164(v) {
            if (!v) return { ok: true, message: null };
            const digits = String(v).replace(/\s|-|\(|\)/g, '');
            const ok = /^\+?[0-9]{8,15}$/.test(digits);
            return { ok, message: ok ? null : 'Enter a valid phone number' };
        },
        matches(re, msg) { return (v) => ({ ok: re.test(v || ''), message: msg }); },
    };

    function fieldError(input) {
        // Find sibling .cardify-field__error within the same .cardify-field.
        const wrapper = input.closest('.cardify-field') || input.parentElement;
        if (!wrapper) return null;
        let slot = wrapper.querySelector('.cardify-field__error');
        if (!slot) {
            slot = document.createElement('p');
            slot.className = 'cardify-field__error';
            wrapper.appendChild(slot);
        }
        return slot;
    }

    function runRules(v, rules) {
        for (const rule of (Array.isArray(rules) ? rules : [rules])) {
            const r = rule(v);
            if (!r.ok) return r;
        }
        return { ok: true, message: null };
    }

    function attachBlurValidation(form, rules) {
        if (!form) return;
        const dirty = new Set();
        const validate = (name) => {
            const input = form.elements[name];
            if (!input || !rules[name]) return true;
            const { ok, message } = runRules(input.value, rules[name]);
            input.setAttribute('aria-invalid', ok ? 'false' : 'true');
            const slot = fieldError(input);
            if (slot) slot.textContent = ok ? '' : (message || '');
            return ok;
        };
        Object.keys(rules).forEach((name) => {
            const input = form.elements[name];
            if (!input) return;
            input.addEventListener('blur', () => { dirty.add(name); validate(name); });
            input.addEventListener('input', () => { if (dirty.has(name)) validate(name); });
        });
        form.addEventListener('submit', (e) => {
            let allOk = true;
            Object.keys(rules).forEach((n) => { dirty.add(n); if (!validate(n)) allOk = false; });
            if (!allOk) { e.preventDefault(); e.stopImmediatePropagation(); }
        });
    }

    function snapshot(form) {
        const data = {};
        Array.from(form.elements).forEach((el) => {
            if (!el.name) return;
            if (el.type === 'checkbox' || el.type === 'radio') data[el.name] = el.checked;
            else data[el.name] = el.value;
        });
        return JSON.stringify(data);
    }
    function watchUnsavedChanges(form, opts) {
        opts = opts || {};
        if (!form) return null;
        let initial = snapshot(form);
        let isDirty = false;
        const onInput = () => { isDirty = snapshot(form) !== initial; };
        form.addEventListener('input', onInput);
        form.addEventListener('change', onInput);
        const beforeUnload = (e) => {
            if (!isDirty) return;
            e.preventDefault();
            e.returnValue = opts.message || 'You have unsaved changes.';
            return e.returnValue;
        };
        window.addEventListener('beforeunload', beforeUnload);
        return {
            markClean() { initial = snapshot(form); isDirty = false; },
            destroy()   {
                window.removeEventListener('beforeunload', beforeUnload);
                form.removeEventListener('input', onInput);
                form.removeEventListener('change', onInput);
            },
            isDirty: () => isDirty,
        };
    }

    function confirmTyped(confirmText, opts) {
        opts = opts || {};
        return new Promise((resolve) => {
            const backdrop = document.createElement('div');
            backdrop.className = 'cardify-modal__backdrop';
            const modal = document.createElement('div');
            modal.className = 'cardify-modal';
            const dialog = document.createElement('div');
            dialog.className = 'cardify-modal__dialog';
            dialog.setAttribute('role', 'dialog');
            dialog.setAttribute('aria-modal', 'true');
            dialog.style.maxWidth = '440px';
            dialog.innerHTML = `
                <div class="cardify-modal__header">${escape(opts.title || 'Confirm')}</div>
                <div class="cardify-modal__body">
                    <p style="margin:0 0 12px;color:var(--cardify-text-muted);font-size:14px;line-height:1.55;">${escape(opts.message || ('Type ' + confirmText + ' to confirm.'))}</p>
                    <input type="text" class="cardify-input" placeholder="${escape(confirmText)}" autocomplete="off" dir="ltr">
                </div>
                <div class="cardify-modal__footer">
                    <button type="button" class="cardify-btn cardify-btn--secondary" data-action="cancel">${escape(opts.cancelLabel || 'Cancel')}</button>
                    <button type="button" class="cardify-btn cardify-btn--danger" data-action="ok" disabled>${escape(opts.okLabel || 'Delete')}</button>
                </div>
            `;
            modal.appendChild(dialog);
            document.body.appendChild(backdrop);
            document.body.appendChild(modal);
            const input = dialog.querySelector('.cardify-input');
            const ok = dialog.querySelector('[data-action="ok"]');
            const cancel = dialog.querySelector('[data-action="cancel"]');
            input.focus();
            const update = () => { ok.disabled = input.value.trim() !== confirmText; };
            input.addEventListener('input', update);
            const close = (val) => {
                backdrop.remove(); modal.remove();
                document.removeEventListener('keydown', esc);
                resolve(val);
            };
            const esc = (e) => { if (e.key === 'Escape') close(false); };
            document.addEventListener('keydown', esc);
            cancel.addEventListener('click', () => close(false));
            backdrop.addEventListener('click', () => close(false));
            ok.addEventListener('click', () => close(true));
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !ok.disabled) { e.preventDefault(); close(true); }
            });
        });
    }

    function escape(s) {
        return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function requiredIndicator(labelText) {
        return `${escape(labelText)}<span aria-hidden="true" style="color:var(--cardify-danger-500);margin-inline-start:4px;">*</span><span class="cardify-sr-only"> (required)</span>`;
    }

    function passwordStrength(v) {
        v = v || '';
        let score = 0;
        if (v.length >= 8)  score++;
        if (v.length >= 12) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v))   score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        score = Math.min(score, 4);
        const labels = ['Very weak', 'Weak', 'Fair', 'Strong', 'Excellent'];
        return { score, label: labels[score] };
    }

    window.cardifyForms = {
        validators,
        attachBlurValidation,
        watchUnsavedChanges,
        confirmTyped,
        requiredIndicator,
        passwordStrength,
    };
})();
