/**
 * Cardify inline checkout controller (Paymob Pixel).
 *
 * Adapted from the proven CupsByAA implementation. A boring, legible, fast card
 * form. Every failure path ends somewhere the customer can still pay (the hosted
 * gateway). Card details go straight to Paymob; Cardify never sees them.
 *
 * State machine (single source of truth, never inferred from the DOM):
 *   idle -> creating -> mounting -> ready -> paying -> complete
 *                  \-> failed (recoverable: retry or hosted fallback)
 *
 * Config global: window.__cardifyCheckout. Instance exposed as
 * window.__cardifyPixel so a page can reconfigure({ intentUrl, amountLabel })
 * when the purchase amount changes (card-credits bundle switch).
 */
(function () {
  'use strict';

  var sdkPromise = null;

  function loadPixelSdk(src, timeoutMs) {
    timeoutMs = timeoutMs || 45000;
    if (sdkPromise) return sdkPromise;
    sdkPromise = new Promise(function (resolve, reject) {
      if (window.Pixel) { resolve(window.Pixel); return; }
      var script = document.createElement('script');
      script.type = 'module';
      script.src = src;
      script.onerror = function () { reject(new Error('sdk-network')); };
      document.head.appendChild(script);
      // The module sets window.Pixel as its final statement; onload can fire
      // fractionally before that assignment is observable, so poll for it.
      var started = Date.now();
      var tick = window.setInterval(function () {
        if (window.Pixel) { window.clearInterval(tick); resolve(window.Pixel); }
        else if (Date.now() - started > timeoutMs) { window.clearInterval(tick); reject(new Error('sdk-timeout')); }
      }, 60);
    });
    return sdkPromise;
  }

  function InlineCheckout(cfg) {
    this.cfg = cfg;
    this.state = 'idle';
    this.mountEl = document.querySelector('[data-checkout="mount"]');
    this.statusEl = document.querySelector('[data-checkout="status"]');
    this.fallbackEl = document.querySelector('[data-checkout="fallback"]');
    this.skeletonEl = document.querySelector('[data-checkout="skeleton"]');
    this.retryEl = document.querySelector('[data-checkout="retry"]');
    this.triggerEl = document.querySelector('[data-checkout="trigger"]');
    this._bound = false;
  }

  InlineCheckout.prototype.init = function () {
    var self = this;
    if (!this.mountEl) return this;
    if (this.cfg.autoStart === false) {
      // The ~2.2MB SDK + card form load ONLY when the customer taps "Pay by
      // card", so Apple Pay users never pay that cost. The trigger hides itself.
      if (this.triggerEl && !this._bound) {
        this._bound = true;
        this.triggerEl.addEventListener('click', function () {
          if (self.triggerEl) self.triggerEl.hidden = true;
          void loadPixelSdk(self.cfg.sdkUrl).catch(function () { return undefined; });
          void self.start();
        });
      }
      return this;
    }
    // ?autoStart!==false: warm the SDK in parallel with the intent (not used
    // by the current pages, kept for parity with the reference implementation).
    void loadPixelSdk(this.cfg.sdkUrl).catch(function () { return undefined; });
    void this.start();
    return this;
  };

  /** Refresh the intent URL / amount label (card-credits bundle switch). */
  InlineCheckout.prototype.reconfigure = function (partial) {
    partial = partial || {};
    if (partial.intentUrl) this.cfg.intentUrl = partial.intentUrl;
    if (partial.amountLabel) this.cfg.amountLabel = partial.amountLabel;
    // Tear the mounted form down so the next tap mounts a fresh one at the new
    // amount. Pixel mounts into mountEl; clearing its innerHTML drops the form.
    if (this.mountEl) this.mountEl.innerHTML = '';
    this.setState('idle', '');
    if (this.triggerEl) this.triggerEl.hidden = false;
  };

  InlineCheckout.prototype.setState = function (next, message) {
    message = message || '';
    this.state = next;
    if (this.skeletonEl) {
      this.skeletonEl.hidden = !(next === 'idle' || next === 'creating' || next === 'mounting');
      // Never show the skeleton before the trigger is tapped.
      if (this.cfg.autoStart === false && next === 'idle') this.skeletonEl.hidden = true;
    }
    if (this.mountEl) {
      this.mountEl.hidden = !(next === 'ready' || next === 'paying' || next === 'complete');
    }
    if (this.retryEl) { this.retryEl.hidden = next !== 'failed'; }
    if (this.fallbackEl) { this.fallbackEl.hidden = !(next === 'ready' || next === 'paying'); }
    if (this.statusEl) {
      this.statusEl.textContent = message;
      this.statusEl.hidden = message === '';
      this.statusEl.dataset.tone = next === 'failed' ? 'error' : 'info';
    }
  };

  InlineCheckout.prototype.start = function () {
    var self = this;
    if (this.state !== 'idle' && this.state !== 'failed') return; // guard double-tap
    this.setState('creating', 'Preparing your secure payment form');

    fetch(this.cfg.intentUrl, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); }).then(function (data) {
      if (!data.success) {
        if (data.paid) { self.setState('complete', 'This order is already paid. Thank you.'); return; }
        self.fail(data.error || 'We could not start the payment.');
        return;
      }
      if (data.fallbackUrl) self.cfg.hostedFallbackUrl = data.fallbackUrl;
      self.setState('mounting', 'Loading secure card form');
      loadPixelSdk(self.cfg.sdkUrl).then(function (PixelCtor) {
        self.mount(PixelCtor, data);
      }).catch(function () {
        // Never strand the customer: the hosted page always works.
        window.location.href = data.fallbackUrl || self.cfg.hostedFallbackUrl;
      });
    }).catch(function () {
      self.fail('We could not reach the payment service. Please use the secure gateway link below.');
    });
  };

  InlineCheckout.prototype.mount = function (PixelCtor, data) {
    var self = this;
    // On browsers WITHOUT ApplePaySession (Chrome/Edge desktop), the native
    // button can't render, so let the Pixel form offer Apple Pay via the W3C
    // Payment Request API there. Safari keeps the native button above.
    var methods = (this.cfg.paymentMethods || ['card']).slice();
    var hasApplePaySession = typeof window.ApplePaySession !== 'undefined';
    if (!hasApplePaySession && methods.indexOf('apple-pay') === -1) {
      methods.push('apple-pay');
    }
    try {
      new PixelCtor({
        publicKey: data.publicKey,
        clientSecret: data.clientSecret,
        paymentMethods: methods,
        elementId: self.mountEl.id || 'paymob-elements',
        showSaveCard: true,
        cardValidationChanged: function () {
          if (self.state === 'mounting') self.setState('ready', '');
        },
        beforePaymentComplete: function () {
          self.setState('paying', 'Confirming your payment, do not close this page');
          return Promise.resolve(true);
        },
        afterPaymentComplete: function () {
          // The embedded flow does NOT navigate the parent page, so redirect
          // ourselves. Give the webhook a moment to land first.
          self.setState('complete', 'Payment confirmed. Redirecting you now.');
          window.setTimeout(function () {
            window.location.href = self.cfg.successUrl || window.location.href;
          }, 1800);
        },
        onPaymentCancel: function () {
          self.setState('ready', 'Payment cancelled. You can try again.');
        },
        customStyle: {
          Color_Primary: '#009bc1',
          Radius_Border: '14',
          Width_of_Container: '100%',
          Font_Size_Label: '14',
          Font_Size_Input_Fields: '16', // >=16px stops iOS zooming on focus
          Container_Padding: '0',
          Color_Border_Input_Fields: '#E5E7EB',
          Text_Color_For_Label: '#374151',
          Color_Error: '#DC2626'
        }
      });
      // If the SDK never calls back, still reveal the form.
      window.setTimeout(function () {
        if (self.state === 'mounting') self.setState('ready', '');
      }, 2500);
    } catch (e) {
      self.fail('The secure card form could not load. Please use the gateway link below.');
    }
  };

  InlineCheckout.prototype.fail = function (message) { this.setState('failed', message); };

  function boot() {
    var cfg = window.__cardifyCheckout;
    if (cfg) {
      window.__cardifyPixel = new InlineCheckout(cfg);
      window.__cardifyPixel.init();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
