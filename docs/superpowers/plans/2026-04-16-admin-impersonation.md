# Admin "Login as" Impersonation, Plan

**Date:** 2026-04-16
**Branch:** `infy-admin-impersonate`
**Estimate:** ~4 hours

## Problem
Super admins need to reproduce user issues and answer support questions from the user's perspective. Currently they must reset passwords to impersonate, destructive and traceable only to password changes.

## Solution
Session-swap impersonation. Super admin clicks "Login as" on a company row → their admin session is stashed, they become that company admin → sticky banner on every page → "Exit impersonation" restores admin session.

## What is a "user" in Cardify?
Cardify's primary tenants are **companies** (each has `admin_email` + `password_hash` in the `companies` table, or a linked row in `users` with `role IN ('company_admin','admin','company')`). For v1, we impersonate **company admins**, i.e. each row in `/admin/super/companies.php` gets a "Login as" action. Employee impersonation is out of scope for this PR.

## Files to touch

### New
- `admin/impersonate.php`, POST handler for start/stop
- `includes/Impersonation.php`, helper class (isImpersonating, banner, guards)

### Modified
- `admin/super/companies.php`, add "Login as" button to each row + JS confirm modal
- `includes/admin-layout.php`, inject banner at top of `<body>` when impersonating
- `includes/Auth.php`, `logout()` must clear impersonation stash so it never persists
- `logout.php`, same (belt + suspenders)

## Routes

### POST `admin/impersonate.php?action=start&company_id={id}`
1. `Auth::requireRole('super_admin')` (hard gate)
2. CSRF token check (use existing `$_SESSION['csrf_token']` pattern)
3. Load target company by id (must be `status='active'`)
4. Stash current session snapshot into `$_SESSION['impersonator']`:
   - `admin_id`, `admin_email`, `admin_name`, `admin_role` (super_admin)
   - `started_at` (ISO timestamp)
   - `audit_id` (UUID, so stop can link to same row)
5. Log to `audit_logs`: action=`impersonate_start`, entity_type=`company`, entity_id=company.id, after_data={admin_email, target_company, target_admin_email}
6. Overwrite session: set `user_id='company_'.$company['id']`, `user_role='company_admin'`, `company_id`, `company_slug`, `company_name`, `user_email=admin_email`, `user_name=company.name`
7. `session_regenerate_id(true)` so old session id isn't reusable
8. Redirect to `{slug}/admin/` (company admin dashboard)

### POST `admin/impersonate.php?action=stop`
1. Require `$_SESSION['impersonator']` set (else redirect to admin)
2. CSRF check
3. Log `impersonate_stop` with same audit_id/started_at/stopped_at
4. Restore session from stash (rehydrate super_admin user from `users` table by admin_id)
5. `session_regenerate_id(true)`
6. `unset($_SESSION['impersonator'])`
7. Redirect to `admin/super/companies.php`

## Session shape while impersonating
```php
$_SESSION['user_id'] = 'company_{id}'       // target
$_SESSION['user_role'] = 'company_admin'    // target
$_SESSION['company_id'] = {target_id}
$_SESSION['company_slug'] = {target_slug}
$_SESSION['impersonator'] = [
    'admin_id' => '...',
    'admin_email' => '...',
    'admin_name' => '...',
    'started_at' => '2026-04-16T...',
    'audit_id' => 'uuid',
    'target_company_id' => '...',
    'target_company_name' => '...',
]
```

## Banner (injected by `adminHeader()` before main content)
```html
<div class="fixed top-0 left-0 right-0 z-50 bg-amber-400 text-amber-900 border-b-2 border-amber-600 shadow-lg">
  <div class="px-4 py-2 flex items-center justify-between gap-4 text-sm font-semibold">
    <div>
      <i class="fa-solid fa-user-secret mr-2"></i>
      You are logged in as <strong>{target_company_name}</strong>
      (admin: {admin_email}), started {relative_time}
    </div>
    <form method="POST" action="{basePath}admin/impersonate.php?action=stop" class="m-0">
      <input type="hidden" name="csrf_token" value="...">
      <button type="submit" class="bg-amber-900 text-white px-3 py-1 rounded font-bold hover:bg-black">
        Exit impersonation
      </button>
    </form>
  </div>
</div>
```
Add `body { padding-top: 40px !important; }` when impersonating so banner doesn't overlap existing fixed navbar. Banner sits ABOVE the existing navbar (z-50 vs navbar z-30).

## Security

1. **Admin-only start**: only `super_admin` can POST start. Explicit `Auth::requireRole('super_admin')` at top of handler BEFORE any DB work.
2. **CSRF**: both start and stop require valid `csrf_token`. Existing `functions.php` already provides `getCsrfToken()` / `validateCsrfToken()` helpers.
3. **Audit log**: every start and stop writes a row with admin_id, target_company_id, ip, user_agent (AuditLog already captures these).
4. **Logout clears stash**: modify `Auth::logout()` to call `session_unset()`, already does. Also explicitly `unset($_SESSION['impersonator'])` before destroy. If someone logs out while impersonating, they should come back as themselves, actually simpler: just destroy everything, make them log in fresh. Document this.
5. **Nested impersonation blocked**: if `$_SESSION['impersonator']` is already set, the start handler refuses (prevents admin-A impersonates admin-B impersonates C).
6. **Sensitive actions warning**: for v1, we add a CSS class `data-impersonating="true"` on `<body>` when active. Defer actually disabling password-change forms to a follow-up, out of scope for 4h estimate but documented in PR.
7. **Session fixation**: `session_regenerate_id(true)` on both start and stop.

## Audit log entries
Uses existing `audit_logs` table. Two actions:
- `impersonate_start`, entity_type=`company`, entity_id=target company id, after_data={admin_id, admin_email, target_company_id, target_admin_email, audit_id}
- `impersonate_stop`, entity_type=`company`, entity_id=target company id, before_data={audit_id, started_at}, after_data={stopped_at, duration_seconds}

Queryable via existing `/admin/audit-logs.php` with `action=impersonate_start,impersonate_stop`.

## UI change on companies.php
Add a button between "Visit Portal" and "Delete":
```html
<form method="POST" action="{basePath}admin/impersonate.php?action=start&company_id={id}" 
      onsubmit="return confirm('Log in as {name}? You will be able to see and act as this company admin. All actions are logged.');"
      class="inline m-0">
  <input type="hidden" name="csrf_token" value="{csrf}">
  <button type="submit" class="text-amber-600 hover:text-amber-800" title="Login as this company">
    <i class="fa-solid fa-user-secret"></i>
  </button>
</form>
```

## Testing (manual, since no PHP test runner in repo)
1. `php -l` every touched file
2. Log in as super admin → see "Login as" on each row
3. Click Login as → confirm → land on company dashboard with banner
4. Navigate around, banner persists on every admin page
5. Click Exit → returned to `/admin/super/companies.php` as super_admin, banner gone
6. `SELECT * FROM audit_logs WHERE action LIKE 'impersonate%' ORDER BY created_at DESC LIMIT 4`, 2 rows visible
7. Log out while impersonating → log back in → not impersonating (stash cleared)
8. Non-super-admin POST to `impersonate.php` → 403/redirect
9. Missing CSRF token → rejected

## Deploy
Per user rules: commit, push, open PR. **Do NOT merge, do NOT deploy.**
