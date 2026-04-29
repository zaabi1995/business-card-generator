/*
 * api.ts
 *
 * Convex function references for the React island. We use `anyApi` instead
 * of importing `../convex/_generated/api` because that path is gitignored and
 * produced only at deploy via `npx convex deploy`. Stringly-typed but enough
 * for an island that only consumes 5 queries.
 *
 * If you ever want type safety, run `npx convex dev --once` from the convex/
 * directory to generate the types and switch to `import { api } from
 * "../../convex/_generated/api"`.
 */

import { anyApi } from "convex/server";

export const api = {
  events: {
    liveCounter: anyApi.events.liveCounter,
    recentActivity: anyApi.events.recentActivity,
    byCountry: anyApi.events.byCountry,
    timeline: anyApi.events.timeline,
    livePresence: anyApi.events.livePresence,
  },
};
