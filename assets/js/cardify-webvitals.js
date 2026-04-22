/**
 * Cardify Web Vitals beacon (Category N action 370).
 *
 * Tracks Core Web Vitals + a handful of page-level metrics and
 * POSTs them to /api/webvitals.php. Uses:
 *
 *   - PerformanceObserver for LCP, CLS, FID / INP.
 *   - performance.getEntriesByType('navigation') for TTFB, DCL, load.
 *
 * Samples 10% of page loads by default so production volume stays
 * manageable. Sample rate can be overridden via
 * <meta name="cardify-webvitals-sample" content="1">.
 *
 * Uses sendBeacon when available, falls back to fetch(keepalive).
 * Runs once per page load, after window.load.
 */
(function () {
    const metaSample = document.querySelector('meta[name="cardify-webvitals-sample"]');
    const sampleRate = metaSample ? parseFloat(metaSample.content) : 0.10;
    if (Math.random() > sampleRate) return;

    const metrics = {
        url: location.pathname + location.search,
        locale: document.documentElement.lang || 'en',
        dir: document.documentElement.dir || 'ltr',
        ua: navigator.userAgent.slice(0, 140),
        connection: (navigator.connection && navigator.connection.effectiveType) || null,
        screen: `${innerWidth}x${innerHeight}`,
    };

    function send() {
        try {
            const payload = JSON.stringify(metrics);
            const blob = new Blob([payload], { type: 'application/json' });
            if (navigator.sendBeacon && navigator.sendBeacon('/api/webvitals.php', blob)) return;
            fetch('/api/webvitals.php', {
                method: 'POST',
                body: payload,
                headers: { 'Content-Type': 'application/json' },
                keepalive: true,
                credentials: 'omit',
            }).catch(() => {});
        } catch (e) { /* non-fatal */ }
    }

    // Navigation timing (TTFB, DCL, load).
    try {
        const nav = performance.getEntriesByType('navigation')[0];
        if (nav) {
            metrics.ttfb = Math.round(nav.responseStart - nav.startTime);
            metrics.dcl  = Math.round(nav.domContentLoadedEventEnd - nav.startTime);
            metrics.load = Math.round(nav.loadEventEnd - nav.startTime);
            metrics.transferSize = nav.transferSize || 0;
        }
    } catch (e) {}

    // LCP
    try {
        new PerformanceObserver((list) => {
            const entries = list.getEntries();
            const last = entries[entries.length - 1];
            if (last) metrics.lcp = Math.round(last.renderTime || last.loadTime || last.startTime);
        }).observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {}

    // CLS
    try {
        let clsValue = 0;
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                if (!entry.hadRecentInput) clsValue += entry.value;
            }
            metrics.cls = +clsValue.toFixed(3);
        }).observe({ type: 'layout-shift', buffered: true });
    } catch (e) {}

    // FID / INP
    try {
        new PerformanceObserver((list) => {
            for (const entry of list.getEntries()) {
                metrics.fid = Math.round(entry.processingStart - entry.startTime);
            }
        }).observe({ type: 'first-input', buffered: true });
    } catch (e) {}

    // Flush at pagehide so even tab-close captures.
    window.addEventListener('pagehide', send, { once: true });
    // Also flush 5s after load so SPA-ish flows don't wait for pagehide.
    window.addEventListener('load', () => setTimeout(send, 5000), { once: true });
})();
