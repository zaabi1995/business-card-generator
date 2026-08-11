/* Island entry for Cardify.
 *
 * Cardify is server-rendered PHP pages, not a hash SPA, so there is no router
 * to hand off to. The island mounts DECLARATIVELY instead: any page that wants
 * a React surface drops a mount point and this bundle fills it.
 *
 *   <div data-cardify-react="kit"></div>
 *   <div data-cardify-react="records" data-props='{"companyId":42}'></div>
 *
 * A page with no mount point never loads this file at all (see the loader in
 * includes/react_island.php), so the other ~180 PHP pages pay nothing.
 *
 * Same two-layer split as Splitty: src/beautiful-ui/ is the kit verbatim and is
 * never edited; anything needing real Cardify data forks the markup against the
 * same tokens.
 */

import { createRoot, type Root } from "react-dom/client";
import { StrictMode, type ComponentType } from "react";
import Kit from "./Kit";
import CompaniesGrid from "./surfaces/CompaniesGrid";
import "./styles.css";

export type SurfaceProps = { props: Record<string, unknown> };

const SURFACES: Record<string, ComponentType<SurfaceProps>> = {
  kit: Kit,
  companies: CompaniesGrid,
};

const roots = new WeakMap<Element, Root>();

/** Reads data-props, tolerating a malformed value rather than killing the page. */
function readProps(el: Element): Record<string, unknown> {
  const raw = el.getAttribute("data-props");
  if (!raw) return {};
  try {
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === "object" ? (parsed as Record<string, unknown>) : {};
  } catch {
    console.error("[cardify-react] data-props is not valid JSON on", el);
    return {};
  }
}

function mountAll(scope: ParentNode = document): number {
  let n = 0;
  for (const el of scope.querySelectorAll("[data-cardify-react]")) {
    const name = el.getAttribute("data-cardify-react") || "";
    const Surface = SURFACES[name];
    if (!Surface) {
      console.error("[cardify-react] no surface registered for", name);
      continue;
    }
    if (roots.has(el)) continue; // already owned

    el.replaceChildren();
    /* The kit expects Tailwind's reset, which preflight-scoped.css anchors to
     * [data-bui-root]. Set it here so a page author only has to remember the
     * one data-cardify-react attribute. */
    el.setAttribute("data-bui-root", "");

    const root = createRoot(el);
    roots.set(el, root);
    root.render(
      <StrictMode>
        <Surface props={readProps(el)} />
      </StrictMode>,
    );
    n += 1;
  }
  return n;
}

const CardifyReact = { surfaces: Object.keys(SURFACES), mountAll };
(window as unknown as { CardifyReact: typeof CardifyReact }).CardifyReact =
  CardifyReact;

/* The loader script is deferred, so the DOM is parsed by the time this runs in
 * the normal case; the readyState guard covers a page that injects the bundle
 * earlier. */
if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", () => mountAll());
} else {
  mountAll();
}
