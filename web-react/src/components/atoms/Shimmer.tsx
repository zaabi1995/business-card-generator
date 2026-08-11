"use client";

/* ─────────────────────────────────────────────────────────
 * SHIMMER — the "model is working" text treatment
 *
 * A gradient sweeps across the glyphs via background-clip,
 * so the label itself pulses instead of a spinner sitting
 * next to it. Used by every in-flight label in the kit
 * (Thinking, Loading State, Fine-tune Card, Tool Chips).
 *
 * Recovered from the shipped bundle of beautiful-ui-five.vercel.app;
 * behaviour matches, formatting is ours.
 * ───────────────────────────────────────────────────────── */

export function Shimmer({
  children,
  className = "",
}: {
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <span
      className={`inline-block bg-clip-text text-transparent ${className}`}
      style={{
        backgroundImage:
          "linear-gradient(90deg, var(--ink-3) 35%, var(--ink) 50%, var(--ink-3) 65%)",
        backgroundSize: "200% 100%",
        animation: "shimmer-text 1.8s linear infinite",
      }}
    >
      {children}
    </span>
  );
}
