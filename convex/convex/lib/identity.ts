/*
 * identity.ts
 *
 * Inline HS256 JWT verifier. Convex self-hosted does not natively support
 * symmetric-secret customJwt providers, so reactive queries accept the JWT
 * as a `token` argument and we verify it here.
 *
 * The secret is read from process.env.CONVEX_AUTH_SECRET (shared with the
 * issuer in PHP, see admin/live-analytics.php::mintConvexToken).
 *
 * Claims expected on the JWT:
 *
 *   {
 *     iss: "cardify-admin",
 *     aud: "convex-cardify",
 *     sub: "<admin user id>",
 *     tenantId:  "<companies._id or mysqlId>",
 *     tenantSlug: "<company slug>",
 *     role: "company_admin" | "admin" | "super_admin" | ...,
 *     nameEn: "...",
 *     iat, exp
 *   }
 */

import type { QueryCtx, MutationCtx } from "../_generated/server";
import type { Id } from "../_generated/dataModel";

const ENV_KEY = "CONVEX_AUTH_SECRET";
const EXPECTED_ISS = "cardify-admin";
const EXPECTED_AUD = "convex-cardify";

export class IdentityError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "IdentityError";
  }
}

export interface CardifyIdentity {
  adminId: string;
  tenantId: Id<"companies">;
  tenantSlug?: string;
  role: string;
  nameEn?: string;
}

function base64UrlDecode(b64url: string): Uint8Array {
  const b64 = b64url.replace(/-/g, "+").replace(/_/g, "/");
  const padded = b64 + "==".slice(0, (4 - (b64.length % 4)) % 4);
  const binary = atob(padded);
  const out = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) out[i] = binary.charCodeAt(i);
  return out;
}

function base64UrlDecodeText(b64url: string): string {
  return new TextDecoder().decode(base64UrlDecode(b64url));
}

async function verifyHs256(
  token: string,
  secret: string,
): Promise<Record<string, unknown>> {
  const parts = token.split(".");
  if (parts.length !== 3) throw new IdentityError("BAD_JWT_STRUCTURE");
  const [headerB64, payloadB64, sigB64] = parts;

  const header = JSON.parse(base64UrlDecodeText(headerB64));
  if (header.alg !== "HS256" || header.typ !== "JWT") {
    throw new IdentityError("BAD_JWT_HEADER");
  }

  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    "raw",
    enc.encode(secret),
    { name: "HMAC", hash: "SHA-256" },
    false,
    ["verify"],
  );
  const signature = base64UrlDecode(sigB64);
  const data = enc.encode(`${headerB64}.${payloadB64}`);
  const ok = await crypto.subtle.verify("HMAC", key, signature, data);
  if (!ok) throw new IdentityError("BAD_JWT_SIGNATURE");

  const payload = JSON.parse(base64UrlDecodeText(payloadB64));
  const now = Math.floor(Date.now() / 1000);
  if (typeof payload.exp === "number" && payload.exp < now) {
    throw new IdentityError("JWT_EXPIRED");
  }
  if (typeof payload.nbf === "number" && payload.nbf > now + 5) {
    throw new IdentityError("JWT_NOT_YET_VALID");
  }
  if (payload.iss !== EXPECTED_ISS) throw new IdentityError("BAD_JWT_ISSUER");
  if (payload.aud !== EXPECTED_AUD) throw new IdentityError("BAD_JWT_AUDIENCE");

  return payload;
}

export async function requireIdentity(
  ctx: QueryCtx | MutationCtx,
  token: string | undefined,
): Promise<CardifyIdentity> {
  if (!token) throw new IdentityError("MISSING_TOKEN");
  const secret = process.env[ENV_KEY];
  if (!secret) throw new IdentityError("AUTH_SECRET_NOT_CONFIGURED");

  const claims = await verifyHs256(token, secret);

  let tenantId: Id<"companies"> | null = null;
  const claimedTenantId = claims.tenantId as string | undefined;
  const claimedTenantSlug = claims.tenantSlug as string | undefined;

  // First try the claim as a Convex Id
  if (claimedTenantId) {
    try {
      const company = await ctx.db.get(claimedTenantId as Id<"companies">);
      if (company) tenantId = company._id;
    } catch {
      // Not a Convex Id; fall through to MySQL-id lookup
    }
    if (!tenantId) {
      const company = await ctx.db
        .query("companies")
        .withIndex("by_mysql_id", (q) => q.eq("mysqlId", claimedTenantId))
        .unique();
      if (company) tenantId = company._id;
    }
  }
  if (!tenantId && claimedTenantSlug) {
    const company = await ctx.db
      .query("companies")
      .withIndex("by_slug", (q) => q.eq("slug", claimedTenantSlug))
      .unique();
    if (company) tenantId = company._id;
  }
  if (!tenantId) throw new IdentityError("TENANT_NOT_RESOLVABLE");

  return {
    adminId: (claims.sub as string | undefined) ?? "unknown",
    tenantId,
    tenantSlug: claimedTenantSlug,
    role: (claims.role as string | undefined) ?? "company_admin",
    nameEn: claims.nameEn as string | undefined,
  };
}
