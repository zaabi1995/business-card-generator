import React from "react";
import ReactDOM from "react-dom/client";
import { ConvexProvider, ConvexReactClient } from "convex/react";
import { App } from "./App";

interface IslandConfig {
  convexUrl: string;
  token: string;
  employeeId: string | null;
  days: number;
}

function readConfig(root: HTMLElement): IslandConfig {
  const convexUrl = root.dataset.convexUrl ?? "";
  const token = root.dataset.token ?? "";
  const employeeId = root.dataset.employeeId || null;
  const days = Number(root.dataset.days || "7") || 7;
  return { convexUrl, token, employeeId, days };
}

const root = document.getElementById("live-analytics-root");
if (!root) {
  throw new Error("Cardify analytics: #live-analytics-root not found");
}

const cfg = readConfig(root);

if (!cfg.convexUrl) {
  root.innerHTML =
    '<div style="padding:24px;font-family:system-ui;color:#b91c1c">' +
    "Live analytics is not configured. Set <code>CONVEX_BROWSER_URL</code> in <code>config.php</code>." +
    "</div>";
} else {
  const client = new ConvexReactClient(cfg.convexUrl);
  ReactDOM.createRoot(root).render(
    <React.StrictMode>
      <ConvexProvider client={client}>
        <App token={cfg.token} employeeId={cfg.employeeId} days={cfg.days} />
      </ConvexProvider>
    </React.StrictMode>,
  );
}
