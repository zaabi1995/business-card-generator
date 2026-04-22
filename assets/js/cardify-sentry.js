/**
 * Lightweight browser error reporter (Cat T action 456).
 *
 * No-op unless window.__SENTRY_DSN__ is present (emitted by
 * Sentry::browserBootstrap() when SENTRY_DSN_PUBLIC is defined in
 * config.php). We avoid the @sentry/browser npm package to keep the
 * page weight down — the Store endpoint is a plain POST, so we send
 * a minimal event.
 */
(function () {
    var dsn = window.__SENTRY_DSN__;
    if (!dsn) return;
    var parsed = /^(https?):\/\/([^@]+)@([^/]+)\/(\d+)$/.exec(dsn);
    if (!parsed) return;
    var url = parsed[1] + '://' + parsed[3] + '/api/' + parsed[4] + '/store/';
    var auth = 'Sentry sentry_version=7,sentry_key=' + parsed[2] + ',sentry_client=cardify-browser/1.0';
    var release = (document.querySelector('meta[name="cardify-version"]') || {}).content || 'main';

    function uuid4() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
        return ('xxxxxxxxxxxx4xxxyxxxxxxxxxxxxxxx').replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0, v = c === 'x' ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function send(event) {
        try {
            var payload = JSON.stringify(event);
            if (navigator.sendBeacon) {
                // sendBeacon ignores custom headers, so skip auth — Sentry accepts
                // the X-Sentry-Auth on the URL as a fallback.
                var beaconUrl = url + '?sentry_version=7&sentry_key=' + encodeURIComponent(parsed[2]);
                navigator.sendBeacon(beaconUrl, new Blob([payload], { type: 'application/json' }));
                return;
            }
            fetch(url, {
                method: 'POST',
                keepalive: true,
                mode: 'cors',
                headers: { 'Content-Type': 'application/json', 'X-Sentry-Auth': auth },
                body: payload,
            }).catch(function () {});
        } catch (_) {}
    }

    function baseEvent(level) {
        return {
            event_id: uuid4(),
            timestamp: new Date().toISOString(),
            platform: 'javascript',
            level: level || 'error',
            release: release,
            environment: 'production',
            request: { url: location.href, headers: { 'User-Agent': navigator.userAgent } },
        };
    }

    window.addEventListener('error', function (e) {
        var event = baseEvent('error');
        event.exception = { values: [{
            type: (e.error && e.error.name) || 'Error',
            value: (e.error && e.error.message) || e.message,
            stacktrace: { frames: parseStack((e.error && e.error.stack) || '') },
        }]};
        send(event);
    });

    window.addEventListener('unhandledrejection', function (e) {
        var reason = e.reason || {};
        var event = baseEvent('error');
        event.exception = { values: [{
            type: reason.name || 'UnhandledRejection',
            value: reason.message || String(reason),
            stacktrace: { frames: parseStack(reason.stack || '') },
        }]};
        send(event);
    });

    window.cardifyTrackError = function (message, level, extra) {
        var event = baseEvent(level || 'warning');
        event.message = { formatted: String(message) };
        if (extra) event.extra = extra;
        send(event);
    };

    function parseStack(stack) {
        if (!stack) return [];
        return stack.split('\n').slice(0, 20).map(function (line) {
            var m = /at\s+(.+?)\s+\((.+?):(\d+):(\d+)\)/.exec(line) || /at\s+(.+?):(\d+):(\d+)/.exec(line);
            if (!m) return { function: line.trim(), filename: '(anonymous)', lineno: 0 };
            return m.length === 5
                ? { function: m[1], filename: m[2], lineno: parseInt(m[3], 10) }
                : { function: '<anonymous>', filename: m[1], lineno: parseInt(m[2], 10) };
        });
    }
})();
