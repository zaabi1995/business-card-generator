/**
 * Hero card flip.
 *
 * Progressive enhancement: the card is complete and readable with this file
 * absent. All this adds is the ability to turn it over.
 *
 * The button carries aria-pressed and is a real <button>, so the interaction
 * is reachable by keyboard and announced, which a drag-only 3D control would
 * not be. The card itself is role="img" with a full alt description, because
 * to a screen reader it is one picture of a business card, not a heap of
 * disconnected text fragments.
 */
(function () {
  'use strict';

  function init() {
    var card = document.getElementById('cardify-hero-card');
    var btn = document.getElementById('cardify-hero-flip');
    if (!card || !btn) return;

    function setFlipped(on) {
      card.classList.toggle('is-flipped', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      if (window.gtag) {
        try { gtag('event', 'hero_card_flip', { flipped: on ? 'back' : 'front' }); } catch (e) {}
      }
    }

    btn.addEventListener('click', function () {
      setFlipped(btn.getAttribute('aria-pressed') !== 'true');
    });

    // Clicking the card itself is a convenience, not the only route in.
    card.addEventListener('click', function () {
      setFlipped(btn.getAttribute('aria-pressed') !== 'true');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
