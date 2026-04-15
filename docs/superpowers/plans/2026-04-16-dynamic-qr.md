# Plan: Dynamic QR — editable destination post-print

**Date:** 2026-04-16
**Author:** dynamic-qr agent
**Status:** Implementing

## Problem
Cardify today prints QR codes encoding `/qr.php?i={employee_id}`. The destination is **hard-wired**: qr.php always serves the VCF contact. If an employee changes roles, leaves, or the company wants to redirect the QR to a landing page, **the physical card must be reprinted**.

## Today (verified)
- `qr.php` resolves employee by `id` and streams VCF only (no redirect support).
- No `qr_redirects` / `short_code` table exists.
- No UI to edit the destination.
- QR tracker (`QRTracker::logScan`) already logs scans to `qr_scans` and aggregates in `qr_scan_daily_stats` — **keep as-is**.

## Solution (minimal, backward-compat)
Add a single optional column `qr_redirect_url` on `employees`. When set, `qr.php?i={id}` logs the scan then `302`-redirects to that URL instead of streaming VCF. Company admins toggle + edit the URL from the existing `admin/employees.php` edit modal.

**No new table.** QR code on printed card never changes (still `/qr.php?i={id}`). Owner flips destination anytime from dashboard.

## Changes
1. **Migration** `043_employee_qr_redirect.php` — `ALTER TABLE employees ADD COLUMN qr_redirect_url VARCHAR(1024) NULL` (idempotent).
2. **qr.php** — if `$employee['qr_redirect_url']` set + valid http(s) URL: log scan, then `header('Location: …', 302)`; else serve VCF (existing behavior).
3. **DatabaseAdapter::addEmployee / updateEmployee** — accept `qr_redirect_url` field (trim, sanitize, validate).
4. **admin/employees.php** — add Dynamic QR section to edit modal: toggle + URL input + copy-tracker-URL button.

## Out of scope
- No new analytics table (existing `qr_scans` already stores landing_url).
- No separate short-code table — employee id is already the short code.
- No Fabric.js / Paymob changes.
