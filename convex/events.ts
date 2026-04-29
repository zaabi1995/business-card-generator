/*
 * events.ts
 *
 * Card-event ingestion + reactive queries for the live admin analytics
 * surface.
 *
 * Trust boundary:
 *   - `ingestEvent` (HTTP action) requires the shared `x-cardify-ingest-secret`
 *     header. PHP fires events here from `CardAnalytics::log()`.
 *   - `liveCounter`, `recentActivity`, `byCountry`, `timeline` are reactive
 *     queries; they require an HS256 JWT (admin browser session) and scope
 *     to the caller's tenant.
 */

import { httpAction, internalMutation, query } from "./_generated/server";
import { internal } from "./_generated/api";
import { v } from "convex/values";
import { verifyIngestSecret, IngestAuthError } from "./lib/ingestAuth";
import { requireIdentity } from "./lib/identity";
import type { Id } from "./_generated/dataModel";

const EVENT_TYPE_LITERALS = v.union(
  v.literal("view"),
  v.literal("qr_scan"),
  v.literal("click_phone"),
  v.literal("click_mobile"),
  v.literal("click_whatsapp"),
  v.literal("click_email"),
  v.literal("click_website"),
  v.literal("click_map"),
  v.literal("click_social"),
  v.literal("save_contact"),
  v.literal("wallet_add"),
  v.literal("offer_redeem"),
  v.literal("product_order_click"),
  v.literal("short_link_click"),
  v.literal("viral_footer_click"),
  v.literal("viral_footer_view"),
);

/**
 * HTTP action invoked by PHP. Validates the shared secret, resolves (or
 * upserts) the tenant by mysqlId, then writes the event row.
 *
 * Body shape:
 * {
 *   companyMysqlId: "abc123",
 *   companySlug:    "bhd",
 *   companyNameEn:  "BHD Group",
 *   companyNameAr:  "...",
 *   employeeId:     "emp-uuid",
 *   type:           "view" | ...,
 *   ctaTarget?:     "tel:+968...",
 *   visitorId:      "sha256-hash",
 *   ip?, countryCode?, countryName?, city?, device?, browser?, os?, referrer?
 * }
 */
export const ingestEvent = httpAction(async (ctx, req) => {
  try {
    verifyIngestSecret(req);
  } catch (err) {
    if (err instanceof IngestAuthError) {
      return new Response(JSON.stringify({ ok: false, error: err.message }), {
        status: 401,
        headers: { "content-type": "application/json" },
      });
    }
    throw err;
  }

  let body: Record<string, unknown>;
  try {
    body = (await req.json()) as Record<string, unknown>;
  } catch {
    return new Response(JSON.stringify({ ok: false, error: "BAD_JSON" }), {
      status: 400,
      headers: { "content-type": "application/json" },
    });
  }

  const required = ["companyMysqlId", "companySlug", "employeeId", "type", "visitorId"];
  for (const k of required) {
    if (typeof body[k] !== "string" || (body[k] as string).length === 0) {
      return new Response(
        JSON.stringify({ ok: false, error: `MISSING_${k.toUpperCase()}` }),
        { status: 400, headers: { "content-type": "application/json" } },
      );
    }
  }

  await ctx.runMutation(internal.events.persistIngested, {
    companyMysqlId: body.companyMysqlId as string,
    companySlug: body.companySlug as string,
    companyNameEn: (body.companyNameEn as string | undefined) ?? (body.companySlug as string),
    companyNameAr: body.companyNameAr as string | undefined,
    employeeId: body.employeeId as string,
    type: body.type as string,
    ctaTarget: body.ctaTarget as string | undefined,
    visitorId: body.visitorId as string,
    ip: body.ip as string | undefined,
    countryCode: body.countryCode as string | undefined,
    countryName: body.countryName as string | undefined,
    city: body.city as string | undefined,
    device: body.device as string | undefined,
    browser: body.browser as string | undefined,
    os: body.os as string | undefined,
    referrer: body.referrer as string | undefined,
  });

  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { "content-type": "application/json" },
  });
});

export const persistIngested = internalMutation({
  args: {
    companyMysqlId: v.string(),
    companySlug: v.string(),
    companyNameEn: v.string(),
    companyNameAr: v.optional(v.string()),
    employeeId: v.string(),
    type: v.string(),
    ctaTarget: v.optional(v.string()),
    visitorId: v.string(),
    ip: v.optional(v.string()),
    countryCode: v.optional(v.string()),
    countryName: v.optional(v.string()),
    city: v.optional(v.string()),
    device: v.optional(v.string()),
    browser: v.optional(v.string()),
    os: v.optional(v.string()),
    referrer: v.optional(v.string()),
  },
  handler: async (ctx, args) => {
    let company = await ctx.db
      .query("companies")
      .withIndex("by_mysql_id", (q) => q.eq("mysqlId", args.companyMysqlId))
      .unique();

    if (!company) {
      const id = await ctx.db.insert("companies", {
        slug: args.companySlug,
        nameEn: args.companyNameEn,
        nameAr: args.companyNameAr,
        mysqlId: args.companyMysqlId,
        createdAt: Date.now(),
      });
      company = await ctx.db.get(id);
    }
    if (!company) throw new Error("COMPANY_UPSERT_FAILED");

    const allowed = new Set([
      "view", "qr_scan",
      "click_phone", "click_mobile", "click_whatsapp", "click_email",
      "click_website", "click_map", "click_social",
      "save_contact", "wallet_add", "offer_redeem",
      "product_order_click", "short_link_click",
      "viral_footer_click", "viral_footer_view",
    ]);
    if (!allowed.has(args.type)) {
      throw new Error("INVALID_EVENT_TYPE");
    }

    const ts = Date.now();
    // Runtime-validated above against the `allowed` Set, so the cast is safe.
    // The concrete literal-union type lives in _generated/dataModel (gitignored).
    await ctx.db.insert("events", {
      tenantId: company._id,
      employeeId: args.employeeId,
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      type: args.type as any,
      ctaTarget: args.ctaTarget,
      visitorId: args.visitorId,
      ip: args.ip,
      countryCode: args.countryCode,
      countryName: args.countryName,
      city: args.city,
      device: args.device,
      browser: args.browser,
      os: args.os,
      referrer: args.referrer,
      ts,
    });

    const existingPresence = await ctx.db
      .query("presence")
      .withIndex("by_visitor", (q) => q.eq("visitorId", args.visitorId))
      .unique();
    if (existingPresence) {
      await ctx.db.patch(existingPresence._id, {
        lastSeen: ts,
        countryCode: args.countryCode ?? existingPresence.countryCode,
        city: args.city ?? existingPresence.city,
      });
    } else {
      await ctx.db.insert("presence", {
        tenantId: company._id,
        employeeId: args.employeeId,
        visitorId: args.visitorId,
        lastSeen: ts,
        countryCode: args.countryCode,
        city: args.city,
      });
    }
  },
});

const DAY_MS = 24 * 60 * 60 * 1000;

export const liveCounter = query({
  args: {
    employeeId: v.optional(v.string()),
    days: v.optional(v.number()),
  },
  handler: async (ctx, args) => {
    const me = await requireIdentity(ctx);
    const since = Date.now() - (args.days ?? 7) * DAY_MS;

    const rows = await (args.employeeId
      ? ctx.db.query("events")
          .withIndex("by_tenant_employee_ts", (q) =>
            q.eq("tenantId", me.tenantId).eq("employeeId", args.employeeId as string).gte("ts", since))
          .collect()
      : ctx.db.query("events")
          .withIndex("by_tenant_ts", (q) =>
            q.eq("tenantId", me.tenantId).gte("ts", since))
          .collect()
    );

    let views = 0, qr = 0, clicks = 0, saves = 0, wallet = 0;
    const visitors = new Set<string>();
    for (const r of rows) {
      visitors.add(r.visitorId);
      switch (r.type) {
        case "view": views++; break;
        case "qr_scan": qr++; break;
        case "save_contact": saves++; break;
        case "wallet_add": wallet++; break;
        default:
          if (r.type.startsWith("click_")) clicks++;
      }
    }
    return {
      views, qr_scans: qr, clicks, saves, wallet_adds: wallet,
      unique_visitors: visitors.size,
      total_events: rows.length,
    };
  },
});

export const recentActivity = query({
  args: {
    employeeId: v.optional(v.string()),
    limit: v.optional(v.number()),
  },
  handler: async (ctx, args) => {
    const me = await requireIdentity(ctx);
    const limit = Math.min(Math.max(args.limit ?? 30, 1), 100);

    const rows = await (args.employeeId
      ? ctx.db.query("events")
          .withIndex("by_tenant_employee_ts", (q) =>
            q.eq("tenantId", me.tenantId).eq("employeeId", args.employeeId as string))
          .order("desc").take(limit)
      : ctx.db.query("events")
          .withIndex("by_tenant_ts", (q) => q.eq("tenantId", me.tenantId))
          .order("desc").take(limit)
    );

    return rows.map((r) => ({
      _id: r._id,
      type: r.type,
      employeeId: r.employeeId,
      ctaTarget: r.ctaTarget ?? null,
      countryCode: r.countryCode ?? null,
      countryName: r.countryName ?? null,
      city: r.city ?? null,
      device: r.device ?? null,
      browser: r.browser ?? null,
      ts: r.ts,
    }));
  },
});

export const byCountry = query({
  args: {
    employeeId: v.optional(v.string()),
    days: v.optional(v.number()),
  },
  handler: async (ctx, args) => {
    const me = await requireIdentity(ctx);
    const since = Date.now() - (args.days ?? 7) * DAY_MS;

    const rows = await (args.employeeId
      ? ctx.db.query("events")
          .withIndex("by_tenant_employee_ts", (q) =>
            q.eq("tenantId", me.tenantId).eq("employeeId", args.employeeId as string).gte("ts", since))
          .collect()
      : ctx.db.query("events")
          .withIndex("by_tenant_ts", (q) =>
            q.eq("tenantId", me.tenantId).gte("ts", since))
          .collect()
    );

    const counts = new Map<string, { code: string; name: string; count: number; visitors: Set<string> }>();
    for (const r of rows) {
      const code = r.countryCode ?? "??";
      const name = r.countryName ?? "Unknown";
      const entry = counts.get(code) ?? { code, name, count: 0, visitors: new Set() };
      entry.count++;
      entry.visitors.add(r.visitorId);
      counts.set(code, entry);
    }
    return Array.from(counts.values())
      .map((e) => ({ code: e.code, name: e.name, count: e.count, unique: e.visitors.size }))
      .sort((a, b) => b.count - a.count);
  },
});

export const timeline = query({
  args: {
    employeeId: v.optional(v.string()),
    days: v.optional(v.number()),
    bucketMinutes: v.optional(v.number()),
  },
  handler: async (ctx, args) => {
    const me = await requireIdentity(ctx);
    const days = args.days ?? 7;
    const bucketMs = (args.bucketMinutes ?? 60) * 60 * 1000;
    const since = Date.now() - days * DAY_MS;

    const rows = await (args.employeeId
      ? ctx.db.query("events")
          .withIndex("by_tenant_employee_ts", (q) =>
            q.eq("tenantId", me.tenantId).eq("employeeId", args.employeeId as string).gte("ts", since))
          .collect()
      : ctx.db.query("events")
          .withIndex("by_tenant_ts", (q) =>
            q.eq("tenantId", me.tenantId).gte("ts", since))
          .collect()
    );

    const buckets = new Map<number, { ts: number; views: number; clicks: number; scans: number }>();
    for (const r of rows) {
      const bucket = Math.floor(r.ts / bucketMs) * bucketMs;
      const e = buckets.get(bucket) ?? { ts: bucket, views: 0, clicks: 0, scans: 0 };
      if (r.type === "view") e.views++;
      else if (r.type === "qr_scan") e.scans++;
      else if (r.type.startsWith("click_")) e.clicks++;
      buckets.set(bucket, e);
    }
    return Array.from(buckets.values()).sort((a, b) => a.ts - b.ts);
  },
});

export const livePresence = query({
  args: {
    employeeId: v.optional(v.string()),
    windowMinutes: v.optional(v.number()),
  },
  handler: async (ctx, args) => {
    const me = await requireIdentity(ctx);
    const since = Date.now() - (args.windowMinutes ?? 5) * 60 * 1000;

    if (args.employeeId) {
      const rows = await ctx.db
        .query("presence")
        .withIndex("by_tenant_employee_lastSeen", (q) =>
          q.eq("tenantId", me.tenantId).eq("employeeId", args.employeeId as string).gte("lastSeen", since))
        .collect();
      return rows.length;
    }
    const rows = await ctx.db
      .query("presence")
      .withIndex("by_tenant_employee_lastSeen", (q) =>
        q.eq("tenantId", me.tenantId))
      .collect();
    return rows.filter((r) => r.lastSeen >= since).length;
  },
});

// Imported by lib/tenant for type safety
export type _TenantId = Id<"companies">;
