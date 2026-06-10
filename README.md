# Business Card Generator 🎴

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20PostgreSQL-orange.svg)](docs/DOCUMENTATION.md)

> Professional PHP-based business card generator with visual template editor. Full multi-tenant SaaS platform where companies can manage employees, create branded templates, and generate downloadable business cards instantly.

## ✨ Features

- ✅ **Multi-Tenant Architecture** - Complete company isolation
- ✅ **Visual Template Editor** - Drag-and-drop template customization
- ✅ **Bilingual Support** - English & Arabic
- ✅ **Database Ready** - MySQL/PostgreSQL support with JSON fallback
- ✅ **Billing Integration** - Amwal Pay & Stripe ready
- ✅ **Subscription Plans** - Free, Pro, Enterprise tiers
- ✅ **Employee Management** - CSV/Excel import support
- ✅ **Card Generation** - PNG export with HTML2Canvas
- ✅ **Installation Wizard** - Complete 7-step setup process

## 📋 Requirements

- **PHP:** 7.4+ with PDO extension
- **Database:** MySQL 5.7+ / MariaDB 10.2+ OR PostgreSQL 10+
- **Web Server:** Apache/Nginx or PHP built-in server
- **Optional:** Composer (for Excel support via PhpSpreadsheet)

## 🚀 Quick Start

### Installation Wizard (Recommended)

1. **Clone the repository:**
   ```bash
   git clone https://github.com/zaabi1995/business-card-generator.git
   cd business-card-generator
   ```

2. **Set up database:**
   - Create a MySQL or PostgreSQL database
   - Note the database credentials

3. **Run the installation wizard:**
   - Navigate to `/install` in your browser
   - Follow the 7-step wizard:
     1. ✅ Requirements check
     2. ✅ Database configuration
     3. ✅ Database migration
     4. ✅ Site configuration
     5. ✅ Billing configuration (optional)
     6. ✅ Admin account creation
     7. ✅ Complete!

4. **Access your application:**
   - 🏠 Homepage: `http://your-domain/`
   - 🏢 Create company: `http://your-domain/company/register.php`
   - ⚙️ Admin panel: `http://your-domain/admin/`

### Manual Installation

See [DOCUMENTATION.md](docs/DOCUMENTATION.md#installation-guide) for detailed manual installation instructions.

## ⚙️ Configuration

See [DOCUMENTATION.md](docs/DOCUMENTATION.md#configuration) for complete configuration guide including:
- Database settings
- Billing integration (Amwal Pay/Stripe)
- Webhook setup
- Security configuration

## 📖 Usage

### For Companies

1. **Register:** Navigate to `/company/register.php`
2. **Login:** Navigate to `/company/login.php`
3. **Manage employees:** Navigate to `/admin/employees.php`
4. **Create templates:** Navigate to `/admin/index.php`
5. **Manage billing:** Navigate to `/admin/billing.php`

### For Employees

1. Go to homepage
2. Enter company code and email
3. Generate and download business card

📚 **Full usage guide:** See [DOCUMENTATION.md](docs/DOCUMENTATION.md#usage-guide)

## 💻 Development

### Local Development

```bash
# Start PHP built-in server
php -S 127.0.0.1:8000

# Or use your preferred web server
# Apache/Nginx configuration examples in docs/DOCUMENTATION.md
```

Then open `http://127.0.0.1:8000`

### Project Structure

```
├── admin/              # Admin panel
│   ├── index.php      # Template management
│   ├── employees.php  # Employee CRUD
│   ├── billing.php    # Billing management
│   └── ...
├── company/           # Company management
├── install/           # Installation wizard (7-step)
├── webhooks/          # Payment webhooks
├── database/          # Schema & migrations
├── includes/          # Core classes
│   ├── Database.php   # Database connection
│   ├── DatabaseAdapter.php  # DB/JSON adapter
│   ├── Billing.php    # Billing integration
│   └── functions.php  # Helper functions
└── config.example.php # Configuration template
```

📚 **Full project structure:** See [DOCUMENTATION.md](docs/DOCUMENTATION.md#file-structure-reference)

## 💳 Subscription Plans

| Plan | Employees | Templates | Storage | Price |
|------|-----------|-----------|---------|-------|
| **Free** | 10 | 2 | 100MB | $0/month |
| **Pro** | 100 | 10 | 1GB | $29.99/month |
| **Enterprise** | Unlimited | Unlimited | Unlimited | $99.99/month |

## 🔒 Security

- ✅ `config.php` contains sensitive data and is excluded from Git
- ✅ Password hashing (bcrypt)
- ✅ SQL injection prevention (PDO prepared statements)
- ✅ Input sanitization
- ⚠️ CSRF protection (recommended - see [DOCUMENTATION.md](docs/DOCUMENTATION.md#security))
- ⚠️ Rate limiting (recommended - see [DOCUMENTATION.md](docs/DOCUMENTATION.md#security))

**Security Policy:** See [SECURITY.md](SECURITY.md) for reporting vulnerabilities.

## 📚 Documentation

📖 **[Complete Documentation](docs/DOCUMENTATION.md)** - Comprehensive guide covering:
- 📋 Installation & Setup
- 🏗️ Architecture & Design  
- ⚙️ Configuration
- 📖 Usage Guide
- 🗄️ Database Structure
- 💳 Billing Integration
- 🔒 Security
- 🛠️ Development
- 🗺️ Roadmap
- 🐛 Troubleshooting

**Quick Links:**
- [Installation Guide](docs/DOCUMENTATION.md#installation-guide)
- [Configuration](docs/DOCUMENTATION.md#configuration)
- [Usage Guide](docs/DOCUMENTATION.md#usage-guide)
- [Troubleshooting](docs/DOCUMENTATION.md#troubleshooting)

**Integration Guides:**
- Payments: **Paymob Oman** is the current gateway (see `includes/Payment.php`). Amwal Pay is legacy, archived at [docs/archived/AMWAL_PAY_INTEGRATION.md](docs/archived/AMWAL_PAY_INTEGRATION.md).
- Accounting: **BHD-ERP** is current. Odoo is legacy, archived at [docs/archived/ODOO_INTEGRATION.md](docs/archived/ODOO_INTEGRATION.md).
- [WhatsApp Integration](docs/WHATSAPP_INTEGRATION.md)
- [Deployment Checklist](docs/DEPLOYMENT_CHECKLIST.md)

## 🤝 Contributing

Contributions are welcome! Please read our [Contributing Guide](CONTRIBUTING.md) for details on:
- Code of conduct
- Development setup
- Pull request process
- Coding standards

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🐛 Issues & Support

- **Bug Reports:** [Open an issue](https://github.com/zaabi1995/business-card-generator/issues/new?template=bug_report.md)
- **Feature Requests:** [Suggest a feature](https://github.com/zaabi1995/business-card-generator/issues/new?template=feature_request.md)
- **Questions:** Check [Documentation](docs/DOCUMENTATION.md) or open a discussion

## 🌟 Star History

If you find this project useful, please consider giving it a star ⭐

## 📊 Project Status

**Current Version:** 2.0  
**Status:** ✅ Production Ready (90%)  
**Last Updated:** December 2024

---

**Made with ❤️ for businesses who need professional business cards instantly**
