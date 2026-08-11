# web-react — the Beautiful UI island

React 19 + Tailwind v4 running inside the existing PHP site. Cardify is
server-rendered pages, so the island mounts DECLARATIVELY rather than owning
routes: a page drops a mount point and this bundle fills it.

```php
require_once INCLUDES_DIR . '/react_island.php';
cardify_react_island();            // emits css + js + fonts, once per page
cardify_react_mount('kit');        // <div data-cardify-react="kit">
```

Opt-in per page: the other ~180 PHP pages never load it.

```
npm install
npm run build     # tsc --noEmit + vite -> ../assets/react/
npm run dev       # same, in watch mode
```

The build output is committed, so a deploy does not need node.

## Adding a surface

1. Write the component in `src/`.
2. Register it in `SURFACES` in `src/main.tsx`.
3. Mount it from PHP with `cardify_react_mount('<name>', $props)`.

Props are JSON-encoded into `data-props` and arrive as the `props` prop.

## Why it is opt-in

The bundle is ~123 kB gzipped. Only pages that call `cardify_react_island()`
load it.

## Two layers, kept apart

- `src/beautiful-ui/` — the kit, **verbatim**. Do not edit. 16 of the 19
  components take no props and carry their own demo data, which is why
  `design-kit.php` (the gallery) shows ice-cream-shop content: verbatim and real-data
  cannot both be true of the same file.
- `src/routes/` — our screens. Where a screen needs a kit pattern with real
  data it **forks** the markup against the same tokens rather than importing
  the component. Keeping `src/beautiful-ui/` pristine is what lets us diff a
  fork against the original when upstream ships a fix.

## Two traps that are already handled, do not "fix" them back

- **Preflight is scoped.** `src/beautiful-ui/preflight-scoped.css` re-anchors
  Tailwind's reset under `[data-bui-root]`. Stock global preflight flattens
  every heading and button in the server-rendered pages sharing this document; dropping
  it entirely makes the kit's own controls fall back to UA chrome (measured:
  grey fill, 2px outset, Arial).
  Containment is verified, not assumed: with the island mounted, a legacy `h1`
  still computes to 32px and a legacy `button` keeps its UA background, while
  the kit's own controls are transparent with `0px solid` borders.

- **Cardify's own Tailwind is v3** (`tailwind.config.js`, scanning `./**/*.php`).
  The island is a separate v4 build with its own stylesheet. The two do not
  share a config and must not be merged.
