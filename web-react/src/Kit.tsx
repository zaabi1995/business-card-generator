/* THE KIT GALLERY — all 19 Beautiful UI primitives, verbatim.
 *
 * Reachable at /design-kit.php. This route imports every component exactly as
 * shipped, with its own demo data untouched, so the team has a live in-product
 * reference of the target look and every variant on real devices and in dark
 * mode. Nothing here is wired to Cardify data ON PURPOSE: 16 of the 19 take no
 * props, so "verbatim" and "real data" cannot both be true of the same file.
 *
 * The rewired, data-carrying forks live alongside this file. Change those, not
 * these. Keeping this gallery pristine is what lets us diff a fork against the
 * original when upstream ships a fix.
 */

import LoadingState from "@/beautiful-ui/components/LoadingState";
import ThinkingState from "@/beautiful-ui/components/ThinkingState";
import StreamingText from "@/beautiful-ui/components/StreamingText";
import ApprovalCard from "@/beautiful-ui/components/ApprovalCard";
import ToolChips from "@/beautiful-ui/components/ToolChips";
import TaskRows from "@/beautiful-ui/components/TaskRows";
import ChatComposer from "@/beautiful-ui/components/ChatComposer";
import PromptBar from "@/beautiful-ui/components/PromptBar";
import RecommendationCard from "@/beautiful-ui/components/RecommendationCard";
import ContextCards from "@/beautiful-ui/components/ContextCards";
import DiffTable from "@/beautiful-ui/components/DiffTable";
import RecordsTable from "@/beautiful-ui/components/RecordsTable";
import FilterTable from "@/beautiful-ui/components/FilterTable";
import SidebarNav from "@/beautiful-ui/components/SidebarNav";
import SearchList from "@/beautiful-ui/components/SearchList";
import InsightCards from "@/beautiful-ui/components/InsightCards";
import CodeBlock from "@/beautiful-ui/components/CodeBlock";
import FineTuneCard from "@/beautiful-ui/components/FineTuneCard";
import SelectionActions from "@/beautiful-ui/components/SelectionActions";

import type { ReactNode } from "react";

function Section({
  n,
  title,
  note,
  children,
}: {
  n: string;
  title: string;
  note: string;
  children: ReactNode;
}) {
  return (
    <section className="border-b border-dashed border-line py-8">
      <header className="mb-5 flex items-baseline gap-3">
        <span className="font-mono text-[12px] text-ink-3 tabular-nums">{n}</span>
        <h2 className="text-[15px] font-medium text-ink">{title}</h2>
        <p className="text-[12.5px] text-ink-2">{note}</p>
      </header>
      <div className="primitive-showcase flex flex-wrap items-start gap-6">
        {children}
      </div>
    </section>
  );
}

export default function Kit(_props: { props: Record<string, unknown> }) {
  return (
    <div className="mx-auto max-w-[960px] pb-24">
      <header className="py-10">
        <h1 className="text-2xl font-semibold text-ink">Beautiful UI kit</h1>
        <p className="mt-2 max-w-[52ch] text-[13px] leading-relaxed text-ink-2">
          All 19 primitives, verbatim, running inside Cardify. Demo data is the
          library's own. Toggle the OS between light and dark to check both token
          sets, and turn on Reduce Motion to check every animation degrades.
        </p>
      </header>

      <Section n="01" title="Loading State" note="Pixel-grid loader, shimmer label, live elapsed timer.">
        <LoadingState label="Churning" variant="Drive" />
        <LoadingState label="Settling up" variant="Dots" />
        <LoadingState label="Syncing" variant="Orbit" />
      </Section>

      <Section n="02" title="Thinking" note="Expandable reasoning trace, four modes.">
        <ThinkingState variant="Steps" />
        <ThinkingState variant="Reasoning" />
        <ThinkingState variant="Search" />
        <ThinkingState variant="Coding" />
      </Section>

      <Section n="03" title="Streaming Text" note="Streamed answer with sources, actions, follow-ups.">
        <StreamingText />
      </Section>

      <Section n="04" title="Approval Card" note="Human-in-the-loop question before the agent acts.">
        <ApprovalCard />
      </Section>

      <Section n="05" title="Tool Chips" note="Tool calls and code edits as compact chips.">
        <ToolChips />
      </Section>

      <Section n="06" title="Task Rows" note="Live task status: running, failed, completed.">
        <TaskRows variant="Capsules" />
        <TaskRows variant="List" />
      </Section>

      <Section n="07" title="Chat" note="Tabbed chat panel with reasoning replies.">
        <ChatComposer />
      </Section>

      <Section n="08" title="Prompt Bar" note="@ sources, / commands, model picker, dictation.">
        <PromptBar variant="Rounded" />
        <PromptBar variant="Pill" />
      </Section>

      <Section n="09" title="Recommendation Card" note="Suggestion with confidence meter and alternatives.">
        <RecommendationCard />
      </Section>

      <Section n="10" title="Context Cards" note="Retrieved chunks with their source documents.">
        <ContextCards />
      </Section>

      <Section n="11" title="Diff Table" note="Proposed edits sweeping through tabular data.">
        <DiffTable />
      </Section>

      <Section n="12" title="Records Table" note="Grid with tags, sorting, relationship strength.">
        <RecordsTable />
      </Section>

      <Section n="13" title="Filter Table" note="Status chips that reorganise live data.">
        <FilterTable />
      </Section>

      <Section n="14" title="Sidebar Nav" note="Workspace navigation with quick search.">
        <SidebarNav />
      </Section>

      <Section n="15" title="Search" note="Command search, live filtering, empty state.">
        <SearchList />
      </Section>

      <Section n="16" title="Insight Cards" note="Paged insights with scrub-ready live charts.">
        <InsightCards />
      </Section>

      <Section n="17" title="Code Block" note="Code streaming in line by line.">
        <CodeBlock />
      </Section>

      <Section n="18" title="Fine-tune Card" note="Design properties in an inspector.">
        <FineTuneCard />
      </Section>

      <Section n="19" title="Selection Actions" note="Highlight a passage, hand it off to rewrite.">
        <SelectionActions />
      </Section>
    </div>
  );
}
