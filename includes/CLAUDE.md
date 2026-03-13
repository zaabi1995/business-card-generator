# Includes — Core PHP Classes

## Architecture
No framework — plain PHP classes loaded via `require_once`. Each file is a self-contained class or helper.

## Critical Files

### Payment.php — Payment Processing
- Abstracts Paymob payment flow
- HMAC verification for webhook callbacks
- Item quantity must be 1 with line total (not unit × qty)
- Test webhook locally before deploying

### Billing.php — Subscription & Billing
- Manages company subscriptions and print order billing
- Integrates with Payment.php for gateway operations

### CreditManager.php — Credit Accounts
- Print shop credit account system
- Ledger tracking, balance management
- Changes affect financial records — be precise

### Auth.php — Authentication
- Session-based auth for companies, admins, print shops, super admins
- Multiple auth contexts (company vs printshop vs super)
- Don't modify without understanding all login flows

### Database.php / DatabaseAdapter.php
- Database.php: Direct MySQL PDO connection
- DatabaseAdapter.php: Fallback adapter (DB or JSON file storage)
- Config comes from `config.php` (never committed to git)

### PrintShop.php / PrintShopBilling.php / PrintShopIntegration.php
- Multi-tenant print shop system
- PrintShopOdoo.php: Odoo integration for print shops
- These are tightly coupled — changes to one may require changes to others

### WhatsApp.php
- Sends via Dardasha API
- Check payment_settings WA toggles FIRST when notifications stop working

## Patterns
- No autoloader — manual `require_once` in each file
- Config via `config.php` (DB creds, API keys, HMAC secrets)
- Error handling: exceptions for critical paths, silent failures for notifications
- Currency: OMR with 3 decimal places via Currency.php
