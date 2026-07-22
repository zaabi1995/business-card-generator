/**
 * Native Apple Pay for Cardify - NO Paymob 2.2MB SDK.
 *
 * Adapted from the proven CupsByAA implementation (same Paymob Oman account,
 * same VPS). The Apple Pay button is a pure-CSS native control (renders
 * instantly). On tap we drive ApplePaySession ourselves and call the exact
 * three Paymob endpoints the Pixel SDK uses under the hood:
 *   1. our admin/paymob-intent.php  -> publicKey + clientSecret (reuses createIntent)
 *   2. GET  {apiBase}/v1/intention/element/{pk}/{cs}/ -> payment_keys["apple-pay"]
 *   3. POST {apiBase}/api/auth/merchant/validate -> Paymob validates the Apple merchant
 *   4. POST {apiBase}/api/acceptance/payments/pay -> Paymob decrypts the token + charges
 * No PCI scope: we never decrypt; we forward Apple's encrypted paymentData to Paymob.
 *
 * NON-SAFARI (Chrome/Edge/Firefox desktop): Apple Pay works there too since
 * iOS 18. We load Apple's official JS SDK, which provides window.ApplePaySession
 * in non-Safari browsers and shows a QR-code handoff the customer scans with
 * their iPhone to authorize. Everything downstream is identical to Safari.
 *
 * Config global: window.__cardifyNativeAP (see admin/order-checkout.php,
 * admin/card-credits.php). Instance exposed as window.__cardifyAP so a page can
 * call reconfigure({ intentUrl, amount }) when the purchase amount changes
 * (card-credits bundle switch).
 */
(function () {
  'use strict';

  var APPLE_SDK_URL = 'https://applepay.cdn-apple.com/jsapi/1.latest/apple-pay-sdk.js';
  var appleSdkPromise = null;

  function loadAppleSdk(timeoutMs) {
    timeoutMs = timeoutMs || 8000;
    if (appleSdkPromise) return appleSdkPromise;
    appleSdkPromise = new Promise(function (resolve) {
      var done = false;
      var finish = function () { if (!done) { done = true; resolve(); } };
      if (window.customElements && window.customElements.get('apple-pay-button')) {
        finish();
        return;
      }
      // The page/layout preloads the SDK with a static <script async> so it
      // downloads in parallel. If that tag is present, don't inject a duplicate.
      var preloaded = document.querySelector('script[data-apple-pay-sdk], script[src^="https://applepay.cdn-apple.com/jsapi/"]');
      if (!preloaded) {
        var script = document.createElement('script');
        script.src = APPLE_SDK_URL;
        script.crossOrigin = 'anonymous';
        script.setAttribute('data-apple-pay-sdk', '');
        script.onerror = finish;
        document.head.appendChild(script);
      }
      if (window.customElements && window.customElements.whenDefined) {
        window.customElements.whenDefined('apple-pay-button').then(finish, finish);
      }
      window.setTimeout(finish, timeoutMs);
    });
    return appleSdkPromise;
  }

  function NativeApplePay(cfg) {
    this.cfg = cfg;
    this.publicKey = '';
    this.clientSecret = '';
    this.paymentToken = '';
    this.busy = false;
    this.wrapEl = document.querySelector('[data-ap="wrap"]');
    this.btnEl = document.querySelector('[data-ap="button"]');
    this.statusEl = document.querySelector('[data-ap="status"]');
    this.unavailableEl = document.querySelector('[data-ap="unavailable"]');
    this._bound = false;
  }

  NativeApplePay.prototype.init = function () {
    var self = this;
    void this.detectAndWire();
    return self;
  };

  /** Refresh the intent URL / amount (card-credits bundle switch) + re-bootstrap. */
  NativeApplePay.prototype.reconfigure = function (partial) {
    partial = partial || {};
    if (partial.intentUrl) this.cfg.intentUrl = partial.intentUrl;
    if (partial.amount) this.cfg.amount = partial.amount;
    if (partial.merchantName) this.cfg.merchantName = partial.merchantName;
    // Force a fresh intention next time.
    this.publicKey = '';
    this.clientSecret = '';
    this.paymentToken = '';
    if (this.wrapEl && !this.wrapEl.hidden) {
      void this.bootstrap().catch(function () { return undefined; });
    }
  };

  NativeApplePay.prototype.detectAndWire = function () {
    var self = this;
    return loadAppleSdk().then(function () {
      var AP = window.ApplePaySession;
      if (!AP) { self.showUnavailable(); return; }
      self.reveal();
      if (!self._bound && self.btnEl) {
        self._bound = true;
        self.btnEl.addEventListener('click', function () { void self.pay(); });
      }
      void self.bootstrap().catch(function () { return undefined; });
      void self.isCapable(AP).then(function (ok) {
        if (!ok) { self.hide(); self.showUnavailable(); }
      });
    });
  };

  NativeApplePay.prototype.hide = function () { if (this.wrapEl) this.wrapEl.hidden = true; };
  NativeApplePay.prototype.reveal = function () { if (this.wrapEl) this.wrapEl.hidden = false; };
  NativeApplePay.prototype.showUnavailable = function () { if (this.unavailableEl) this.unavailableEl.hidden = false; };

  NativeApplePay.prototype.isCapable = function (AP) {
    var self = this;
    if (typeof AP.applePayCapabilities === 'function') {
      return AP.applePayCapabilities(self.cfg.appleMerchantId).then(function (r) {
        return r.paymentCredentialStatus !== 'applePayUnsupported';
      }).catch(function () {
        try { return typeof AP.canMakePayments === 'function' && AP.canMakePayments(); }
        catch (e) { return false; }
      });
    }
    try { return Promise.resolve(typeof AP.canMakePayments === 'function' && AP.canMakePayments()); }
    catch (e) { return Promise.resolve(false); }
  };

  NativeApplePay.prototype.setStatus = function (msg, error) {
    if (!this.statusEl) return;
    this.statusEl.textContent = msg;
    this.statusEl.hidden = msg === '';
    this.statusEl.dataset.tone = error ? 'error' : 'info';
  };

  NativeApplePay.prototype.bootstrap = function () {
    var self = this;
    if (self.paymentToken) return Promise.resolve();
    return fetch(self.cfg.intentUrl, {
      method: 'POST',
      headers: { Accept: 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.success || !d.clientSecret || !d.publicKey) {
        throw new Error(d.error || 'intent-failed');
      }
      self.publicKey = d.publicKey;
      self.clientSecret = d.clientSecret;
      if (d.fallbackUrl) self.cfg.hostedFallbackUrl = d.fallbackUrl;
      return fetch(self.cfg.apiBase + '/v1/intention/element/' + self.publicKey + '/' + self.clientSecret + '/', {
        headers: { Accept: 'application/json' }
      });
    }).then(function (er) { return er.json(); }).then(function (e) {
      var token = e.payment_keys && e.payment_keys['apple-pay'];
      if (!token) {
        console.error('[cardify-apple-pay] no apple-pay payment_token in element', e);
        throw new Error('no-apple-pay-token');
      }
      self.paymentToken = token;
    });
  };

  NativeApplePay.prototype.pay = function () {
    var self = this;
    if (self.busy) return;
    self.busy = true;
    self.setStatus('');
    var AP = window.ApplePaySession;

    var proceed = function () {
      var request = {
        countryCode: self.cfg.countryCode || 'OM',
        currencyCode: self.cfg.currency || 'OMR',
        merchantCapabilities: ['supports3DS'],
        supportedNetworks: ['visa', 'masterCard', 'amex'],
        // No name/email/phone requested: the intention already carries the
        // company's billing identity, so Paymob's receipt goes there.
        total: { label: self.cfg.merchantName || 'Cardify', amount: String(self.cfg.amount) }
      };
      var session;
      try { session = new AP(3, request); }
      catch (e) { self.busy = false; window.location.href = self.cfg.hostedFallbackUrl; return; }

      session.onvalidatemerchant = function (ev) {
        fetch(self.cfg.apiBase + '/api/auth/merchant/validate', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ appleURL: ev.validationURL, integrationId: self.cfg.appleIntegrationId })
        }).then(function (r) { return r.json(); }).then(function (j) {
          session.completeMerchantValidation(j.api_response || j);
        }).catch(function () {
          session.completePayment(AP.STATUS_FAILURE);
          self.busy = false;
          self.setStatus('Could not start Apple Pay. Please use another method below.', true);
        });
      };

      session.onpaymentauthorized = function (ev) {
        fetch(self.cfg.apiBase + '/api/acceptance/payments/pay', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            payment_token: self.paymentToken,
            api_source: 'PIXEL',
            source: { identifier: JSON.stringify(ev.payment.token.paymentData), subtype: 'APPLE_PAY' }
          })
        }).then(function (r) { return r.json(); }).then(function (j) {
          var ok = String(j && j.success) === 'true';
          session.completePayment(ok ? AP.STATUS_SUCCESS : AP.STATUS_FAILURE);
          if (ok) {
            self.setStatus('Payment confirmed. Redirecting you now.');
            window.setTimeout(function () { window.location.href = self.cfg.successUrl; }, 1400);
          } else {
            self.busy = false;
            self.setStatus((j && j.data && j.data.message) || 'Payment was declined. Please try another method below.', true);
          }
        }).catch(function () {
          session.completePayment(AP.STATUS_FAILURE);
          self.busy = false;
          self.setStatus('Payment could not be completed. Please use another method below.', true);
        });
      };

      session.oncancel = function () { self.busy = false; };
      try { session.begin(); }
      catch (e) { self.busy = false; window.location.href = self.cfg.hostedFallbackUrl; }
    };

    // Safari requires the ApplePaySession to be created inside the user gesture.
    // The token is normally already fetched (eager bootstrap on reveal), so this
    // await is a no-op in the common path.
    if (self.paymentToken) {
      proceed();
    } else {
      self.bootstrap().then(proceed).catch(function () {
        self.busy = false;
        window.location.href = self.cfg.hostedFallbackUrl;
      });
    }
  };

  function boot() {
    var cfg = window.__cardifyNativeAP;
    if (cfg) {
      window.__cardifyAP = new NativeApplePay(cfg);
      window.__cardifyAP.init();
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
