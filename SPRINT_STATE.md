# Cardify v2.0 Sprint — Final State

**Walltime:** 2026-04-20 → 2026-04-23 (121 iterations)
**Release:** v2.0, live at https://cardify.om, HEAD `5bd251d`
**Last deploy:** iter 120 close-out, all 5 smoke URLs green
**Last test run:** 138 passed / 2 skipped / 0 failed (2.7 min, chromium)

## Action counts (as of iter 121)

| State | Count | Meaning |
|---|---|---|
| `[x]` done | 297 | Shipped, deployed, verified with SHA pointer |
| `[~]` partial / blocked | 181 | Useful artefact committed + explicit blocker + queued follow-up |
| `[ ]` open | 331 | Deferred to next cycle; 269 of these are Cat B bulk-i18n Phase 2 (the plan always intended this as a separate pass), 62 are operationally-triaged follow-ups in Appended Actions 780-842 |

## Why the loop stops here

The iteration-119 self-review declared "no new actions discovered"
and iter-120 closed the sprint with a retro written to
`~/obsidian/claude-vault/cardify-sprint-retro.md`. The remaining
`[ ]` rows fall into two clean buckets:

**Cat B bulk i18n (actions 519-790).** The per-admin-page string
translation that the plan always carved out as Phase 2. Scope is
~3,000 strings across 30+ admin pages. Mechanical auto-translation
would violate the voice rule in memory
`feedback_proposal_voice_plain_english.md`; a human Arabic reviewer
needs to pass once, and Ali has indicated he'd rather chunk this
over time with a real translator. Action 519 explicitly calls this
out ("Requires a qualified Arabic business writer"). No iteration of
the autonomous loop will satisfy this without betraying the rule.

**Operational follow-ups (actions 780-842).** Triaged in iter 118
into 8 themed buckets; 3 need Ali's approval (launch blasts), 8 need
one-time ops config (Sentry, Uptime Robot, Cloudflare DNS, B2
bucket), 8 are stage-dependent E2E happy paths, rest are polish.
All have explicit blockers or require Ali's green light.

## What to run if you want more

- **Continue Phase 2 i18n**: pick a specific admin page, engage an
  Arabic translator, paste both EN + AR in one commit. Don't auto-
  translate.
- **Unblock the follow-ups**: work through
  `cardify.om/ops/backlog-triage.md` in dependency order
  (disk prune → GRANT → Sentry DSN → Uptime Robot → DNS →
  TEST_OTP → 8 stage E2E tests).

## Where to look

| File | Purpose |
|---|---|
| `SPRINT_ACTIONS.md` | Full action list with state |
| `SPRINT_LOG.md` | 120-entry engineering journal, one-liner + SHA per iter |
| `RELEASE_NOTES_v2.0.md` | Sprint highlights + migrations + ops |
| `ops/backlog-triage.md` | Grouped, dependency-ordered follow-ups |
| `ops/runbook.md` | Incident playbook |
| `ops/launch-{posts,email,whatsapp}.md` | Ali-review-blocked comms drafts |
| `~/obsidian/claude-vault/cardify-sprint-retro.md` | Retro (outside repo) |

## Live systems snapshot

```
https://cardify.om/              200  live
https://cardify.om/api/health    200  status=up, all checks green
https://cardify.om/api/erp-health 200  status=ok
https://cardify.om/status         200  all 5 components green
https://cardify.om/changelog      200  4 seed entries EN + AR
```

Cron on the VPS: 9 active Cardify schedules (deploy/rollback hooks
instant, backup-db 02:25, backup-storage 02:35, backup-restore-test
Sun 03:45, erp-retry */1, erp-reconcile 2nd 06:30, payment-retry
hourly :15, disk-alert */30, slow-query Mon 07:15).

Migrations applied: 077-096 (20 total).

E2E suite: 16 specs at `tests/e2e/*.spec.ts`, 140 tests total, default
chromium, opt-in Safari iOS + Chrome Android.
