/*
 * http.ts
 *
 * HTTP router. Self-hosted Convex serves these on port 3211 (mapped to
 * https://cardify.om/_convex/http via nginx).
 *
 *   POST /ingest  -> events.ingestEvent  (PHP server-to-server)
 */

import { httpRouter } from "convex/server";
import { httpAction } from "./_generated/server";
import { ingestEvent } from "./events";

const http = httpRouter();

http.route({
  path: "/ingest",
  method: "POST",
  handler: ingestEvent,
});

http.route({
  path: "/healthz",
  method: "GET",
  handler: httpAction(async () =>
    new Response(JSON.stringify({ ok: true, ts: Date.now() }), {
      status: 200,
      headers: { "content-type": "application/json" },
    }),
  ),
});

export default http;
