/** Tailwind config for the wc.cardify.om World Cup pages. Compiled + purged. */
module.exports = {
  content: [
    './world-cup.php',
    './predictions.php',
    './wc-leaderboard.php',
    './wc-settings.php',
    './wc-wallet.php',
  ],
  theme: {
    extend: {
      fontFamily: { sans: ['Outfit', 'IBM Plex Sans Arabic', 'sans-serif'] },
    },
  },
  // Classes only ever produced at runtime by JS (check-in, save, badge unlock,
  // pick toggle). Safelisted so the purge can never drop them.
  safelist: [
    'bg-emerald-600', 'bg-blue-600', 'text-white', 'save-ok',
    'bg-amber-400', 'wk-on', 'badge-pop',
    'bg-gradient-to-br', 'from-amber-100', 'to-amber-200', 'text-amber-500',
    'bg-slate-100', 'text-slate-300', 'text-slate-600', 'font-semibold',
    'bg-slate-50', 'text-slate-700', 'border-slate-200',
    'border-blue-600', 'text-amber-600',
  ],
  corePlugins: { preflight: true },
};
