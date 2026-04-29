/*
 * tenant.ts
 *
 * Multi-tenant safety for Cardify Convex. Every domain table carries
 * `tenantId: v.id("companies")` and `by_tenant_*` indexes. These helpers
 * enforce that no query crosses the tenant boundary.
 *
 * Usage in a query/mutation:
 *
 *   const tenantId = await requireTenantId(ctx);
 *   const events = await ctx.db.query("events")
 *     .withIndex("by_tenant_ts", q => q.eq("tenantId", tenantId))
 *     .order("desc").take(50);
 */

import type { QueryCtx, MutationCtx } from "../_generated/server";
import type { Doc, Id } from "../_generated/dataModel";

export class TenantError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "TenantError";
  }
}

export async function requireTenantId(
  ctx: QueryCtx | MutationCtx,
): Promise<Id<"companies">> {
  const identity = await ctx.auth.getUserIdentity();
  if (!identity) {
    throw new TenantError("UNAUTHENTICATED");
  }
  const tenantSlug = (identity as { tenantSlug?: string }).tenantSlug;
  const tenantIdClaim = (identity as { tenantId?: string }).tenantId;

  if (tenantIdClaim) {
    const company = await ctx.db.get(tenantIdClaim as Id<"companies">);
    if (company) return company._id;
  }

  if (tenantSlug) {
    const company = await ctx.db
      .query("companies")
      .withIndex("by_slug", (q) => q.eq("slug", tenantSlug))
      .unique();
    if (company) return company._id;
  }

  throw new TenantError("TENANT_NOT_RESOLVABLE");
}

export function assertSameTenant<T extends { tenantId: Id<"companies"> }>(
  doc: T | null,
  tenantId: Id<"companies">,
): T {
  if (!doc) throw new TenantError("DOC_NOT_FOUND");
  if (doc.tenantId !== tenantId) throw new TenantError("CROSS_TENANT_DENIED");
  return doc;
}
