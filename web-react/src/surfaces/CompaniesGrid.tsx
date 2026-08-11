/* Admin > Companies, on the Beautiful UI language.
 *
 * A FORK of the kit's RecordsTable + FilterTable + SearchList patterns rather
 * than an import: all three take no props and carry their own demo data, so a
 * real grid can only be built by rebuilding the markup against the same tokens.
 * The originals stay pristine at design-kit.php for diffing.
 *
 * READ SIDE ONLY, deliberately. The rows, their search, filtering and sorting
 * move here; every mutation (create, edit, delete, plan change) stays on the
 * existing POST forms in admin/companies.php. Porting a destructive admin
 * action to a new client at the same time as a visual rewrite is how you lose a
 * tenant record, and none of it would make the grid nicer to use.
 *
 * Data arrives through data-props from the page's existing query -- no new API
 * endpoint, so no new auth surface to get wrong.
 *
 * EVERY user-facing string comes in through props.labels, resolved by PHP's
 * t() against lang/{en,ar}/companiesmgmt.php. The grid holds no copy of its
 * own: the repo rule is that a string lands in both locales in the same
 * commit, and a second, JS-side string table would quietly escape that.
 *
 * Progressive enhancement: the server-rendered table sits INSIDE the mount
 * point and React replaces it on mount. JS off or bundle 404 leaves the admin
 * with the working PHP table rather than an empty page.
 */

import { useMemo, useState } from "react";

export type CompanyRow = {
  id: number | string;
  name: string;
  slug: string;
  admin_email: string | null;
  billing_email: string | null;
  plan: string | null;
  currency: string | null;
  status: string | null;
  employee_count: number;
  order_count: number;
  total_spend: number;
};

type SortKey = "name" | "employee_count" | "order_count" | "total_spend";

const PLAN_TONE: Record<string, string> = {
  enterprise: "bg-accent-tint text-accent-ink",
  pro: "bg-green-tint text-green",
  free: "bg-field text-ink-2",
};

/* Money is rendered in the company's OWN currency and never converted
 * client-side: mixing currencies in one column and silently normalising them is
 * a reporting bug, not a formatting choice.
 *
 * Fraction digits are left to Intl rather than hardcoded to 2, because OMR, BHD
 * and KWD are 3-decimal currencies and OMR is the common case here. */
function money(amount: number, currency: string | null): string {
  const code = (currency || "OMR").toUpperCase();
  try {
    return new Intl.NumberFormat("en", {
      style: "currency",
      currency: code,
      currencyDisplay: "code",
    }).format(amount);
  } catch {
    return code + " " + amount.toFixed(3);
  }
}

function initials(name: string): string {
  return name.slice(0, 2).toUpperCase();
}

/* Server-resolved copy. Falls back to the English wording only if PHP failed
 * to pass a key, which would be a bug rather than a supported path. */
const FALLBACK = {
  grid_search_ph: "Search name, slug or admin email",
  grid_col_people: "People",
  grid_col_orders: "Orders",
  grid_col_spend: "Spend",
  grid_filter_all: "all",
  grid_billing: "Billing",
  grid_empty: "No companies yet",
  grid_no_matches: "Nothing matches that filter",
  grid_of: "of",
  grid_companies: "companies",
  grid_mixed_cur: "mixed currencies",
  col_company: "Company",
  col_admin: "Admin",
};
type LabelKey = keyof typeof FALLBACK;

export default function CompaniesGrid({
  props,
}: {
  props: Record<string, unknown>;
}) {
  const rows = (Array.isArray(props.rows) ? props.rows : []) as CompanyRow[];
  const editBase = typeof props.editBase === "string" ? props.editBase : "";
  const rtl = props.rtl === true;

  const labels = (props.labels || {}) as Partial<Record<LabelKey, string>>;
  const L = (k: LabelKey): string => labels[k] || FALLBACK[k];

  const [needle, setNeedle] = useState("");
  const [plan, setPlan] = useState<string>("all");
  const [sort, setSort] = useState<SortKey>("name");
  const [desc, setDesc] = useState(false);

  const plans = useMemo(() => {
    const seen = new Map<string, number>();
    for (const r of rows) {
      const p = (r.plan || "free").toLowerCase();
      seen.set(p, (seen.get(p) || 0) + 1);
    }
    return [...seen.entries()].sort((a, b) => b[1] - a[1]);
  }, [rows]);

  const shown = useMemo(() => {
    const q = needle.trim().toLowerCase();
    const out = rows.filter((r) => {
      if (plan !== "all" && (r.plan || "free").toLowerCase() !== plan) return false;
      if (!q) return true;
      return (
        r.name.toLowerCase().includes(q) ||
        r.slug.toLowerCase().includes(q) ||
        (r.admin_email || "").toLowerCase().includes(q)
      );
    });
    out.sort((a, b) => {
      const dir = desc ? -1 : 1;
      if (sort === "name") return dir * a.name.localeCompare(b.name);
      return dir * ((Number(a[sort]) || 0) - (Number(b[sort]) || 0));
    });
    return out;
  }, [rows, needle, plan, sort, desc]);

  const totals = useMemo(
    () => ({
      employees: shown.reduce((n, r) => n + (Number(r.employee_count) || 0), 0),
      orders: shown.reduce((n, r) => n + (Number(r.order_count) || 0), 0),
    }),
    [shown],
  );

  const Th = ({ k, label }: { k: SortKey; label: string }) => (
    <button
      type="button"
      onClick={() => {
        if (sort === k) setDesc(!desc);
        else {
          setSort(k);
          setDesc(false);
        }
      }}
      className="flex items-center gap-1 text-[12px] font-medium text-ink-2
                 transition-colors duration-100 hover:text-ink"
    >
      {label}
      <span
        aria-hidden
        className={
          "font-mono text-[10px] " +
          (sort === k ? "text-accent" : "text-ink-3 opacity-0")
        }
      >
        {desc ? "↓" : "↑"}
      </span>
    </button>
  );

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <label className="relative flex h-8 min-w-[220px] flex-1 items-center">
          <span className="sr-only">{L("grid_search_ph")}</span>
          <input
            value={needle}
            onChange={(e) => setNeedle(e.target.value)}
            placeholder={L("grid_search_ph")}
            className="h-8 w-full rounded-control bg-field px-2.5 text-[12.5px] text-ink
                       shadow-inset-field outline-none placeholder:text-ink-3"
          />
        </label>

        <div className="flex flex-wrap items-center gap-1.5">
          {[["all", rows.length] as [string, number], ...plans].map(([key, count]) => {
            const active = plan === key;
            return (
              <button
                key={key}
                type="button"
                aria-pressed={active}
                onClick={() => setPlan(key)}
                className={
                  "inline-flex h-7 items-center gap-1.5 rounded-chip px-2.5 text-[12px] " +
                  "font-medium capitalize transition-[background-color,color,transform] " +
                  "duration-150 active:scale-[0.96] " +
                  (active
                    ? "bg-accent-tint text-accent-ink"
                    : "text-ink-2 hover:bg-hover hover:text-ink")
                }
              >
                {key === "all" ? L("grid_filter_all") : key}
                <span className="font-mono text-[11px] tabular-nums opacity-70">
                  {count}
                </span>
              </button>
            );
          })}
        </div>
      </div>

      <div className="overflow-hidden rounded-card bg-surface shadow-card">
        <div className="primitive-table-cell flex items-center gap-4 border-b border-line-strong">
          <div className="min-w-0 flex-[2]"><Th k="name" label={L("col_company")} /></div>
          <div className="hidden min-w-0 flex-[2] md:block">
            <span className="text-[12px] font-medium text-ink-2">{L("col_admin")}</span>
          </div>
          <div className="w-24 shrink-0"><Th k="employee_count" label={L("grid_col_people")} /></div>
          <div className="w-24 shrink-0"><Th k="order_count" label={L("grid_col_orders")} /></div>
          <div className="w-32 shrink-0"><Th k="total_spend" label={L("grid_col_spend")} /></div>
        </div>

        {shown.length === 0 ? (
          <p className="px-6 py-14 text-center text-[13px] text-ink-2">
            {rows.length === 0 ? L("grid_empty") : L("grid_no_matches")}
          </p>
        ) : (
          shown.map((r) => {
            const planKey = (r.plan || "free").toLowerCase();
            return (
              <a
                key={r.id}
                href={
                  editBase
                    ? editBase + encodeURIComponent(String(r.id))
                    : "/" + encodeURIComponent(r.slug)
                }
                className="primitive-table-cell flex items-center gap-4 border-b border-line
                           transition-colors duration-100 last:border-b-0 hover:bg-hover"
              >
                <span className="flex min-w-0 flex-[2] items-center gap-2.5">
                  <span
                    aria-hidden
                    className="flex size-8 shrink-0 items-center justify-center rounded-control
                               bg-accent-tint text-[11px] font-semibold text-accent-ink"
                  >
                    {initials(r.name)}
                  </span>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate text-[13px] font-medium text-ink">{r.name}</span>
                    <span className="truncate font-mono text-[11px] text-ink-3">{r.slug}</span>
                  </span>
                  <span
                    className={
                      "ms-1 shrink-0 rounded-chip px-1.5 py-0.5 text-[11px] font-medium capitalize " +
                      (PLAN_TONE[planKey] || PLAN_TONE.free)
                    }
                  >
                    {planKey}
                  </span>
                  {r.status && r.status !== "active" && (
                    <span className="shrink-0 rounded-chip bg-orange-tint px-1.5 py-0.5 text-[11px] text-orange">
                      {r.status}
                    </span>
                  )}
                </span>

                <span className="hidden min-w-0 flex-[2] flex-col md:flex">
                  <span className="truncate text-[12.5px] text-ink-2">
                    {r.admin_email || "—"}
                  </span>
                  {r.billing_email && r.billing_email !== r.admin_email && (
                    <span className="truncate text-[11px] text-ink-3">
                      {L("grid_billing")}: {r.billing_email}
                    </span>
                  )}
                </span>

                <span className="w-24 shrink-0 font-mono text-[12px] text-ink tabular-nums">
                  {r.employee_count}
                </span>
                <span className="w-24 shrink-0 font-mono text-[12px] text-ink-2 tabular-nums">
                  {r.order_count}
                </span>
                <span className="w-32 shrink-0 font-mono text-[12px] text-ink tabular-nums">
                  {money(Number(r.total_spend) || 0, r.currency)}
                </span>
              </a>
            );
          })
        )}

        {shown.length > 0 && (
          <div className="primitive-card-footer flex items-center gap-4 border-t border-line-strong bg-inset">
            <span className="flex-[2] text-[12px] text-ink-3">
              <span className="font-mono tabular-nums">{shown.length}</span>
              {shown.length === rows.length
                ? " " + L("grid_companies")
                : " " + L("grid_of") + " " + rows.length}
            </span>
            <span className="hidden flex-[2] md:block" />
            <span className="w-24 shrink-0 font-mono text-[12px] text-ink-2 tabular-nums">
              {totals.employees}
            </span>
            <span className="w-24 shrink-0 font-mono text-[12px] text-ink-2 tabular-nums">
              {totals.orders}
            </span>
            {/* No spend total: rows can carry different currencies and summing
                across them would print a number that means nothing. */}
            <span className="w-32 shrink-0 text-[11px] text-ink-3">{L("grid_mixed_cur")}</span>
          </div>
        )}
      </div>
    </div>
  );
}
