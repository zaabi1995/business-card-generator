/*
 * ingestAuth.ts
 *
 * Server-to-server auth for the PHP -> Convex /ingest endpoint. The PHP
 * process authenticates with a shared secret in the `x-cardify-ingest-secret`
 * header. Browsers should NEVER hold this secret; use HS256 JWT (lib/identity)
 * for browser sessions.
 *
 * Constant-time compare to avoid timing attacks. Secret is read at call-time
 * from process.env so rotating without container restart is possible (set the
 * env var in compose then `docker compose up -d` to reload).
 */

const ENV_KEY = "CARDIFY_INGEST_SECRET";

export class IngestAuthError extends Error {
  constructor(message = "INGEST_AUTH_FAILED") {
    super(message);
    this.name = "IngestAuthError";
  }
}

function constantTimeEquals(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}

export function verifyIngestSecret(req: Request): void {
  const expected = process.env[ENV_KEY];
  if (!expected) {
    throw new IngestAuthError("INGEST_SECRET_NOT_CONFIGURED");
  }
  const presented = req.headers.get("x-cardify-ingest-secret");
  if (!presented || !constantTimeEquals(presented, expected)) {
    throw new IngestAuthError();
  }
}
