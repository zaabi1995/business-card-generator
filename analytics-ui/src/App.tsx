import { useState } from "react";
import { useQuery } from "convex/react";
import { api } from "./api";
import { LiveCounter } from "./components/LiveCounter";
import { RecentActivity } from "./components/RecentActivity";
import { GeoBreakdown } from "./components/GeoBreakdown";
import { EventTimeline } from "./components/EventTimeline";
import "./styles.css";

type Tab = "live" | "activity" | "geo" | "timeline";

export function App({
  token,
  employeeId,
  days,
}: {
  token: string;
  employeeId: string | null;
  days: number;
}) {
  const [tab, setTab] = useState<Tab>("live");
  const [range, setRange] = useState<number>(days);

  return (
    <div className="cardify-live">
      <header className="cardify-live__header">
        <div>
          <h1>Live analytics</h1>
          <p className="muted">
            Real-time. Updates without refresh.{" "}
            {employeeId ? "Filtered to one employee." : "All employees."}
          </p>
        </div>
        <div className="cardify-live__range">
          {[1, 7, 30, 90].map((d) => (
            <button
              key={d}
              className={`pill ${range === d ? "pill--active" : ""}`}
              onClick={() => setRange(d)}
            >
              {d === 1 ? "Today" : `${d}d`}
            </button>
          ))}
        </div>
      </header>

      <LiveCounter token={token} employeeId={employeeId} days={range} />

      <nav className="cardify-live__tabs">
        {(
          [
            ["live", "Live presence"],
            ["activity", "Recent activity"],
            ["geo", "By country"],
            ["timeline", "Timeline"],
          ] as const
        ).map(([k, label]) => (
          <button
            key={k}
            className={`tab ${tab === k ? "tab--active" : ""}`}
            onClick={() => setTab(k)}
          >
            {label}
          </button>
        ))}
      </nav>

      <main className="cardify-live__panel">
        {tab === "live" && <LivePresenceTab token={token} employeeId={employeeId} />}
        {tab === "activity" && <RecentActivity token={token} employeeId={employeeId} />}
        {tab === "geo" && <GeoBreakdown token={token} employeeId={employeeId} days={range} />}
        {tab === "timeline" && (
          <EventTimeline token={token} employeeId={employeeId} days={range} />
        )}
      </main>
    </div>
  );
}

function LivePresenceTab({ token, employeeId }: { token: string; employeeId: string | null }) {
  const count = useQuery(api.events.livePresence, {
    token,
    employeeId: employeeId ?? undefined,
    windowMinutes: 5,
  }) as number | undefined;

  return (
    <div className="cardify-live__presence">
      <h3>Right now</h3>
      <p className="muted">
        Visitors active on the card in the last 5 minutes.
      </p>
      <div
        className={`presence-badge ${count && count > 0 ? "presence-badge--live" : ""}`}
      >
        <span className="dot" />
        {count === undefined ? "Connecting…" : `${count} viewing now`}
      </div>
      <hr />
      <RecentActivity token={token} employeeId={employeeId} limit={10} />
    </div>
  );
}
