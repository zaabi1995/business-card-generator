# Security Policy

## Supported Versions

We provide security updates for the following versions:

| Version | Supported          |
| ------- | ------------------ |
| 2.0.x   | :white_check_mark: |
| < 2.0   | :x:                |

## Reporting a Vulnerability

If you discover a security vulnerability, please **do not** open a public issue. Instead, please follow these steps:

1. **Email us directly** at: [security@yourdomain.com] (replace with your security email)
2. **Include details** about the vulnerability:
   - Description of the issue
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

3. **We will respond** within 48 hours to acknowledge receipt
4. **We will provide updates** on the status of the vulnerability
5. **We will notify you** when the vulnerability is fixed

## Security Best Practices

### For Users

- Always use HTTPS in production
- Keep PHP and database software updated
- Use strong passwords for admin accounts
- Regularly backup your database
- Review file permissions
- Keep dependencies updated

### For Developers

- Never commit `config.php` to version control
- Use environment variables for sensitive data
- Implement CSRF protection
- Use prepared statements for database queries
- Validate and sanitize all user input
- Implement rate limiting
- Use secure session configuration

## Known Security Considerations

The following security features are recommended but not yet fully implemented:

- ⚠️ CSRF protection (recommended before production)
- ⚠️ Rate limiting (recommended for production)
- ⚠️ Enhanced session security (configure secure cookies)

See [DOCUMENTATION.md](DOCUMENTATION.md) for security recommendations.

## Security Updates

Security updates will be released as:
- **Patch versions** (2.0.x) for critical security fixes
- **Minor versions** (2.x.0) for security enhancements
- **Major versions** (x.0.0) for breaking security changes

---

**Thank you for helping keep Business Card Generator secure!**
