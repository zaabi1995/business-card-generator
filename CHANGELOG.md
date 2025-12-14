# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2024-12

### Added
- Complete database integration (MySQL/PostgreSQL)
- DatabaseAdapter class for seamless DB/JSON switching
- 7-step installation wizard
- Billing integration structure (Amwal Pay)
- Subscription plans system
- Payment webhook handler
- Comprehensive documentation
- GitHub templates (issues, PRs)
- Security policy

### Changed
- All core functions now use DatabaseAdapter when database available
- Automatic fallback to JSON when database not configured
- Improved installation process
- Enhanced configuration system

### Fixed
- DatabaseAdapter integration in all functions
- Installation wizard includes
- Config constants definitions
- Admin account creation flow

### Security
- Password hashing (bcrypt)
- SQL injection prevention
- Input sanitization
- Company data isolation

## [1.0.0] - Initial Release

### Added
- Multi-tenant company system
- Visual template editor
- Employee management
- Card generation (PNG)
- Bilingual support (EN/AR)
- CSV/Excel import
- JSON-based storage

---

## [Unreleased]

### Planned
- CSRF protection
- Rate limiting
- Plan limit enforcement
- PostgreSQL schema variant
- Unit tests
- QR code generation
- PDF export
- API access

[2.0.0]: https://github.com/zaabi1995/business-card-generator/releases/tag/v2.0.0
[1.0.0]: https://github.com/zaabi1995/business-card-generator/releases/tag/v1.0.0
