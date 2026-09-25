# Oman Business Directory

Company-level directory. Not a personal-data product.

## What is stored today

`om_companies` is the public index (one row per slug). Migration 170 adds:

- normalized names, completeness, verification confidence
- optional `cr_number` (empty until an official value is supplied)
- `om_company_facts` for field provenance
- `om_company_matches` for human review
- `om_directory_exports` for reproducible commercial snapshots
- `om_directory_sources` for the source hierarchy

A weaker source cannot overwrite a stronger one. `inferred` never replaces `official_registry`.

Matches are queued. They are not merged. Accepting a pair in `/admin/directory-quality.php` only records the decision.

## ClearTax offer

FU-884 quoted OMR 500.000 for the field set:

`name_en`, `name_ar`, `sector`, `wilayat`, `size_bucket`, `website`, `summary_en`

`php scripts/om-directory.php export-cleartax` writes that snapshot and its SHA-256. It does not email the customer.

## Official sources checked on 25 Sep 2026

| Source | Result |
|---|---|
| business.gov.om | `robots.txt` is `User-agent: *` / `Disallow: /`. Homepage is a redirect shell. No public API was found. Not crawled. |
| tejarah.gov.om | Public ministry site. No company-registry dump or documented lookup API on the public pages. Not used as a registry. |
| Cardify `om_companies` | Usable as the existing company-level index. It is not an official commercial register. |

CR coverage stays 0 until a permitted official feed exists. Unknown stays unknown.

## Jobs

```bash
php scripts/om-directory.php score
php scripts/om-directory.php queue-matches
php scripts/om-directory.php metrics
php scripts/om-directory.php export-cleartax
```

Score runs in batches of 200. It only normalizes and scores columns already on the row.

## Public directory

`/companies` search matches English name, Arabic name, English summary, Arabic summary, and an exact CR when one exists. Internal scores are not shown. A CR line appears on a company page only when `cr_number` is set.
