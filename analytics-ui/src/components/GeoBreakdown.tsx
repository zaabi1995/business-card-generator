import { useQuery } from "convex/react";
import { api } from "../api";

interface CountryRow {
  code: string;
  name: string;
  count: number;
  unique: number;
}

const FLAG_FALLBACK = "🌐";

function flag(code: string): string {
  if (!code || code.length !== 2 || code === "??") return FLAG_FALLBACK;
  const upper = code.toUpperCase();
  return String.fromCodePoint(
    0x1f1e6 + upper.charCodeAt(0) - 65,
    0x1f1e6 + upper.charCodeAt(1) - 65,
  );
}

export function GeoBreakdown({
  token,
  employeeId,
  days,
}: {
  token: string;
  employeeId: string | null;
  days: number;
}) {
  const rows = useQuery(api.events.byCountry, {
    token,
    employeeId: employeeId ?? undefined,
    days,
  }) as CountryRow[] | undefined;

  if (rows === undefined) {
    return <div className="muted">Loading geographic breakdown…</div>;
  }
  if (rows.length === 0) {
    return <div className="muted">No location data yet.</div>;
  }

  const max = rows[0]?.count ?? 1;

  return (
    <table className="geo-table">
      <thead>
        <tr>
          <th></th>
          <th>Country</th>
          <th>Events</th>
          <th>Unique</th>
          <th>Share</th>
        </tr>
      </thead>
      <tbody>
        {rows.map((r) => (
          <tr key={r.code}>
            <td className="geo-table__flag">{flag(r.code)}</td>
            <td>{r.name}</td>
            <td className="geo-table__num">{r.count}</td>
            <td className="geo-table__num">{r.unique}</td>
            <td className="geo-table__bar">
              <div
                className="geo-table__bar-fill"
                style={{ width: `${(r.count / max) * 100}%` }}
              />
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
