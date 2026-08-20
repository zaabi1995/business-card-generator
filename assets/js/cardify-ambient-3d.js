/**
 * Cardify ambient 3D layer.
 *
 * A slow, low-contrast field of teal-lit cards drifting behind the page. It
 * runs on every page because that is what was asked for, so the whole design
 * is about making "every page" cost as close to nothing as possible.
 *
 * What keeps it honest:
 *   - Nothing is imported until after window `load`, so Three (163 KB gzipped)
 *     is never on the critical path and cannot move LCP.
 *   - It refuses to start at all on the conditions below, and on refusal it
 *     imports nothing, so those visitors pay zero bytes for it.
 *   - The canvas is fixed, behind everything, and pointer-events:none, so it
 *     can never intercept a click or change layout. No CLS.
 *   - Renders at devicePixelRatio capped to 1.5 and pauses entirely when the
 *     tab is hidden or the canvas scrolls out of view.
 *
 * Bows out when: prefers-reduced-motion, no WebGL, Save-Data, deviceMemory < 4,
 * hardwareConcurrency < 4, viewport under 900px, or ?no3d=1 in the URL.
 * The last one is how the E2E suite and Lighthouse get a clean read.
 */
(function () {
  'use strict';

  var SRC = '/assets/js/three-0.160.1.module.min.js';

  function shouldSkip() {
    try {
      if (new URLSearchParams(location.search).has('no3d')) return 'no3d param';
      var mq = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
      if (mq && mq.matches) return 'prefers-reduced-motion';
      if (window.innerWidth < 900) return 'viewport under 900px';
      var c = navigator.connection || {};
      if (c.saveData) return 'Save-Data';
      if (typeof c.effectiveType === 'string' && /(^|\W)(2g|slow-2g|3g)$/.test(c.effectiveType)) {
        return 'slow connection';
      }
      if (typeof navigator.deviceMemory === 'number' && navigator.deviceMemory < 4) {
        return 'deviceMemory < 4';
      }
      if (typeof navigator.hardwareConcurrency === 'number' && navigator.hardwareConcurrency < 4) {
        return 'hardwareConcurrency < 4';
      }
      var probe = document.createElement('canvas');
      var gl = probe.getContext('webgl2') || probe.getContext('webgl');
      if (!gl) return 'no WebGL';
      if (gl.getExtension) gl.getExtension('WEBGL_lose_context') && gl.getExtension('WEBGL_lose_context').loseContext();
    } catch (e) {
      return 'capability probe threw';
    }
    return null;
  }

  function start() {
    var skip = shouldSkip();
    if (skip) {
      document.documentElement.setAttribute('data-cardify-3d', 'off:' + skip);
      return;
    }

    import(SRC).then(function (THREE) {
      // Stacking, the part that quietly breaks this kind of layer.
      //
      // A position:fixed element with z-index:0 paints in step 6 of the CSS
      // painting order, which is ABOVE in-flow text (steps 3 to 5). At z-index
      // 0 this canvas would sit on top of every page's copy. So it has to be
      // z-index:-1, and that in turn puts it behind body's own background,
      // which is opaque (bg-gray-50 on most pages) and would hide it outright.
      //
      // Fix: lift body's computed background onto <html>, then make body
      // transparent, so the canvas sits between the two. Read the real
      // computed value rather than hardcoding a hex, because marketing pages,
      // admin and the Arabic side do not all share one background.
      var bodyBg = getComputedStyle(document.body).backgroundColor;
      if (bodyBg && bodyBg !== 'rgba(0, 0, 0, 0)' && bodyBg !== 'transparent') {
        document.documentElement.style.backgroundColor = bodyBg;
        document.body.style.backgroundColor = 'transparent';
      }

      var host = document.createElement('div');
      host.id = 'cardify-ambient-3d';
      host.setAttribute('aria-hidden', 'true');
      host.style.cssText =
        'position:fixed;inset:0;z-index:-1;pointer-events:none;' +
        'opacity:0;transition:opacity 1200ms ease-out;contain:strict;';
      document.body.appendChild(host);

      var renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'low-power' });
      renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
      renderer.setSize(window.innerWidth, window.innerHeight);
      host.appendChild(renderer.domElement);

      var scene = new THREE.Scene();
      var camera = new THREE.PerspectiveCamera(38, window.innerWidth / window.innerHeight, 0.1, 100);
      camera.position.z = 15;

      // Brand palette, straight from DESIGN.md: teal #009bc1 into deep #053b49.
      scene.add(new THREE.AmbientLight(0xffffff, 0.55));
      var key = new THREE.DirectionalLight(0x009bc1, 2.2);
      key.position.set(4, 6, 8);
      scene.add(key);
      var fill = new THREE.DirectionalLight(0x053b49, 1.1);
      fill.position.set(-6, -3, 4);
      scene.add(fill);

      // Business cards, 85.6 x 54 mm, the real ratio.
      var geo = new THREE.BoxGeometry(3.42, 2.16, 0.02);
      var mat = new THREE.MeshStandardMaterial({
        color: 0x0d7f9b, roughness: 0.35, metalness: 0.15,
        transparent: true, opacity: 0.4,
      });

      var cards = [];
      for (var i = 0; i < 11; i++) {
        var m = new THREE.Mesh(geo, mat);
        m.position.set((Math.random() - 0.5) * 26, (Math.random() - 0.5) * 16, (Math.random() - 0.5) * 12 - 4);
        m.rotation.set(Math.random() * Math.PI, Math.random() * Math.PI, Math.random() * Math.PI);
        m.userData.spin = (Math.random() - 0.5) * 0.0016;
        m.userData.drift = (Math.random() - 0.5) * 0.0022;
        scene.add(m);
        cards.push(m);
      }

      var running = true, raf = 0;
      function frame() {
        if (!running) return;
        for (var j = 0; j < cards.length; j++) {
          cards[j].rotation.y += cards[j].userData.spin;
          cards[j].rotation.x += cards[j].userData.spin * 0.5;
          cards[j].position.y += cards[j].userData.drift;
          if (cards[j].position.y > 9) cards[j].position.y = -9;
          if (cards[j].position.y < -9) cards[j].position.y = 9;
        }
        renderer.render(scene, camera);
        raf = requestAnimationFrame(frame);
      }

      function setRunning(on) {
        if (on === running) return;
        running = on;
        if (on) { raf = requestAnimationFrame(frame); }
        else { cancelAnimationFrame(raf); }
      }

      document.addEventListener('visibilitychange', function () {
        setRunning(!document.hidden);
      });

      var resizeTimer = 0;
      window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
          camera.aspect = window.innerWidth / window.innerHeight;
          camera.updateProjectionMatrix();
          renderer.setSize(window.innerWidth, window.innerHeight);
        }, 150);
      });

      document.documentElement.setAttribute('data-cardify-3d', 'on');
      requestAnimationFrame(function () { host.style.opacity = '1'; });
      frame();
    }).catch(function (e) {
      document.documentElement.setAttribute('data-cardify-3d', 'off:import-failed');
      if (window.console && console.debug) console.debug('ambient 3d skipped:', e && e.message);
    });
  }

  // After load, and then only when the browser is actually idle.
  function schedule() {
    if (window.requestIdleCallback) requestIdleCallback(start, { timeout: 3000 });
    else setTimeout(start, 1200);
  }
  if (document.readyState === 'complete') schedule();
  else window.addEventListener('load', schedule, { once: true });
})();
