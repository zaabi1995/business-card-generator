# WhatsApp Integration Guide

## Overview

The application now supports automatic WhatsApp confirmation messages when print orders are created. This feature allows companies to receive instant notifications about their print orders via WhatsApp.

## Features

- ✅ **P.O. File Upload** - Companies can upload Purchase Order documents when creating print orders
- ✅ **WhatsApp Notifications** - Automatic confirmation messages sent via WhatsApp REST API
- ✅ **Admin Configuration** - Super admin can configure WhatsApp API token and enable/disable notifications

## Setup Instructions

### 1. Configure WhatsApp API Token

1. Log in as **Super Admin**
2. Navigate to `/admin/super/`
3. Click on **"WhatsApp Settings"**
4. Enter your WhatsApp REST API token (JWT format)
5. Enable notifications using the toggle switch
6. Click **"Save Token"**

### 2. Default Token

The default token provided during setup:
```
eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJ1aWQiOiJENEVFdTRmZGdJZncyMTFqeERVVFJ0VHFkZVcyU1RMTCIsInJvbGUiOiJ1c2VyIiwiaWF0IjoxNzY3NjAzMDUwfQ.atbDJ8y1bXTKiq6xZ7KUX5TzYkTWbrvjMrK4SCmDCUY
```

**⚠️ Important:** Replace this with your actual production token from your WhatsApp API provider.

### 3. API Endpoint Configuration

The WhatsApp API endpoint is currently set to a generic URL. You may need to update it in `/includes/WhatsApp.php` based on your provider:

```php
// Line 74 in includes/WhatsApp.php
$apiUrl = 'https://api.whatsapp.com/v1/messages'; // Replace with your actual API endpoint
```

Common WhatsApp API providers:
- **Twilio** - `https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json`
- **MessageBird** - `https://rest.messagebird.com/messages`
- **WhatsApp Business API** - `https://graph.facebook.com/v18.0/{phone-number-id}/messages`
- **Custom Provider** - Use your provider's endpoint

### 4. Update API Payload (if needed)

If your WhatsApp API provider uses a different payload structure, update the `sendMessage()` method in `/includes/WhatsApp.php`:

```php
$payload = [
    'to' => $phoneNumber,
    'message' => $message,
    'type' => 'text'
];
```

Adjust the payload keys and structure based on your provider's requirements.

## Usage

### Creating Print Orders with P.O.

1. Navigate to `/admin/print.php`
2. Select employees for the print order
3. Choose template and quantity
4. Add notes (optional)
5. **Upload P.O. Document** (optional) - PDF, JPG, or PNG format
6. Click **"Create Print Order"**

### WhatsApp Confirmation Message

When a print order is created:
- If WhatsApp is enabled and company has a phone number
- A confirmation message is automatically sent with:
  - Order number
  - Company name
  - Number of employees
  - Quantity per employee
  - Total cards
  - Order status
  - Notes (if any)

### Viewing P.O. Documents

- P.O. documents are stored in `/uploads/companies/{company_id}/po/`
- View P.O. documents from the print orders list
- Click **"📄 View P.O. Document"** link

## Database Changes

### New Column
- `print_orders.po_file_path` - Stores path to uploaded P.O. document

### New Table
- `system_settings` - Stores WhatsApp API token and other system settings

## Migration

The migration `004_print_po_whatsapp.php` will:
1. Add `po_file_path` column to `print_orders` table
2. Create `system_settings` table
3. Insert default WhatsApp settings

Run migration via:
- Installation wizard (automatic)
- `/admin/updates.php` (manual)

## Troubleshooting

### WhatsApp Messages Not Sending

1. **Check Token Configuration**
   - Verify token is saved in WhatsApp Settings
   - Ensure notifications are enabled

2. **Check Company Phone Number**
   - Company must have a valid phone number in their profile
   - Phone number should be in international format (e.g., +96812345678)

3. **Check API Endpoint**
   - Verify API endpoint URL is correct for your provider
   - Check API payload structure matches your provider

4. **Check API Response**
   - Review server error logs for API errors
   - Verify token is valid and not expired
   - Check API rate limits

### P.O. Upload Issues

1. **File Size**
   - Maximum file size: 10MB (configured in PHP settings)
   - Check `upload_max_filesize` in `php.ini`

2. **File Type**
   - Accepted formats: PDF, JPG, JPEG, PNG
   - Verify file extension matches content type

3. **Permissions**
   - Ensure `/uploads/companies/{company_id}/po/` directory is writable
   - Check file permissions (755 recommended)

## Security Notes

- WhatsApp API token is stored securely in the database
- Only super admins can configure WhatsApp settings
- P.O. documents are stored per-company (isolated)
- File uploads are validated for type and size

## Future Enhancements

- Support for multiple WhatsApp providers
- Custom message templates
- Delivery status tracking
- Bulk WhatsApp notifications
- WhatsApp template messages (for approved business accounts)

---

**Last Updated:** December 2024
