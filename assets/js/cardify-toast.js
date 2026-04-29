/**
 * Cardify toast helper. Global: window.cardifyToast.push(options).
 *
 * Options:
 *   title    string (required)
 *   body     string (optional)
 *   variant  'success'|'error'|'info'|'warn' (default 'info')
 *   duration ms, 0 for sticky (default 5000)
 *   actionLabel string
 *   onAction function  (receives the toast root element, can call remove())
 *
 * Undo helper: cardifyToast.undo('Employee deleted', onUndo).
 */
(function () {
    let host = null;
    function ensureHost() {
        if (host) return host;
        host = document.querySelector('.cardify-toast-host');
        if (!host) {
            host = document.createElement('div');
            host.className = 'cardify-toast-host';
            host.setAttribute('role', 'status');
            host.setAttribute('aria-live', 'polite');
            document.body.appendChild(host);
        }
        return host;
    }

    function icon(variant) {
        switch (variant) {
            case 'success': return 'fa-solid fa-circle-check';
            case 'error':   return 'fa-solid fa-circle-exclamation';
            case 'warn':    return 'fa-solid fa-triangle-exclamation';
            default:        return 'fa-solid fa-circle-info';
        }
    }

    function push(opts) {
        opts = Object.assign({ variant: 'info', duration: 5000 }, opts || {});
        const root = document.createElement('div');
        root.className = 'cardify-toast';
        root.dataset.variant = opts.variant;
        const i  = document.createElement('i');
        i.className = 'cardify-toast__icon ' + icon(opts.variant);
        const body = document.createElement('div');
        body.className = 'cardify-toast__body';
        const t = document.createElement('div');
        t.className = 'cardify-toast__title';
        t.textContent = opts.title || '';
        body.appendChild(t);
        if (opts.body) {
            const b = document.createElement('p');
            b.textContent = opts.body;
            body.appendChild(b);
        }
        root.appendChild(i); root.appendChild(body);

        if (opts.actionLabel && typeof opts.onAction === 'function') {
            const a = document.createElement('button');
            a.type = 'button';
            a.className = 'cardify-toast__action';
            a.textContent = opts.actionLabel;
            a.addEventListener('click', () => {
                try { opts.onAction(root); } catch (e) {}
                close(root);
            });
            root.appendChild(a);
        }
        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'cardify-toast__close';
        x.setAttribute('aria-label', 'close');
        x.innerHTML = '&times;';
        x.addEventListener('click', () => close(root));
        root.appendChild(x);

        ensureHost().appendChild(root);
        if (opts.duration > 0) setTimeout(() => close(root), opts.duration);
        return root;
    }

    function close(root) {
        if (!root || !root.parentNode) return;
        root.classList.add('leaving');
        setTimeout(() => root.remove(), 200);
    }

    function undo(message, onUndo, opts) {
        return push(Object.assign({
            title: message,
            variant: 'info',
            duration: 6000,
            actionLabel: (opts && opts.actionLabel) || 'Undo',
            onAction: () => { try { onUndo(); } catch (e) {} }
        }, opts || {}));
    }

    window.cardifyToast = { push, undo, close };
})();
