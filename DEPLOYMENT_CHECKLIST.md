# Production Deployment Checklist for bc.bhd.om

This checklist ensures your Business Card Generator SaaS is ready for production deployment.

## Pre-Deployment

### 1. Domain & DNS Setup
- [ ] Configure DNS A record for `bc.bhd.om` pointing to your server IP
- [ ] Verify DNS propagation: `nslookup bc.bhd.om` or `dig bc.bhd.om`
- [ ] Wait for DNS propagation (can take up to 48 hours)

### 2. SSL Certificate
- [ ] Install SSL certificate (Let's Encrypt recommended)
- [ ] Configure automatic renewal
- [ ] Test SSL: `https://bc.bhd.om` should load with valid certificate
- [ ] Verify HTTPS redirect works

### 3. Server Requirements
- [ ] PHP 7.4+ installed and configured
- [ ] PDO extension enabled (`php -m | grep pdo`)
- [ ] MySQL/MariaDB or PostgreSQL installed
- [ ] Apache/Nginx configured
- [ ] Required PHP extensions: `pdo`, `pdo_mysql` (or `pdo_pgsql`), `json`, `gd`, `curl`

### 4. File Permissions
```bash
# Set proper permissions
chmod 755 /path/to/bc
chmod 644 /path/to/bc/*.php
chmod 755 /path/to/bc/data
chmod 755 /path/to/bc/uploads
chmod 755 /path/to/bc/logs
chmod 600 /path/to/bc/config.php  # Important: protect config.php
```

### 5. Database Setup
- [ ] Create database: `business_cards` (or your preferred name)
- [ ] Create database user with proper permissions
- [ ] Test database connection
- [ ] Run installation wizard or manual setup

## Installation

### Option A: Installation Wizard (Recommended)
- [ ] Navigate to `https://bc.bhd.om/install/`
- [ ] Complete all 7 steps:
  1. Requirements check
  2. Database configuration
  3. Database migration
  4. Site configuration
  5. Billing configuration (Amwal Pay)
  6. Admin account creation
  7. Finalization
- [ ] Verify `config.php` was created
- [ ] Verify directories were created

### Option B: Manual Installation
- [ ] Copy `config.example.php` to `config.php`
- [ ] Update database credentials in `config.php`
- [ ] Run database schema: `mysql -u user -p database < database/schema.sql`
- [ ] Set installation complete: `UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'installation_complete';`

## Configuration

### 1. Production Settings in `config.php`
```php
// Verify these are set correctly:
- DB_HOST, DB_NAME, DB_USER, DB_PASS
- SITE_NAME, SITE_DESCRIPTION
- Error reporting disabled (display_errors = 0)
- Error logging enabled
```

### 2. Amwal Pay Configuration
- [ ] Get credentials from Amwal Pay dashboard:
  - Merchant ID
  - Terminal ID
  - Secure Key
- [ ] Add to `config.php`:
```php
define('AMWAL_MERCHANT_ID', 'your_merchant_id');
define('AMWAL_TERMINAL_ID', 'your_terminal_id');
define('AMWAL_SECURE_KEY', 'your_secure_key');
```
- [ ] Set callback URL in Amwal Pay dashboard:
  `https://bc.bhd.om/amwalpay/callback.php`

### 3. .htaccess Configuration
- [ ] Verify `.htaccess` file exists
- [ ] Uncomment HTTPS redirect (after SSL is configured)
- [ ] Test that protected files are inaccessible

## Security Checklist

### 1. File Security
- [ ] `config.php` is not accessible via web (test: `https://bc.bhd.om/config.php` should return 403)
- [ ] `.htaccess` is protecting sensitive files
- [ ] `data/` directory is not web-accessible
- [ ] `logs/` directory is not web-accessible

### 2. Database Security
- [ ] Database user has minimal required permissions
- [ ] Database password is strong
- [ ] Database is not accessible from outside (firewall)

### 3. Application Security
- [ ] Change default admin password
- [ ] Verify password hashing is working (bcrypt)
- [ ] Test SQL injection protection
- [ ] Verify input sanitization

### 4. Server Security
- [ ] Firewall configured (only allow 80, 443, SSH)
- [ ] SSH key authentication (disable password auth)
- [ ] Regular security updates enabled
- [ ] Fail2ban or similar intrusion prevention

## Testing

### 1. Basic Functionality
- [ ] Homepage loads: `https://bc.bhd.om/`
- [ ] Company registration works: `https://bc.bhd.om/company/register.php`
- [ ] Company login works: `https://bc.bhd.om/company/login.php`
- [ ] Admin panel accessible: `https://bc.bhd.om/admin/`
- [ ] Employee management works
- [ ] Template editor works
- [ ] Card generation works
- [ ] Card download works

### 2. Payment Integration
- [ ] Billing page loads: `https://bc.bhd.om/admin/billing.php`
- [ ] Subscription plans display correctly
- [ ] Payment process redirects to Amwal Pay
- [ ] Callback URL receives payment status
- [ ] Subscription activates after payment

### 3. Multi-Tenant Features
- [ ] Multiple companies can register
- [ ] Company data is isolated
- [ ] Company-specific templates work
- [ ] Company-specific employees work

### 4. Error Handling
- [ ] 404 errors handled gracefully
- [ ] Database errors don't expose sensitive info
- [ ] Error logs are being written
- [ ] No PHP errors displayed to users

## Performance

### 1. Caching
- [ ] Browser caching enabled (check `.htaccess`)
- [ ] Gzip compression enabled
- [ ] Static assets cached properly

### 2. Database
- [ ] Database indexes are created (check `schema.sql`)
- [ ] Query performance is acceptable
- [ ] No slow queries in logs

### 3. File Uploads
- [ ] Upload limits configured (PHP and server)
- [ ] Large file uploads work
- [ ] File storage is efficient

## Monitoring

### 1. Logs
- [ ] Error logs directory exists: `logs/`
- [ ] PHP errors are being logged
- [ ] Application errors are being logged
- [ ] Log rotation configured

### 2. Monitoring Setup
- [ ] Server monitoring (CPU, RAM, disk)
- [ ] Database monitoring
- [ ] Uptime monitoring
- [ ] Error alerting configured

### 3. Backups
- [ ] Database backup strategy in place
- [ ] File backup strategy in place
- [ ] Backup restoration tested
- [ ] Automated backups configured

## Post-Deployment

### 1. Initial Setup
- [ ] Create first admin company account
- [ ] Add test employees
- [ ] Create test templates
- [ ] Generate test business cards

### 2. Payment Testing
- [ ] Test payment flow with sandbox credentials
- [ ] Verify subscription activation
- [ ] Test plan limits enforcement
- [ ] Switch to production credentials

### 3. Documentation
- [ ] Update any hardcoded URLs in documentation
- [ ] Document any custom configurations
- [ ] Create admin user guide
- [ ] Document backup/restore procedures

## Go-Live Checklist

- [ ] All tests pass
- [ ] SSL certificate valid
- [ ] DNS configured correctly
- [ ] Database backed up
- [ ] Error logging working
- [ ] Monitoring active
- [ ] Backup system tested
- [ ] Team notified of go-live
- [ ] Support channels ready

## Post-Launch Monitoring

### First 24 Hours
- [ ] Monitor error logs every hour
- [ ] Check server resources
- [ ] Monitor payment transactions
- [ ] Check user registrations
- [ ] Verify email notifications (if implemented)

### First Week
- [ ] Review error logs daily
- [ ] Monitor performance metrics
- [ ] Check user feedback
- [ ] Verify backups are running
- [ ] Review security logs

## Troubleshooting

### Common Issues

**Issue: 500 Internal Server Error**
- Check PHP error logs
- Verify file permissions
- Check `.htaccess` syntax
- Verify database connection

**Issue: Database Connection Failed**
- Verify credentials in `config.php`
- Check database server is running
- Verify firewall allows connections
- Check database user permissions

**Issue: Payment Callback Not Working**
- Verify callback URL in Amwal Pay dashboard
- Check server logs for callback attempts
- Verify HTTPS is working
- Test callback URL manually

**Issue: File Uploads Not Working**
- Check PHP `upload_max_filesize`
- Verify directory permissions
- Check disk space
- Review PHP error logs

## Support Resources

- **Documentation**: See `DOCUMENTATION.md`
- **Amwal Pay Integration**: See `AMWAL_PAY_INTEGRATION.md`
- **GitHub Issues**: https://github.com/zaabi1995/business-card-generator/issues
- **Server Logs**: `logs/php-errors.log`

---

**Last Updated**: December 2024  
**Domain**: bc.bhd.om
