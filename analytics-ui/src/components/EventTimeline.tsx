import { useQuery } from "convex/react";
import { api } from "../api";

interface BucketRow {
  ts: number;
  views: number;
  clicks: number;
  scans: number;
}

export function EventTimeline({
  token,
  employeeId,
  days,
}: {
  token: string;
  employeeId: string | null;
  days: number;
}) {
  const bucketMinutes = days <= 1 ? 15 : days <= 7 ? 60 : 360;
  const rows = useQuery(api.events.timeline, {
    token,
    employeeId: employeeId ?? undefined,
    days,
    bucketMinutes,
  }) as BucketRow[] | undefined;

  if (rows === undefined) {
    return <div className="muted">Loading timeline…</div>;
  }
  if (rows.length === 0) {
    return <div className="muted">No activity yet in the selected range.</div>;
  }

  const max = Math.max(
    1,
    ...rows.map((r) => r.views + r.clicks + r.scans),
  );

  return (
    <div className="timeline">
      <div className="timeline__legend">
        <span><i className="legend-views" /> Views</span>
        <span><i className="legend-scans" /> QR scans</span>
        <span><i className="legend-clicks" /> Clicks</span>
      </div>
      <div className="timeline__bars">
        {rows.map((r) => {
          const total = r.views + r.scans + r.clicks;
          const h = (total / max) * 100;
          const tooltip = new Date(r.ts).toLocaleString();
          return (
            <div
              key={r.ts}
              className="timeline__bar"
              title={`${tooltip}\nviews ${r.views} • scans ${r.scans} • clicks ${r.clicks}`}
            >
              <div
                className="timeline__bar-stack"
                style={{ height: `${h}%` }}
              >
                <div
                  className="timeline__seg timeline__seg--views"
                  style={{ flex: r.views }}
                />
                <div
                  className="timeline__seg timeline__seg--scans"
                  style={{ flex: r.scans }}
                />
                <div
                  className="timeline__seg timeline__seg--clicks"
                  style={{ flex: r.clicks }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
