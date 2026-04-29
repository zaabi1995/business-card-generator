import { useQuery } from "convex/react";
import { api } from "../api";

interface LiveCounterStats {
  views: number;
  qr_scans: number;
  clicks: number;
  saves: number;
  wallet_adds: number;
  unique_visitors: number;
  total_events: number;
}

export function LiveCounter({
  employeeId,
  days,
}: {
  employeeId: string | null;
  days: number;
}) {
  const stats = useQuery(api.events.liveCounter, {
    employeeId: employeeId ?? undefined,
    days,
  }) as LiveCounterStats | undefined;

  const cards: Array<[string, number | string, string]> = stats
    ? [
        ["Views", stats.views, "card-views"],
        ["QR scans", stats.qr_scans, "card-qr"],
        ["Clicks", stats.clicks, "card-clicks"],
        ["Saves", stats.saves, "card-saves"],
        ["Wallet adds", stats.wallet_adds, "card-wallet"],
        ["Unique visitors", stats.unique_visitors, "card-unique"],
      ]
    : [
        ["Views", "—", "card-views"],
        ["QR scans", "—", "card-qr"],
        ["Clicks", "—", "card-clicks"],
        ["Saves", "—", "card-saves"],
        ["Wallet adds", "—", "card-wallet"],
        ["Unique visitors", "—", "card-unique"],
      ];

  return (
    <section className="cardify-live__kpis">
      {cards.map(([label, value, key]) => (
        <div key={key} className="kpi">
          <div className="kpi__value">{value}</div>
          <div className="kpi__label">{label}</div>
        </div>
      ))}
    </section>
  );
}
