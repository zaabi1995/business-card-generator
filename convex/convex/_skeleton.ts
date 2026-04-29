/*
 * _skeleton.ts
 *
 * Bootstrap helpers for the Cardify Convex backend.
 *
 * - upsertCompany: idempotent upsert of a Cardify company into the Convex
 *   `companies` table (the tenancy anchor). PHP calls this once per company
 *   on first event ingestion via the HTTP action.
 *
 * - resolveTenant: looks up the Convex `companies._id` for a Cardify company
 *   slug, used by reactive queries when the JWT carries `tenantSlug`.
 */

import { internalMutation, internalQuery } from "./_generated/server";
import { v } from "convex/values";

export const upsertCompany = internalMutation({
  args: {
    slug: v.string(),
    nameEn: v.string(),
    nameAr: v.optional(v.string()),
    mysqlId: v.string(),
  },
  handler: async (ctx, args) => {
    const existing = await ctx.db
      .query("companies")
      .withIndex("by_slug", (q) => q.eq("slug", args.slug))
      .unique();

    if (existing) {
      await ctx.db.patch(existing._id, {
        nameEn: args.nameEn,
        nameAr: args.nameAr,
        mysqlId: args.mysqlId,
      });
      return existing._id;
    }

    return await ctx.db.insert("companies", {
      slug: args.slug,
      nameEn: args.nameEn,
      nameAr: args.nameAr,
      mysqlId: args.mysqlId,
      createdAt: Date.now(),
    });
  },
});

export const resolveTenant = internalQuery({
  args: { slug: v.string() },
  handler: async (ctx, args) => {
    return await ctx.db
      .query("companies")
      .withIndex("by_slug", (q) => q.eq("slug", args.slug))
      .unique();
  },
});

export const resolveTenantByMysqlId = internalQuery({
  args: { mysqlId: v.string() },
  handler: async (ctx, args) => {
    return await ctx.db
      .query("companies")
      .withIndex("by_mysql_id", (q) => q.eq("mysqlId", args.mysqlId))
      .unique();
  },
});
