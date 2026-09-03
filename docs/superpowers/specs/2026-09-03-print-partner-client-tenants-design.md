# Print partner client tenants

Printers worldwide operate a client company the same way a large Cardify client does. This is not a new product brand.

## What already existed

- `printshop/register.php` and shop accounts (`users.role = print_shop`)
- Marketplace listing at `/print-shops` (BHD Printing, Muscat)
- Shop settings and `printshop/client-pricing.php` for shop-set prices
- BHD-only `is_internal_provider` browse of every company
- Company admin: employees, CSV import, locked template, bulk generate

## Gap

- `/print-shops/register` and `/partners` 404 (nginx has no rewrite; catch-all is one segment)
- Regular shops cannot create or open a company tenant
- `company_admin.php`, `requireAdmin()`, and `adminHeader()` bounce `print_shop`

## Approach

Extend the shop role. Do not invent a second SaaS.

1. Public signup: `/print-shops/register` and `/partners` serve the existing register form.
2. `print_shop_companies` attaches a shop to the client companies it created.
3. Regular shops see only attached clients. BHD internal provider still lists every company.
4. "Manage cards" opens the existing company admin. No parallel editor.
5. Shops keep setting their own print prices. No Cardify commission. BHD Oman list prices unchanged.

## Auth

A print partner reaches `/{slug}/admin/*` only when `PrintShopClients::canAccessCompanyAdmin` is true (attached, or internal provider). Other tenants stay closed.
