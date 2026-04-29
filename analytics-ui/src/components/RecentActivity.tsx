import { useQuery } from "convex/react";
import { api } from "../api";

interface ActivityRow {
  _id: string;
  type: string;
  employeeId: string;
  ctaTarget: string | null;
  countryCode: string | null;
  countryName: string | null;
  city: string | null;
  device: string | null;
  browser: string | null;
  ts: number;
}

const TYPE_LABEL: Record<string, string> = {
  view: "viewed",
  qr_scan: "scanned QR",
  click_phone: "tapped phone",
  click_mobile: "tapped mobile",
  click_whatsapp: "opened WhatsApp",
  click_email: "opened email",
  click_website: "opened website",
  click_map: "opened map",
  click_social: "opened social",
  save_contact: "saved contact",
  wallet_add: "added to wallet",
  offer_redeem: "redeemed offer",
  product_order_click: "clicked product",
  short_link_click: "clicked short link",
  viral_footer_click: "clicked viral footer",
  viral_footer_view: "saw viral footer",
};

function timeAgo(ts: number): string {
  const s = Math.max(1, Math.floor((Date.now() - ts) / 1000));
  if (s < 60) return `${s}s ago`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ago`;
  const d = Math.floor(h / 24);
  return `${d}d ago`;
}

export function RecentActivity({
  token,
  employeeId,
  limit = 30,
}: {
  token: string;
  employeeId: string | null;
  limit?: number;
}) {
  const rows = useQuery(api.events.recentActivity, {
    token,
    employeeId: employeeId ?? undefined,
    limit,
  }) as ActivityRow[] | undefined;

  if (rows === undefined) {
    return <div className="muted">Loading recent activity…</div>;
  }
  if (rows.length === 0) {
    return <div className="muted">No activity yet. As cards get viewed, events stream here in real time.</div>;
  }

  return (
    <ul className="activity">
      {rows.map((r) => (
        <li key={r._id} className="activity__row">
          <span className={`activity__dot activity__dot--${r.type}`} />
          <span className="activity__line">
            <strong>{TYPE_LABEL[r.type] ?? r.type}</strong>
            {r.city || r.countryName ? (
              <span className="activity__where">
                {[r.city, r.countryName].filter(Boolean).join(", ")}
              </span>
            ) : null}
            {r.device ? <span className="activity__device">{r.device}</span> : null}
          </span>
          <span className="activity__ts">{timeAgo(r.ts)}</span>
        </li>
      ))}
    </ul>
  );
}
