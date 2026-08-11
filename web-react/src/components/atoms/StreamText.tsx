"use client";

import { useEffect, useRef, useState } from "react";

/* ─────────────────────────────────────────────────────────
 * STREAM TEXT — token-by-token reveal with a blurred tail
 *
 * Reveals `charsPerTick` characters every `tickMs`. The last
 * `blurTail` characters get .stream-tail (blur + mask fade),
 * so text resolves into focus instead of popping in. A caret
 * blinks only once the stream has finished.
 *
 * Both classes and the caret-blink keyframe live in theme.css.
 * Reduced motion flattens the tail and freezes the caret.
 *
 * Recovered from the shipped bundle of beautiful-ui-five.vercel.app;
 * behaviour matches, formatting is ours.
 * ───────────────────────────────────────────────────────── */

export function StreamText({
  text,
  charsPerTick = 2,
  tickMs = 9,
  blurTail = 6,
  caret = true,
  className,
  onProgress,
  onDone,
}: {
  text: string;
  charsPerTick?: number;
  tickMs?: number;
  blurTail?: number;
  caret?: boolean;
  className?: string;
  onProgress?: () => void;
  onDone?: () => void;
}) {
  const [count, setCount] = useState(0);
  const progressRef = useRef(onProgress);
  const doneRef = useRef(onDone);
  progressRef.current = onProgress;
  doneRef.current = onDone;

  useEffect(() => {
    setCount(0);
    let n = 0;
    const id = setInterval(() => {
      n = Math.min(n + charsPerTick, text.length);
      setCount(n);
      progressRef.current?.();
      if (n >= text.length) {
        clearInterval(id);
        doneRef.current?.();
      }
    }, tickMs);
    return () => clearInterval(id);
  }, [text, charsPerTick, tickMs]);

  const streaming = count < text.length;
  const shown = text.slice(0, count);
  const head = streaming ? Math.max(0, shown.length - blurTail) : shown.length;

  return (
    <span className={className}>
      {shown.slice(0, head)}
      {head < shown.length && (
        <span className="stream-tail">{shown.slice(head)}</span>
      )}
      {caret && (
        <span
          aria-hidden
          className={`stream-caret${streaming ? " is-streaming" : ""}`}
        />
      )}
    </span>
  );
}
