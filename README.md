# Business Card Generator (Multi-Company)

PHP-based business card generator with a visual template editor. Companies can manage employee data and generate branded business cards instantly.

## Requirements

- PHP 7.4+

## Run locally

From the project folder:

```bash
php -S 127.0.0.1:8000
```

Then open `http://127.0.0.1:8000`.

## Admin

- Admin panel: `/admin`
- Admin password is configured in `config.php` (do not commit this file)

## Notes

- This repo is structured for future multi-tenant SaaS (per-company login, isolated data, templates, and generation history).


