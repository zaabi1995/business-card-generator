# Amwal Pay Integration Guide

This document explains how to integrate and use Amwal Pay payment gateway in the Business Card Generator SaaS platform.

## Overview

Amwal Pay integration follows the official Amwal Pay API structure, similar to their Laravel package. The integration uses:
- **Merchant ID** - Your merchant identifier
- **Terminal ID** - Your terminal identifier  
- **Secure Key** - Secret key for signature generation and verification
- **API URL** - `https://backend.sa.amwal.tech` (default)

## Setup Instructions

### Step 1: Get Amwal Pay Credentials

1. Sign up at [Amwal Pay](https://amwal.tech)
2. Contact their sales team to get your account activated
3. Once your contract is signed, you'll receive:
   - **Merchant ID**
   - **Terminal ID**
   - **Secure Key**

### Step 2: Configure in Application

#### Option A: During Installation Wizard

1. Run the installation wizard (`/install`)
2. In the **Billing Configuration** step:
   - Select "Amwal Pay" as payment gateway
   - Enter your **Merchant ID**
   - Enter your **Terminal ID**
   - Enter your **Secure Key**
   - Verify API URL is `https://backend.sa.amwal.tech`

#### Option B: Manual Configuration

1. Edit `config.php`:
```php
define('BILLING_GATEWAY', 'amwal');
define('AMWAL_MERCHANT_ID', 'your_merchant_id');
define('AMWAL_TERMINAL_ID', 'your_terminal_id');
define('AMWAL_SECURE_KEY', 'your_secure_key');
define('AMWAL_API_URL', 'https://backend.sa.amwal.tech');
```

### Step 3: Configure Callback URL

In your Amwal Pay merchant dashboard, set the callback URL to:
```
https://your-domain.com/amwalpay/callback.php
```

Replace `your-domain.com` with your actual domain.

## How It Works

### Payment Flow

1. **User selects subscription plan** → `/admin/billing.php`
2. **Payment intent created** → Stores payment data in session
3. **Redirect to process endpoint** → `/amwalpay/process.php`
4. **Auto-submit form to Amwal Pay** → User completes payment on Amwal Pay
5. **Amwal Pay redirects back** → `/amwalpay/callback.php`
6. **Callback processes payment** → Updates subscription status
7. **User redirected** → `/admin/billing.php?payment=success`

### Files Structure

```
amwalpay/
├── process.php      # Payment form submission endpoint
└── callback.php     # Payment callback handler

includes/
└── Billing.php      # Core billing integration class

admin/
└── billing.php     # Subscription management interface
```

## API Integration Details

### Payment Request

When a user subscribes, the system:
1. Generates a unique Order ID: `SUB_{companyId}_{timestamp}`
2. Creates payment data with:
   - MerchantId
   - TerminalId
   - Amount (formatted to 2 decimal places)
   - Currency (USD)
   - OrderId
   - CustomerId (company ID)
   - Description
   - CallbackUrl
   - ReturnUrl
3. Generates signature using SHA256:
   ```
   signature = SHA256(MerchantId + TerminalId + OrderId + Amount + Currency + SecureKey)
   ```
4. Stores payment data in session
5. Redirects to process endpoint which auto-submits to Amwal Pay

### Payment Callback

When Amwal Pay sends callback:
1. Receives POST data with payment status
2. Verifies signature (if provided)
3. Updates transaction status in database
4. If successful:
   - Updates company subscription
   - Sets subscription expiry date
   - Activates plan features
5. Redirects user to billing page

## Testing

### Sandbox Environment

Before going live:
1. Use Amwal Pay sandbox credentials
2. Test complete payment flow
3. Verify callbacks are received correctly
4. Check subscription activation

### Production

1. Update credentials to production values
2. Ensure callback URL is accessible via HTTPS
3. Monitor first few transactions
4. Check error logs for any issues

## Troubleshooting

### Payment Not Processing

- **Check credentials**: Verify Merchant ID, Terminal ID, and Secure Key are correct
- **Check callback URL**: Ensure it's accessible and matches Amwal Pay dashboard settings
- **Check API URL**: Should be `https://backend.sa.amwal.tech`
- **Check session**: Ensure PHP sessions are working correctly

### Callback Not Received

- **Verify URL**: Check callback URL in Amwal Pay dashboard matches your site
- **Check HTTPS**: Callback URL must be HTTPS in production
- **Check logs**: Review server error logs for callback attempts
- **Test manually**: Try accessing callback URL directly (should show error without POST data)

### Signature Verification Failed

- **Check Secure Key**: Ensure it matches Amwal Pay dashboard
- **Check signature generation**: Verify signature algorithm matches Amwal Pay requirements
- **Check data format**: Ensure amounts are formatted correctly (2 decimal places)

## Security Notes

- **Never commit credentials**: `config.php` is excluded from Git
- **Use HTTPS**: Always use HTTPS in production
- **Protect Secure Key**: Keep Secure Key secret and secure
- **Verify signatures**: Always verify payment callbacks using signature
- **Validate amounts**: Double-check payment amounts match expected values

## Support

For Amwal Pay API issues:
- **Email**: [email protected] or [email protected]
- **Documentation**: https://docs.amwal.tech
- **GitHub**: https://github.com/amwal-pay/laravel_package

For application integration issues:
- Check application logs
- Review this documentation
- Open an issue on GitHub

---

**Last Updated**: December 2024
