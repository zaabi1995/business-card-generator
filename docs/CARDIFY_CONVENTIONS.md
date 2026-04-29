# Cardify Tenant URL Conventions

These rules apply to every Cardify tenant, no exceptions, no per-company opt-out. They keep URLs predictable for QR generation, vCard download, email signatures, and recipient muscle memory.

## 1. Company subdomain = email-domain local-part

When a new company is created, Cardify auto-derives the subdomain from the admin's email address:

```
admin@alali.om                 → alali.cardify.om
admin@otech.om                 → otech.cardify.om
support@oman-data-park.com     → omandatapark.cardify.om
```

The derivation function `CardifyConvention::companySlugFromEmail()` strips:
- everything before `@`
- the TLD and any subdomain (only the first dot-separated component is kept)
- hyphens, underscores, dots, and any non-`a-z0-9` characters

If the resulting slug collides with an existing company, the helper appends `2`, `3`, ... until it lands on a free slug. Reserved subdomains (`admin`, `mail`, `api`, `www`, etc.) are also avoided automatically by appending `co`.

The super-admin can override the auto-derived slug by typing one explicitly in the Create Company form. If left blank, the convention runs.

## 2. Employee URL = email local-part

Every employee gets a clean URL built from the local-part of their email:

```
ali.alzaabi@alali.om           → alali.cardify.om/ali.alzaabi
sara.alqasimi@otech.om         → otech.cardify.om/sara.alqasimi
ahmed_balushi@otech.om         → otech.cardify.om/ahmed.balushi
First.Last+tag@otech.om        → otech.cardify.om/first.last.tag
```

The derivation function `CardifyConvention::normalizeEmployeeId()` lowercases the local-part, replaces anything outside `[a-z0-9.]` with a dot, and collapses consecutive dots. Collisions inside the same company get `2`, `3`, ... appended.

This applies to:
- The admin "Add Employee" form (`DatabaseAdapter::addEmployee`)
- CSV/Excel bulk imports (`OnboardingImport`)
- Self-service portal request approvals (also goes through `addEmployee`)
- Any future entry point that uses `addEmployee()` (helper at `includes/functions.php`)

## 3. Tenant root URL = employee request portal

`<slug>.cardify.om/` always serves the employee Self-Service Hub form (portal.php). No login required to land there. Employees fill in their card details, submit, and HR approves.

```
otech.cardify.om/              → portal.php (employee form)
otech.cardify.om/login         → tenant_login.php (admin OTP sign-in)
otech.cardify.om/admin/        → admin dashboard (post-login)
otech.cardify.om/portal        → portal.php (explicit, same as /)
otech.cardify.om/<id>          → digital_card.php (printed-card target)
otech.cardify.om/card/<id>     → digital_card.php (legacy URL pattern)
```

The routing decision lives in `index.php` (the tenant-host check) and the nginx rewrite rules at `/www/server/panel/vhost/rewrite/cardify.om.conf`.

## 4. Why this matters

- **QR generation is trivial**: build URL = `https://<slug>.cardify.om/<email-local-part>` from the two columns we already have on the employee row.
- **vCard `URL:` field is canonical**: every recipient who scans the QR sees the same URL pattern.
- **Email signatures generate themselves**: `Yours, Sara Al Qasimi · otech.cardify.om/sara.alqasimi`.
- **Recipients can dictate the URL**: short, dot-separated, no random hashes.
- **No naming collisions across tenants**: the subdomain isolates each company's namespace.
- **Reprints, role changes, leaver flows all keep the same URL**: only the data behind it changes.

## 5. The helper library

All four rules live in a single class so the policy is unambiguous and centrally testable:

`includes/CardifyConvention.php`

```php
CardifyConvention::companySlugFromEmail($email, $db, $excludeCompanyId = null);
CardifyConvention::employeeIdFromEmail($email, $companyId, $db, $excludeEmployeeId = null);
CardifyConvention::normalizeSlug($raw);
CardifyConvention::normalizeEmployeeId($email);
CardifyConvention::tenantUrl($slug, $employeeId = '');
CardifyConvention::reservedSlugs();
```

Verified test cases (28 Apr 2026):

| Input | Output |
|---|---|
| `companySlugFromEmail('admin@alali.om')` | `alali` |
| `companySlugFromEmail('support@oman-data-park.com')` | `omandatapark` |
| `companySlugFromEmail('admin@otech.om')` (collision) | `otech2` |
| `normalizeEmployeeId('ali.alzaabi@alali.om')` | `ali.alzaabi` |
| `normalizeEmployeeId('Ahmed.Al-Balushi')` | `ahmed.al.balushi` |
| `normalizeEmployeeId('first.last+tag@otech.om')` | `first.last.tag` |
| `tenantUrl('alali', 'ali.alzaabi')` | `https://alali.cardify.om/ali.alzaabi` |
