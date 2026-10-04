(function () {
    'use strict';
    var panel = document.querySelector('[data-logo-access]');
    if (!panel) return;
    var labels = window.cardifyLogoAccessLabels || {};
    var dialog = document.getElementById('cardify-logo-access');
    var endpoint = '/api/logo-access.php?lang=' + panel.dataset.lang;
    var csrf = '', state = null, pending = null, wantsUpgrade = false, busyDownload = false;
    var one = function (selector) { return panel.querySelector(selector); };
    var text = function (key, values) {
        var result = labels[key] || labels.try_again;
        Object.keys(values || {}).forEach(function (key) { result = result.replace(':' + key, String(values[key])); });
        return result;
    };
    function error(key) { var e = one('[data-access-error]'); e.textContent = text(key); e.hidden = false; }
    function clearError() { one('[data-access-error]').hidden = true; }
    async function request(action, fields) {
        var options = {credentials: 'same-origin', headers: {Accept: 'application/json'}};
        if (action) {
            options.method = 'POST'; options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(Object.assign({action: action, csrf_token: csrf}, fields || {}));
        }
        var response = await fetch(endpoint, options);
        var data = await response.json();
        if (data.csrf) csrf = data.csrf;
        if (data.state) draw(data.state);
        if (!response.ok || data.error) throw new Error(data.error || 'try_again');
        return data;
    }
    function draw(next) {
        state = next;
        var allowance = next.paid ? text('active') : text(next.daily ? 'remaining' : 'remaining_total', {count: next.remaining, limit: next.limit});
        one('[data-access-quota]').textContent = next.remaining || next.paid ? allowance
            : text(!next.registered ? 'limit_guest' : (next.daily ? 'limit_member' : 'limit_member_total'));
        document.querySelectorAll('[data-logo-allowance]').forEach(function (e) { e.textContent = allowance; });
        one('[data-access-guest]').hidden = next.registered;
        one('[data-access-member]').hidden = !next.registered;
        one('[data-access-reset]').hidden = !next.daily;
        one('[data-access-member-email]').textContent = text('signed_in', {email: next.email});
        one('[data-access-signout]').hidden = !next.canSignOut;
        one('[data-access-upgrade]').hidden = next.paid;
        one('[data-access-reward]').hidden = !next.rewardedUnit || next.remaining > 0;
        one('[data-access-continue]').hidden = !pending || (!next.paid && next.remaining <= 0);
        one('[data-access-active]').hidden = !next.paid;
        if (next.paid) one('[data-access-active]').textContent = text('active_until', {date: new Date(next.paidUntil.replace(' ', 'T') + 'Z').toLocaleDateString(panel.dataset.lang === 'ar' ? 'ar-OM' : 'en-GB')});
        if (next.name && !one('[name=first_name]').value) {
            var parts = next.name.split(' ');
            one('[name=first_name]').value = parts.shift() || '';
            one('[name=last_name]').value = parts.join(' ');
        }
        if (next.phone) one('[name=phone]').value = next.phone;
    }
    function open() {
        if (dialog && !dialog.open) dialog.showModal();
    }
    function close() { if (dialog && dialog.open) dialog.close(); }
    async function download(selection) {
        if (busyDownload) return;
        busyDownload = true; clearError(); pending = selection;
        try {
            if (!csrf) await request();
            var data = await request('download', selection);
            close();
            var a = document.createElement('a'); a.href = data.url; a.download = '';
            document.body.appendChild(a); a.click(); a.remove();
            pending = null; draw(state);
        } catch (e) {
            open();
            if (e.message !== 'quota_reached') error(e.message);
        } finally { busyDownload = false; }
    }
    function selection(url) {
        var u = new URL(url, location.origin);
        return {company: Number(u.searchParams.get('company')), format: u.searchParams.get('format') || ''};
    }
    document.querySelectorAll('a[href*="logo-download"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (a.href.includes('ticket=')) return;
            e.preventDefault(); wantsUpgrade = false;
            void download(selection(a.href));
        });
    });
    document.querySelectorAll('[data-logo-access-open]').forEach(function (button) {
        button.addEventListener('click', function (e) { e.preventDefault(); clearError(); open(); });
    });
    document.querySelectorAll('[data-copy]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var done = function () {
                el.classList.add('copied'); var badge = el.querySelector('[data-copy-state=ok]');
                if (badge) badge.classList.remove('hidden');
                setTimeout(function () { el.classList.remove('copied'); if (badge) badge.classList.add('hidden'); }, 1500);
            };
            var fallback = function () {
                var input = document.createElement('textarea'); input.value = el.dataset.copy || '';
                document.body.appendChild(input); input.select();
                try { document.execCommand('copy'); done(); } catch (_) {} input.remove();
            };
            if (navigator.clipboard) navigator.clipboard.writeText(el.dataset.copy || '').then(done, fallback);
            else fallback();
        });
    });
    if (dialog) dialog.querySelector('[data-access-close]').addEventListener('click', close);

    async function submit(form, action, fields, complete) {
        var button = form.querySelector('button[type=submit]'); var original = button.textContent;
        button.disabled = true; button.textContent = text('working'); clearError();
        try { if (!csrf) await request(); await complete(await request(action, fields)); }
        catch (e) { error(e.message); }
        finally { button.disabled = false; button.textContent = original; }
    }
    one('[data-access-email]').addEventListener('submit', function (e) {
        e.preventDefault(); var form = e.currentTarget;
        void submit(form, 'send_code', {email: form.elements.email.value}, async function (data) {
            form.hidden = true; one('[data-access-code]').hidden = false;
            one('[data-access-code-message]').textContent = text('code_sent', {email: data.email});
            one('[name=code]').focus();
        });
    });
    one('[data-access-code]').addEventListener('submit', function (e) {
        e.preventDefault(); var form = e.currentTarget;
        void submit(form, 'verify_code', {code: form.elements.code.value}, async function () {
            if (wantsUpgrade) showBilling();
            else if (pending) await download(pending);
        });
    });
    one('[name=code]').addEventListener('input', function () {
        this.value = this.value.replace(/[\u0660-\u0669\u06f0-\u06f9]/g, function (c) { return String(c.charCodeAt(0) % 16); });
        if (/^[0-9]{6}$/.test(this.value) && !one('[data-access-code] button[type=submit]').disabled) one('[data-access-code]').requestSubmit();
    });
    one('[data-access-change]').addEventListener('click', function () {
        one('[data-access-code]').hidden = true; one('[data-access-email]').hidden = false;
        one('[name=code]').value = ''; clearError(); one('[name=email]').focus();
    });
    one('[data-access-continue]').addEventListener('click', function () { if (pending) void download(pending); });
    one('[data-access-signout]').addEventListener('click', async function () {
        try { await request('sign_out'); one('[data-access-code]').hidden = true; one('[data-access-email]').hidden = false; }
        catch (e) { error(e.message); }
    });
    function showBilling() {
        one('[data-access-buy]').hidden = true; one('[data-access-billing]').hidden = false;
        one('[name=first_name]').focus();
    }
    one('[data-access-buy]').addEventListener('click', function () {
        wantsUpgrade = true; clearError();
        if (state && state.registered) showBilling();
        else one('[name=email]').focus();
    });
    one('[data-access-billing]').addEventListener('submit', function (e) {
        e.preventDefault(); var form = e.currentTarget; var fields = Object.fromEntries(new FormData(form));
        if (pending) Object.assign(fields, pending);
        void submit(form, 'checkout', fields, async function (data) {
            form.hidden = true; one('[data-access-payments]').hidden = false;
            one('[data-access-card]').href = data.fallbackUrl;
            var cfg = {
                intentUrl: endpoint, intentBody: {action: 'intent', csrf_token: csrf, order: data.order},
                apiBase: 'https://oman.paymob.com', appleMerchantId: 'merchant.om.bhd', appleIntegrationId: 48389, readyOnly: true,
                merchantName: 'Cardify', amount: data.amount, currency: 'OMR', countryCode: 'OM',
                successUrl: data.successUrl, hostedFallbackUrl: data.fallbackUrl,
                messages: {startFailed: text('ap_start_failed'), confirmed: text('ap_confirmed'), declined: text('ap_declined'), failed: text('ap_failed')}
            };
            if (window.CardifyNativeApplePay) new window.CardifyNativeApplePay(cfg).init();
            else one('[data-ap=unavailable]').hidden = false;
        });
    });

    function loadGpt() {
        if (window.googletag && window.googletag.apiReady) return Promise.resolve();
        window.googletag = window.googletag || {cmd: []};
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script'); script.async = true;
            script.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
            script.onload = resolve; script.onerror = function () { reject(new Error('ad_unavailable')); };
            document.head.appendChild(script);
            setTimeout(function () { if (!window.googletag.apiReady) reject(new Error('ad_unavailable')); }, 10000);
        });
    }
    one('[data-access-watch]').addEventListener('click', async function () {
        var button = one('[data-access-watch]'); button.disabled = true; clearError();
        try {
            var attempt = await request('reward_start'); await loadGpt();
            await new Promise(function (resolve, reject) {
                window.googletag.cmd.push(function () {
                    var gpt = window.googletag, slot = gpt.defineOutOfPageSlot(attempt.unit, gpt.enums.OutOfPageFormat.REWARDED);
                    if (!slot) { reject(new Error('ad_unavailable')); return; }
                    var granted = false, finished = false, timer, grantPromise = Promise.resolve();
                    var finish = function (err) {
                        if (finished) return; finished = true; clearTimeout(timer);
                        ['rewardedSlotReady','rewardedSlotGranted','rewardedSlotClosed','slotRenderEnded'].forEach(function (name) { gpt.pubads().removeEventListener(name, events[name]); });
                        gpt.destroySlots([slot]); err ? reject(err) : grantPromise.then(resolve, reject);
                    };
                    var events = {
                        rewardedSlotReady: function (event) { if (event.slot === slot) { clearTimeout(timer); event.makeRewardedVisible(); } },
                        rewardedSlotGranted: function (event) { if (event.slot === slot && !granted) { granted = true; grantPromise = request('reward_granted', {challenge: attempt.challenge}); grantPromise.catch(function () {}); } },
                        rewardedSlotClosed: function (event) { if (event.slot === slot) { granted ? finish() : finish(new Error('ad_unavailable')); } },
                        slotRenderEnded: function (event) { if (event.slot === slot && event.isEmpty) finish(new Error('ad_unavailable')); }
                    };
                    Object.keys(events).forEach(function (name) { gpt.pubads().addEventListener(name, events[name]); });
                    slot.addService(gpt.pubads()); gpt.enableServices(); gpt.display(slot);
                    timer = setTimeout(function () { finish(new Error('ad_unavailable')); }, 15000);
                });
            });
            await request(); if (pending) await download(pending);
        } catch (e) { error('ad_unavailable'); }
        finally { button.disabled = false; }
    });

    async function init() {
        var params = new URLSearchParams(location.search);
        if (panel.dataset.company && Number(panel.dataset.company) > 0 && panel.dataset.format) pending = {company: Number(panel.dataset.company), format: panel.dataset.format};
        if (params.get('download') === 'required' || params.get('unlock') === 'required') {
            pending = {company: Number(panel.dataset.company), format: params.get('format') || 'svg'}; open();
        }
        try {
            await request();
            if (panel.dataset.returned === '1') {
                for (var i = 0; i < 15 && !state.paid; i++) {
                    one('[data-access-quota]').textContent = text('payment_wait');
                    await new Promise(function (resolve) { setTimeout(resolve, 2000); }); await request();
                }
                if (!state.paid) error('payment_pending');
                else if (pending) await download(pending);
            }
        } catch (e) { if (panel.dataset.page === '1' || (dialog && dialog.open)) error(e.message); }
    }
    void init();
})();
