# Cardify Appointment Booking, Plan

**Date:** 2026-04-16  
**Branch:** `infy-appointments`  
**Migration:** 046

## Goal
Port Infy vCard's "Appointment Booking" to Cardify. Visitors of a digital card can pick a time slot to meet the card owner. Owner gets emailed; slot becomes unavailable for others.

## Decisions
- **Clean-room.** Read Infy schema/UI for inspiration; write fresh code in Cardify's plain-PHP/PDO/Tailwind style.
- **Mirror `CardSections` pattern exactly**, single master row per employee + child `appointments` table; same admin form on `company/views/employee.php`; same public widget rendered conditionally below sections in `digital_card.php`.
- **No new include class needed**, small enough to live in API endpoints + a thin helper. (Decided against `Appointments.php` helper class to keep PR small; revisit if logic grows.)
- **Slot generation**: server-computes `[start, end)` slots from `available_start` → `available_end` in `duration_minutes` increments + `buffer_minutes` gap. Excludes any slot that overlaps an existing non-cancelled appointment.
- **Status flow**: `pending` (just booked) → owner confirms via admin UI → `confirmed`. Visitor doesn't need an account.
- **Tenant scoping**: company_admin sees only `appointments WHERE employee_id IN (SELECT id FROM employees WHERE company_id = :cid)`.
- **Email**: re-use `Mailer::send()` like `api/lead.php` does.

## DB (migration 046)
- `employee_appointment_settings` (employee_id PK)
- `appointments` (id, employee_id, slot_start, slot_end, status, visitor_*...)
- `.sql` sibling for direct mysql import.

## Routes
- `GET  /api/appointment/slots.php?eid=&date=` → JSON `{ slots: [{start,end,label}] }`
- `POST /api/appointment/book.php` → JSON `{success, id}`; emails owner
- `GET  /admin/appointments.php` → list/filter dashboard
- `POST /admin/appointments.php` action=update_status (CSRF)
- New section in `company/views/employee.php` for the 8 settings.

## Widget
- Render in `digital_card.php` after sections, when `enabled=1`.
- Date picker `<input type="date">` (today → today + max_advance_days)
- Slot grid (fetched on date change)
- Form: name/email/phone/notes + honeypot
- Vanilla fetch JS

## Deploy
1. Migration via direct mysql on VPS
2. Merge PR → `ssh root@... git pull origin main`
3. Smoke test 3 endpoints

## Out of scope (future)
- Owner reminders via email/WhatsApp
- Calendar (.ics) attachment
- Recurring availability overrides per date
- Visitor cancel link
