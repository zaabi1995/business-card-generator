/*
 * Behaviours that used to live in on* attributes.
 *
 * An inline event handler is inline script: a Content-Security-Policy that
 * drops 'unsafe-inline' kills every one of them. cardify.om carried 224 across
 * 76 files, which is the reason script-src still allowed inline script long
 * after the stored XSS on the public digital card was closed.
 *
 * This file is the replacement for the public surface. Every behaviour is
 * delegated from the document, keyed off a data attribute, so markup declares
 * WHAT it wants and never carries code. Loaded once from ui-header.php.
 *
 * Nothing here evaluates a string. Adding a behaviour means adding a named
 * entry to ACTIONS, not writing JavaScript into an attribute.
 */
(function () {
  'use strict';

  /* ---- 1. Stylesheets parked on media="print" until they finish loading ----
   * The pattern was onload="this.onload=null;this.media='all'". A link may
   * already have loaded by the time this runs, so both cases are handled:
   * a ready sheet is swapped now, one still in flight gets a listener. */
  function activateAsyncCss(link) {
    var target = link.getAttribute('data-cardify-async-css');
    if (target === 'stylesheet') {
      link.rel = 'stylesheet';
    } else {
      link.media = 'all';
    }
  }
  function scanAsyncCss() {
    var links = document.querySelectorAll('link[data-cardify-async-css]');
    for (var i = 0; i < links.length; i++) {
      (function (link) {
        if (link.dataset.cardifyAsyncCssDone) return;
        link.dataset.cardifyAsyncCssDone = '1';
        // A preload has no sheet until its rel becomes stylesheet, so it is
        // switched as soon as the browser reports it loaded.
        if (link.sheet || link.getAttribute('rel') === 'preload') {
          activateAsyncCss(link);
          return;
        }
        link.addEventListener('load', function () { activateAsyncCss(link); }, { once: true });
        link.addEventListener('error', function () { activateAsyncCss(link); }, { once: true });
      })(links[i]);
    }
  }
  scanAsyncCss();
  document.addEventListener('DOMContentLoaded', scanAsyncCss);

  /* ---- 2. Images that hide themselves when the file is missing ----
   * `error` does not bubble, so this listens in the capture phase. */
  window.addEventListener('error', function (e) {
    var el = e.target;
    if (!el || el.tagName !== 'IMG') return;
    if (el.hasAttribute('data-cardify-hide-on-error')) {
      el.style.display = 'none';
      return;
    }
    var fallback = el.getAttribute('data-cardify-fallback-src');
    if (fallback && el.getAttribute('src') !== fallback) {
      el.removeAttribute('data-cardify-fallback-src');
      el.src = fallback;
    }
  }, true);

  /* ---- 3. Named click behaviours ---- */
  var ACTIONS = {
    /* Currency picker. Was onclick="cardifySetCurrency('OMR')". */
    'set-currency': function (el) {
      if (typeof window.cardifySetCurrency === 'function') {
        window.cardifySetCurrency(el.getAttribute('data-currency'));
      }
    },
    /* Phone country picker. Was a four-argument inline call; the function
     * signature is (inputId, countryCode, phoneCode, flagClass). */
    'select-phone-country': function (el) {
      if (typeof window.selectPhoneCountry === 'function') {
        window.selectPhoneCountry(
          el.getAttribute('data-input-id'),
          el.getAttribute('data-country-code'),
          el.getAttribute('data-phone-code'),
          el.getAttribute('data-flag-class')
        );
      }
    },
    /* Go somewhere. Was onclick="window.location.href='...'" on a non-anchor. */
    'navigate': function (el) {
      var href = el.getAttribute('data-href');
      if (href) window.location.href = href;
    },
    'reload': function () { window.location.reload(); },
    'history-back': function () { window.history.back(); },
    'print': function () { window.print(); },
    /* Toggle an element's hidden state by id. */
    'toggle': function (el) {
      var target = document.getElementById(el.getAttribute('data-target'));
      if (target) target.classList.toggle(el.getAttribute('data-toggle-class') || 'hidden');
    },
    /* Copy text to the clipboard and say so on the button itself. */
    'copy': function (el) {
      var text = el.getAttribute('data-copy');
      if (!text) {
        var src = document.getElementById(el.getAttribute('data-copy-from') || '');
        text = src ? (src.value !== undefined ? src.value : src.textContent) : '';
      }
      if (!text || !navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () {
        var doneHtml = el.getAttribute('data-copied-html');
        if (doneHtml) {
          var wasHtml = el.innerHTML;
          el.innerHTML = doneHtml;
          setTimeout(function () { el.innerHTML = wasHtml; }, 2000);
          return;
        }
        var done = el.getAttribute('data-copied-label');
        if (!done) return;
        var was = el.textContent;
        el.textContent = done;
        setTimeout(function () { el.textContent = was; }, 1600);
      });
    },
    /* Submit a form by id. Was onclick="document.getElementById('f').submit()". */
    'submit-form': function (el) {
      var form = document.getElementById(el.getAttribute('data-form'));
      if (form) form.submit();
    },

    /* Call a named global with string arguments.
     *
     * The page still owns the function; this only replaces the attribute that
     * used to carry the call. data-args is JSON, parsed, never evaluated, so
     * the worst a bad value can do is fail to parse. The name is looked up on
     * window and must resolve to a function, so "constructor" or a property
     * that is not callable does nothing. */
    'call': function (el, e) {
      var names = [];
      var single = el.getAttribute('data-fn');
      if (single) names.push(single);
      var many = el.getAttribute('data-fns');
      if (many) {
        try {
          var list = JSON.parse(many);
          if (Array.isArray(list)) names = names.concat(list);
        } catch (err) { return; }
      }
      var args = argsFor(el, e);
      for (var i = 0; i < names.length; i++) callGlobal(names[i], args);
    },

    /* Was onclick="event.stopPropagation()" on a panel inside a modal. */
    'stop': function (el, e) { if (e) e.stopPropagation(); },

    /* Open this element's own image in a new tab. Was window.open(this.src). */
    'open-src': function (el) {
      var src = el.getAttribute('src') || el.getAttribute('data-href');
      if (src) window.open(src, '_blank', 'noopener');
    },

    /* Add, remove or toggle a class on an element named by id.
     * Was onclick="document.getElementById('x').classList.add('hidden')". */
    'add-class': function (el) { classOp(el, 'add'); },
    'remove-class': function (el) { classOp(el, 'remove'); },
    'toggle-class': function (el) { classOp(el, 'toggle'); },

    /* Take an element out of the page. Was .remove() in an onclick. */
    'remove-element': function (el) {
      var target = document.getElementById(el.getAttribute('data-target'));
      if (target) target.remove();
    },

    /* <dialog> open and close. */
    'open-dialog': function (el) {
      var d = document.getElementById(el.getAttribute('data-target'));
      if (d && d.showModal) d.showModal();
    },
    'close-dialog': function (el) {
      var d = el.getAttribute('data-target')
        ? document.getElementById(el.getAttribute('data-target'))
        : el.closest('dialog');
      if (d && d.close) d.close();
    },

    /* Put a value into another field. Was an inline assignment to
     * document.querySelector(...).value. */
    'set-value': function (el) {
      var target = el.getAttribute('data-target')
        ? document.getElementById(el.getAttribute('data-target'))
        : document.querySelector(el.getAttribute('data-selector') || '');
      if (target) target.value = el.getAttribute('data-value') || '';
    },

    /* Set a field in this element's form, then submit it. Was two chained
     * closest('form') calls in an onclick. */
    'set-field-and-submit': function (el) {
      var form = el.closest('form');
      if (!form) return;
      var field = form.querySelector('[name="' + (el.getAttribute('data-field') || '') + '"]');
      if (field) field.value = el.getAttribute('data-value') || '';
      form.submit();
    },

    /* Select this field's own text. Was onclick="this.select()". */
    'select-self': function (el) { if (el.select) el.select(); },

    /* Fire a CustomEvent on window. Was an inline dispatchEvent with a literal
     * detail object; the detail is JSON in a data attribute now. */
    'dispatch': function (el) {
      var name = el.getAttribute('data-event');
      if (!name) return;
      var detail = null;
      var raw = el.getAttribute('data-detail');
      if (raw) { try { detail = JSON.parse(raw); } catch (err) { return; } }
      window.dispatchEvent(new CustomEvent(name, { detail: detail }));
    },

    /* Copy, then swap the icon class on the button's own <i>. */
    'copy-icon': function (el) {
      var text = el.getAttribute('data-copy');
      if (!text || !navigator.clipboard) return;
      navigator.clipboard.writeText(text).then(function () {
        var icon = el.querySelector('i');
        var span = el.querySelector('span');
        var doneIcon = el.getAttribute('data-copied-icon');
        var doneLabel = el.getAttribute('data-copied-label');
        if (icon && doneIcon) {
          var was = icon.className;
          icon.className = doneIcon;
          setTimeout(function () { icon.className = was; }, 1600);
        }
        if (span && doneLabel) {
          var wasText = span.textContent;
          span.textContent = doneLabel;
          setTimeout(function () { span.textContent = wasText; }, 1600);
        }
      });
    },

    /* Show or hide a block and swap the button's own label.
     * Was a self-invoking function inside an onclick. */
    'toggle-block': function (el) {
      var target = document.getElementById(el.getAttribute('data-target'));
      if (!target) return;
      var open = target.style.display === 'block';
      target.style.display = open ? 'none' : 'block';
      var label = open ? el.getAttribute('data-label-open') : el.getAttribute('data-label-close');
      if (label) el.textContent = label;
    }
  };

  /* Look a function up on window by name and call it. A name is a plain
   * identifier, never an expression, so nothing here evaluates a string. */
  function callGlobal(name, args) {
    if (!name || !/^[A-Za-z_$][\w$]*(\.[A-Za-z_$][\w$]*)?$/.test(name)) return;
    var scope = window;
    var fn = window[name];
    var dot = name.indexOf('.');
    if (dot > -1) {
      scope = window[name.slice(0, dot)];
      if (!scope) return;
      fn = scope[name.slice(dot + 1)];
    }
    if (typeof fn === 'function') fn.apply(scope, args || []);
  }

  /* data-arg says what the old inline call passed: nothing, `this`, or
   * `this.value`. data-args carries literal arguments as JSON. */
  function argsFor(el, e) {
    var kind = el.getAttribute('data-arg');
    if (kind === 'element') return [el];
    if (kind === 'value') return [el.value];
    if (kind === 'event') return [e];
    var raw = el.getAttribute('data-args');
    if (!raw) return [];
    try {
      var parsed = JSON.parse(raw);
      var list = Array.isArray(parsed) ? parsed : [parsed];
      /* "__SELF__" stands where the old inline call wrote this.value, so a
       * mixed argument list still works: fn(this.value, 'other'). */
      return list.map(function (v) { return v === '__SELF__' ? el.value : v; });
    } catch (err) { return []; }
  }

  function classOp(el, op) {
    var target = document.getElementById(el.getAttribute('data-target'));
    if (!target) return;
    var names = (el.getAttribute('data-class') || 'hidden').split(/\s+/);
    for (var i = 0; i < names.length; i++) {
      if (names[i]) target.classList[op](names[i]);
    }
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    /* A confirm can sit on a plain submit button with no action of its own.
     * Was onclick="return confirm('...')". */
    var asker = e.target.closest('[data-cardify-confirm]');
    if (asker && asker.tagName !== 'FORM' && !window.confirm(asker.getAttribute('data-cardify-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    var el = e.target.closest('[data-cardify-action]');
    if (!el) return;
    var fn = ACTIONS[el.getAttribute('data-cardify-action')];
    if (!fn) return;
    if (el.tagName === 'A' || el.tagName === 'BUTTON') e.preventDefault();
    fn(el, e);
  });

  /* ---- 4. Forms that ask before they submit, and forms that never submit ----
   * Was onsubmit="return confirm('Are you sure?')" and onsubmit="return false". */
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.getAttribute) return;
    if (form.hasAttribute('data-cardify-no-submit')) {
      e.preventDefault();
      return;
    }
    var text = form.getAttribute('data-cardify-confirm');
    if (text && !window.confirm(text)) e.preventDefault();
  });

  /* ---- 4b. Fields that call a named global when they lose focus ----
   * Was onblur="checkExistingEmployee(this.value)". blur does not bubble, so
   * this listens in the capture phase like the image errors above. */
  window.addEventListener('blur', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute) return;
    var name = el.getAttribute('data-cardify-blur-fn');
    if (!name) return;
    callGlobal(name, argsFor(el, e).length ? argsFor(el, e) : [el.value]);
  }, true);

  /* ---- 4bb. Fields that call a named global on every keystroke ----
   * Was onkeyup="checkEmailDomain()". */
  document.addEventListener('keyup', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute) return;
    var name = el.getAttribute('data-cardify-keyup-fn');
    if (name) callGlobal(name, argsFor(el, e));
  });

  /* ---- 4c. A field that clears its own masked value on focus ----
   * Was onfocus="if(this.value==='***hidden***') this.value=''". */
  window.addEventListener('focus', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute) return;
    var mask = el.getAttribute('data-cardify-clear-on-focus');
    if (mask !== null && el.value === mask) el.value = '';
  }, true);

  /* ---- 4d. Images with an error treatment other than hiding ---- */
  window.addEventListener('error', function (e) {
    var el = e.target;
    if (!el || el.tagName !== 'IMG') return;
    var bg = el.getAttribute('data-cardify-error-bg');
    if (bg) el.style.background = bg;
    var html = el.getAttribute('data-cardify-error-html');
    if (html && el.parentElement) el.parentElement.innerHTML = html;
  }, true);

  /* ---- 5. Selects that navigate or submit on change ---- */
  document.addEventListener('change', function (e) {
    var el = e.target;
    if (!el || !el.getAttribute) return;
    if (el.hasAttribute('data-cardify-submit-on-change')) {
      var form = el.getAttribute('data-form')
        ? document.getElementById(el.getAttribute('data-form'))
        : el.form;
      if (form) form.submit();
      return;
    }
    var tmpl = el.getAttribute('data-cardify-navigate-on-change');
    if (tmpl) {
      window.location.href = tmpl.replace('__VALUE__', encodeURIComponent(el.value));
      return;
    }
    /* Was onchange="fn(this.value)" or "if(typeof f==='function'){ f(); }".
     * The guard is kept: the function may not be defined yet on a page that
     * loads it late. */
    var single = el.getAttribute('data-cardify-change-fn');
    var many = el.getAttribute('data-cardify-change-fns');
    if (!single && !many) return;
    var names = single ? [single] : [];
    if (many) {
      try {
        var list = JSON.parse(many);
        if (Array.isArray(list)) names = names.concat(list);
      } catch (err) { return; }
    }
    var args = argsFor(el, e);
    for (var i = 0; i < names.length; i++) callGlobal(names[i], args);
  });
})();
