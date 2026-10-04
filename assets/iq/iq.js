/*
 * Cardify IQ (cardify.om/iq). The server owns the clock, the answer key and the choice of the
 * next question; this file draws what the server says and sends choices back.
 * Pages: test (intro, practice, questions), practice (IQ Pro), account (sign in, profile), result.
 */
(function () {
  'use strict';
  var el = document.getElementById('iq-data');
  if (!el) return;
  var D = JSON.parse(el.textContent);
  var S = D.s;
  var AR = D.lang === 'ar';
  var tr = function (ar, en) { return AR ? ar : en; };
  var num = function (n) { return AR ? String(n).replace(/\d/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'[d]; }) : String(n); };
  var errText = function (code) { return S.errors[code] || S.errors.server; };

  function h(tag, props) {
    var node = document.createElement(tag);
    props = props || {};
    Object.keys(props).forEach(function (k) {
      var v = props[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'class') node.className = v;
      else if (k === 'text') node.textContent = v;
      else if (k === 'html') node.innerHTML = v; // server-built SVG only
      else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), v);
      else node.setAttribute(k, v === true ? '' : v);
    });
    for (var i = 2; i < arguments.length; i++) {
      var c = arguments[i];
      if (c === null || c === undefined || c === false) continue;
      if (Array.isArray(c)) c.forEach(function (x) { if (x) node.append(x); });
      else node.append(c);
    }
    return node;
  }

  function api(action, body, method) {
    var opt = { method: method || (body ? 'POST' : 'GET'), credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (opt.method === 'POST') {
      opt.headers['Content-Type'] = 'application/json';
      opt.headers['X-CSRF-Token'] = D.csrf;
      opt.body = JSON.stringify(body || {});
    }
    return fetch('/iq/api/' + action + (action.indexOf('?') < 0 ? '?' : '&') + 'ui=' + D.lang, opt)
      .then(function (r) {
        return r.json().catch(function () { return { error: 'server' }; }).then(function (j) {
          if (!r.ok) { var e = new Error(j.error || 'server'); e.code = j.error || 'server'; throw e; }
          return j;
        });
      });
  }

  function toast(msg, bad) {
    var t = h('div', { class: 'iqx-toast' + (bad ? ' is-bad' : ''), role: 'status', text: msg });
    document.body.append(t);
    setTimeout(function () { t.remove(); }, bad ? 5000 : 2600);
  }

  /* ---------- shared question drawing ---------- */

  function optionButton(o, i) {
    var b = h('button', { class: 'iqx-opt' + (o.svg ? ' is-fig' : ''), type: 'button', 'aria-pressed': 'false' });
    if (o.svg) b.innerHTML = o.svg;
    else b.textContent = o.text !== undefined ? o.text : (AR ? o.text_ar : o.text_en);
    b.prepend(h('span', { class: 'iqx-letter', text: AR ? ['أ', 'ب', 'ج', 'د', 'هـ', 'و'][i] : 'ABCDEF'[i] }));
    return b;
  }

  function body(q) {
    if (q.series) {
      return h('div', { class: 'iqx-series', dir: 'ltr' }, q.series.map(function (n) { return h('span', { text: String(n) }); }), h('span', { class: 'is-blank', text: '?' }));
    }
    if (q.grid) {
      return h('div', { class: 'iqx-grid' }, q.grid.map(function (svg) { return h('span', { html: svg }); }), h('span', { class: 'is-blank', text: '?' }));
    }
    if (q.figure) return h('div', { class: 'iqx-figure', html: q.figure });
    return null;
  }

  function optsClass(q) {
    var fig = !!q.options[0].svg;
    return 'iqx-opts' + (fig ? ' is-fig' : '') + (fig && q.options.length === 4 ? ' is-four' : '');
  }

  /* ---------- the test ---------- */

  var app = document.getElementById('iq-app');
  var stopClock = null;
  var onLeave = null;

  function cleanup() {
    if (stopClock) stopClock();
    stopClock = null;
    if (onLeave) document.removeEventListener('visibilitychange', onLeave);
    onLeave = null;
  }

  function show(view) {
    cleanup();
    if (view.result_url) { location.href = view.result_url; return; }
    if (view.attempt && view.question) return question(view);
    intro(view);
  }

  function intro(view) {
    var next = view.next_free || D.next_free;
    if (next) {
      app.replaceChildren(h('article', { class: 'iqx-card iqx-center' }, h('p', { text: tr('اختبارك المجاني التالي في ' + next + '.', 'Your next free test is on ' + next + '.') })));
      return;
    }
    var name = h('input', { class: 'iqx-input', id: 'iq-name', maxlength: 60, autocomplete: 'name', value: D.name || '' });
    var age = h('input', { class: 'iqx-input', id: 'iq-age', type: 'number', inputmode: 'numeric', min: 14, max: 90, dir: 'ltr' });
    var country = h('select', { class: 'iqx-input', id: 'iq-country' }, h('option', { value: '', text: '' }),
      (D.countries || []).map(function (c) { return h('option', { value: c[0], text: c[1], selected: c[0] === D.country }); }));
    var go = h('button', { class: 'iqx-btn iqx-btn-lg', type: 'submit', text: tr('ابدأ', 'Begin') });
    var form = h('form', {
      class: 'iqx-card iqx-form', onsubmit: function (e) {
        e.preventDefault();
        var n = name.value.trim();
        var a = parseInt(age.value, 10);
        if (n.length < 2) { toast(errText('name'), true); name.focus(); return; }
        if (!(a >= 14 && a <= 90)) { toast(errText('age'), true); age.focus(); return; }
        go.disabled = true;
        api('practice').then(function (p) {
          practice(p.questions, 0, { name: n, age: a, country: country.value });
        }).catch(function (err) { toast(errText(err.code), true); go.disabled = false; });
      },
    },
      h('h1', { class: 'iqx-h2', text: tr('قبل أن تبدأ', 'Before you start') }),
      h('ul', { class: 'iqx-rules' }, S.rules.map(function (r) { return h('li', { text: r }); })),
      h('div', { class: 'iqx-fields' },
        h('label', { for: 'iq-name' }, tr('اسمك', 'Your name'), name),
        h('label', { for: 'iq-age' }, tr('عمرك', 'Your age'), age)),
      h('label', { for: 'iq-country' }, tr('الدولة (اختياري)', 'Country (optional)'), country),
      h('p', { class: 'iqx-muted iqx-small', text: tr('تبدأ بثلاثة أسئلة تدريبية بلا وقت ولا درجة، ثم يبدأ الاختبار ومعه ٣٣ دقيقة.', 'You start with three practice questions, untimed and unscored. Then the test and its 33 minutes begin.') }),
      go);
    app.replaceChildren(form);
  }

  var TIPS = {
    matrix: tr('في كل صف وكل عمود قاعدة: انظر كيف يتغيّر الشكل والعدد والتعبئة.', 'Each row and column follows a rule: watch how shape, count and fill change.'),
    series: tr('ابحث عن الخطوة بين كل رقمين: جمع، أو ضرب، أو خطوة تكبر.', 'Look for the step between each pair: adding, multiplying, or a step that grows.'),
    rotate: tr('دوّر الشكل في ذهنك. الشكل المقلوب كما في المرآة ليس هو نفسه.', 'Turn the shape in your head. A mirror image is not the same shape.'),
    odd: tr('انظر للشكل والعدد والتعبئة: أربعة تشترك في صفة والخامس لا.', 'Check shape, count and fill: four share a feature, one does not.'),
    logic: tr('اقرأ المعطيات وحدها، ولا تفترض شيئاً لم يُذكر.', 'Use only what is given; assume nothing that is not stated.'),
  };

  function practice(qs, i, who, endless) {
    var q = qs[i];
    var last = i + 1 >= qs.length;
    var fb = h('p', { class: 'iqx-feedback', role: 'status' });
    var goOn = h('button', {
      class: 'iqx-btn iqx-next', type: 'button', hidden: true, onclick: function () {
        if (!last) { practice(qs, i + 1, who, endless); return; }
        if (endless) { loadPractice(endless); return; }
        goOn.disabled = true;
        api('start', who).then(show).catch(function (err) { toast(errText(err.code), true); goOn.disabled = false; });
      },
    }, last && !endless ? tr('ابدأ الاختبار الآن (يبدأ الوقت)', 'Start the test now (the clock starts)') : tr('السؤال التالي', 'Next question'));
    var done = false;
    var buttons = q.options.map(function (o, k) {
      var b = optionButton(o, k);
      b.addEventListener('click', function () {
        if (done) return;
        done = true;
        buttons.forEach(function (x, j) { x.disabled = true; if (j === q.answer) x.classList.add('is-right'); else if (j === k) x.classList.add('is-wrong'); });
        fb.textContent = (k === q.answer ? tr('صحيح. ', 'Right. ') : tr('ليست هذه. الصحيحة معلّمة بالأخضر. ', 'Not this one. The right one is marked green. ')) + (TIPS[q.kind] || '');
        fb.classList.toggle('is-ok', k === q.answer);
        goOn.hidden = false;
        goOn.focus();
      });
      return b;
    });
    app.replaceChildren(
      h('div', { class: 'iqx-top' }, h('div', {},
        h('p', { class: 'iqx-count', text: endless ? tr('تدريب', 'Practice') + ' · ' + (S.level[q.level] || '') : tr('سؤال تدريبي ' + num(i + 1) + ' من ' + num(qs.length), 'Practice ' + (i + 1) + ' of ' + qs.length) }),
        h('p', { class: 'iqx-muted iqx-small', text: tr('بلا وقت ولا درجة', 'No clock, no score') }))),
      h('article', { class: 'iqx-card iqx-qcard' }, h('h2', { class: 'iqx-prompt', text: AR ? q.prompt_ar : q.prompt_en }), body(q), h('div', { class: optsClass(q) }, buttons), fb),
      goOn);
  }

  function question(view) {
    var q = view.question;
    var count = view.attempt.count;
    var chosen = null;
    var sent = false;
    var send = function (choice) {
      if (sent) return;
      sent = true;
      cleanup();
      api('answer', { index: q.index, choice: choice }).then(show).catch(function (err) {
        toast(errText(err.code), true);
        api('state').then(show);
      });
    };
    var total = q.seconds;
    var ring = h('span', { class: 'iqx-clock', role: 'timer', 'aria-live': 'off' });
    var secs = h('span', { dir: 'ltr' });
    ring.append(secs);
    var deadline = performance.now() + q.remaining * 1000;
    var raf = 0;
    var tick = function () {
      if (!ring.isConnected) { cleanup(); return; }
      var left = Math.max(0, (deadline - performance.now()) / 1000);
      var whole = Math.ceil(left);
      secs.textContent = num(Math.floor(whole / 60)) + ':' + num(String(whole % 60).padStart(2, '0'));
      ring.style.setProperty('--p', String(left / total));
      ring.classList.toggle('is-low', left <= 60);
      if (left <= 0) { send(null); return; }
      raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    stopClock = function () { cancelAnimationFrame(raf); };
    onLeave = function () {
      if (document.visibilityState === 'hidden') api('focus', {}).catch(function () {});
      else toast(tr('خروجك من الصفحة سُجّل.', 'Leaving the page was recorded.'), true);
    };
    document.addEventListener('visibilitychange', onLeave);

    var next = h('button', { class: 'iqx-btn iqx-next', type: 'button', disabled: true, onclick: function () { send(chosen); } },
      q.index + 1 < count ? tr('التالي', 'Next') : tr('إنهاء', 'Finish'));
    var wrap;
    var buttons = q.options.map(function (o, i) {
      var b = optionButton(o, i);
      b.addEventListener('click', function () {
        chosen = i;
        wrap.querySelectorAll('.iqx-opt').forEach(function (x, k) { x.setAttribute('aria-pressed', String(k === i)); });
        next.disabled = false;
      });
      return b;
    });
    wrap = h('div', { class: optsClass(q) }, buttons);
    app.replaceChildren(
      h('div', { class: 'iqx-top' },
        h('div', {}, h('p', { class: 'iqx-count', text: tr('السؤال ' + num(q.index + 1) + ' من ' + num(count), 'Question ' + (q.index + 1) + ' of ' + count) }),
          h('p', { class: 'iqx-muted iqx-small', text: S.level[q.level] || '' })),
        ring),
      h('div', { class: 'iqx-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(count), 'aria-valuenow': String(q.index) },
        h('i', { style: 'inline-size:' + (q.index / count * 100) + '%' })),
      h('article', { class: 'iqx-card iqx-qcard iqx-live', oncontextmenu: function (e) { e.preventDefault(); }, oncopy: function (e) { e.preventDefault(); } },
        h('h2', { class: 'iqx-prompt', text: AR ? q.prompt_ar : q.prompt_en }), body(q), wrap),
      next);
    app.scrollIntoView({ block: 'start' });
  }

  function loadPractice(level) {
    var pick = h('select', { class: 'iqx-input', 'aria-label': tr('المستوى', 'Level') }, [1, 2, 3, 4].map(function (l) {
      return h('option', { value: l, text: S.level[l], selected: l === level });
    }));
    api('practice?n=5&level=' + level).then(function (p) {
      practice(p.questions, 0, null, level);
      app.prepend(h('div', { class: 'iqx-filters' }, pick));
      pick.addEventListener('change', function () { loadPractice(parseInt(pick.value, 10)); });
    }).catch(function (err) { toast(errText(err.code), true); });
  }

  /* ---------- account ---------- */

  function account() {
    var otp = document.getElementById('iq-otp');
    if (otp) {
      var msg = otp.querySelector('.iqx-msg');
      otp.addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = otp.querySelector('button[type=submit]');
        btn.disabled = true;
        msg.textContent = '';
        api('otp_send', { identifier: otp.identifier.value.trim() }).then(function () {
          otp.querySelector('.iqx-code').hidden = false;
          msg.textContent = S.code_sent;
          otp.code.focus();
          setTimeout(function () { btn.disabled = false; }, 30000);
        }).catch(function (err) { msg.textContent = errText(err.code); btn.disabled = false; });
      });
      var verify = function () {
        api('otp_verify', { code: otp.code.value.trim() }).then(function () {
          var next = otp.getAttribute('data-next');
          location.href = next ? D.base + next : D.base + '/account';
        }).catch(function (err) { msg.textContent = errText(err.code); });
      };
      otp.querySelector('[data-iq-verify]').addEventListener('click', verify);
      otp.code.addEventListener('input', function () { if (/^\d{6}$/.test(otp.code.value)) verify(); });
    }
    var prof = document.getElementById('iq-profile');
    if (prof) {
      prof.addEventListener('submit', function (e) {
        e.preventDefault();
        api('profile', {
          display_name: prof.display_name.value, country: prof.country.value,
          birth_year: parseInt(prof.birth_year.value, 10) || 0, leaderboard: prof.leaderboard.checked,
        }).then(function () { prof.querySelector('.iqx-msg').textContent = S.saved; })
          .catch(function (err) { prof.querySelector('.iqx-msg').textContent = errText(err.code); });
      });
    }
    var out = document.querySelector('[data-iq-signout]');
    if (out) out.addEventListener('click', function () { api('logout', {}).then(function () { location.href = D.base; }); });
  }

  /* ---------- buying, sharing ---------- */

  document.querySelectorAll('[data-iq-buy]').forEach(function (b) {
    b.addEventListener('click', function () {
      b.disabled = true;
      api('checkout', { product: b.getAttribute('data-iq-buy'), attempt: b.getAttribute('data-attempt') || '' })
        .then(function (r) { location.href = r.url; })
        .catch(function (err) { toast(errText(err.code), true); b.disabled = false; });
    });
  });
  document.querySelectorAll('[data-iq-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var url = b.getAttribute('data-iq-copy');
      var text = b.getAttribute('data-iq-text') || '';
      if (navigator.share && /Mobi/.test(navigator.userAgent)) { navigator.share({ text: text, url: url }).catch(function () {}); return; }
      var all = text ? text + ' ' + url : url;
      (navigator.clipboard ? navigator.clipboard.writeText(all) : Promise.reject()).then(function () { toast(S.copied); }, function () { prompt('', all); });
    });
  });

  if (D.page === 'test' && app) api('state').then(show).catch(function () { intro({}); });
  if (D.page === 'practice' && app) loadPractice(1);
  if (D.page === 'account') account();
})();
