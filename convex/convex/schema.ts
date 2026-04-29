import { defineSchema, defineTable } from "convex/server";
import { v } from "convex/values";

export const EVENT_TYPES = [
  "view",
  "qr_scan",
  "click_phone",
  "click_mobile",
  "click_whatsapp",
  "click_email",
  "click_website",
  "click_map",
  "click_social",
  "save_contact",
  "wallet_add",
  "offer_redeem",
  "product_order_click",
  "short_link_click",
  "viral_footer_click",
  "viral_footer_view",
] as const;

export default defineSchema({
  companies: defineTable({
    slug: v.string(),
    nameEn: v.string(),
    nameAr: v.optional(v.string()),
    mysqlId: v.string(),
    createdAt: v.number(),
  }).index("by_slug", ["slug"])
    .index("by_mysql_id", ["mysqlId"]),

  events: defineTable({
    tenantId: v.id("companies"),
    employeeId: v.string(),
    type: v.union(
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
    ),
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
    ts: v.number(),
  })
    .index("by_tenant_ts", ["tenantId", "ts"])
    .index("by_tenant_employee_ts", ["tenantId", "employeeId", "ts"])
    .index("by_tenant_type_ts", ["tenantId", "type", "ts"])
    .index("by_tenant_country_ts", ["tenantId", "countryCode", "ts"]),

  presence: defineTable({
    tenantId: v.id("companies"),
    employeeId: v.string(),
    visitorId: v.string(),
    lastSeen: v.number(),
    countryCode: v.optional(v.string()),
    city: v.optional(v.string()),
  })
    .index("by_tenant_employee_lastSeen", ["tenantId", "employeeId", "lastSeen"])
    .index("by_visitor", ["visitorId"]),
});
