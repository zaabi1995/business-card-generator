# Architecture (Planned Multi-Tenant SaaS)

## Current MVP (single-tenant)
- Storage: JSON files in `data/`
- Uploads: `uploads/` (templates + generated cards)
- Auth: single admin password (`ADMIN_PASSWORD` in `config.php`)

## Target SaaS (multi-tenant)

### Tenancy model
- One platform, many companies
- Every record belongs to a `company_id`

### URL model (choose one)
- Subdomain: `companySlug.domain.com`
- Path: `domain.com/c/companySlug`

### Roles
- Platform super admin (support/ops)
- Company admin (manage employees/templates/settings)
- Employee (generate own card)

### Data model (database)
Tables (minimum):
- `companies`: id, name, slug, admin_email, password_hash, plan, created_at
- `employees`: id, company_id, email, profile fields, created_at
- `templates`: id, company_id, name, side, background_path, fields_json, is_active
- `generated_cards`: id, company_id, employee_id, front_path, back_path, generated_at

### Storage layout
Store files per company:
- `uploads/companies/{company_id}/templates/...`
- `uploads/companies/{company_id}/cards/...`

### Request flow (high level)
1. Resolve company context (subdomain/path → company)
2. For admin routes: require company admin session
3. For employee generation: find employee by email scoped to company
4. Render HTML card → generate PNG/PDF → return/download

### Security basics
- Passwords hashed (bcrypt/argon2)
- Company isolation on every query and file path
- Rate limit login and generate endpoints (later)


