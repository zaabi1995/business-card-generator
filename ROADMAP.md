# Roadmap

## Phase 1 — MVP (current)
- Single-tenant business card generator
- Visual template editor
- Employee management
- Instant PNG generation

## Phase 2 — Multi-tenant foundation
- Introduce companies (tenant context)
- Company registration + login
- Per-company isolation for employees/templates/generated cards
- Per-company storage layout under `uploads/companies/{company_id}/...`

## Phase 3 — Productization
- Bulk import (CSV/Excel)
- Template library (starter templates)
- Analytics: generation counts, active employees
- Better admin UX (audit log, changes history)

## Phase 4 — Monetization & scale
- Subscriptions + billing (Stripe)
- Plan limits (employees/templates/storage)
- White-label + custom domains
- SSO (SAML/OIDC) for enterprise


