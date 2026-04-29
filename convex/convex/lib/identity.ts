/*
 * identity.ts
 *
 * Resolves the admin's identity from the HS256 JWT minted by Cardify PHP.
 * Claims expected on the JWT:
 *
 *   {
 *     iss: "cardify-admin",
 *     aud: "convex-cardify",
 *     sub: "<admin user id>",
 *     tenantId:  "<companies._id>",     // optional, fast path
 *     tenantSlug: "<company slug>",      // fallback
 *     role: "company_admin" | "admin" | "super_admin" | "printshop_admin",
 *     nameEn: "Ali Al-Zaabi",
 *     iat, exp
 *   }
 */

import type { QueryCtx, MutationCtx } from "../_generated/server";
import type { Id } from "../_generated/dataModel";
import { TenantError } from "./tenant";

export interface CardifyIdentity {
  adminId: string;
  tenantId: Id<"companies">;
  tenantSlug?: string;
  role: string;
  nameEn?: string;
}

export async function requireIdentity(
  ctx: QueryCtx | MutationCtx,
): Promise<CardifyIdentity> {
  const identity = await ctx.auth.getUserIdentity();
  if (!identity) throw new TenantError("UNAUTHENTICATED");

  const claims = identity as {
    subject?: string;
    tenantId?: string;
    tenantSlug?: string;
    role?: string;
    nameEn?: string;
  };

  let tenantId: Id<"companies"> | null = null;
  if (claims.tenantId) {
    const company = await ctx.db.get(claims.tenantId as Id<"companies">);
    if (company) tenantId = company._id;
  }
  if (!tenantId && claims.tenantSlug) {
    const company = await ctx.db
      .query("companies")
      .withIndex("by_slug", (q) => q.eq("slug", claims.tenantSlug as string))
      .unique();
    if (company) tenantId = company._id;
  }
  if (!tenantId) throw new TenantError("TENANT_NOT_RESOLVABLE");

  return {
    adminId: claims.subject ?? "unknown",
    tenantId,
    tenantSlug: claims.tenantSlug,
    role: claims.role ?? "company_admin",
    nameEn: claims.nameEn,
  };
}
