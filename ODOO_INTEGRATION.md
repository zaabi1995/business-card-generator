# Odoo ERP Integration Guide

## Overview

The Business Card Generator SaaS platform can now integrate with Odoo ERP, allowing printers to automatically sync print orders, quotations, invoices, and delivery notes with their Odoo system.

## Features

- ✅ **Automatic Order Sync** - Print orders are automatically created as Sale Orders in Odoo
- ✅ **Quotation Sync** - Quotations are attached to Sale Orders
- ✅ **Invoice Sync** - Invoices are created in Odoo and attached to orders
- ✅ **Delivery Note Sync** - Delivery notes are attached to Sale Orders
- ✅ **Customer Management** - Companies are automatically created as Partners in Odoo
- ✅ **Product Management** - Business Card Printing product is created if it doesn't exist

## Requirements

### PHP Extensions
- **XML-RPC** extension must be enabled in PHP
  ```bash
  # Check if enabled
  php -m | grep xmlrpc
  
  # Enable if needed (varies by system)
  # Ubuntu/Debian: sudo apt-get install php-xmlrpc
  # Or enable in php.ini: extension=xmlrpc.so
  ```

### Odoo Requirements
- Odoo instance with XML-RPC enabled (default in most installations)
- User account with permissions to:
  - Create Sale Orders
  - Create Partners (Customers)
  - Create Products
  - Create Invoices
  - Attach files to records

## Setup Instructions

### 1. Configure Odoo Connection

1. Log in as **Super Admin**
2. Navigate to `/admin/super/`
3. Click on **"Odoo Integration"**
4. Enter your Odoo details:
   - **Odoo URL**: Your Odoo instance URL (e.g., `https://odoo.yourcompany.com`)
   - **Database Name**: Your Odoo database name
   - **Username**: Odoo user with appropriate permissions
   - **Password**: User password
5. Click **"Test Connection"** to verify
6. Enable integration using the toggle switch
7. Click **"Save Settings"**

### 2. Odoo User Permissions

Create or use an existing Odoo user with these permissions:

**Required Access Rights:**
- Sales → Sales Orders (Create, Write, Read)
- Sales → Customers (Create, Write, Read)
- Sales → Products (Create, Write, Read)
- Accounting → Invoices (Create, Write, Read)
- Documents → Attachments (Create, Write, Read)

**Recommended:** Create a dedicated user for API integration with limited permissions.

### 3. Enable XML-RPC in Odoo

XML-RPC is enabled by default in Odoo. If disabled, enable it in Odoo configuration:

```python
# In Odoo configuration file (odoo.conf)
[options]
xmlrpc = True
xmlrpc_interface = 0.0.0.0
xmlrpc_port = 8069
```

## How It Works

### Automatic Sync Flow

1. **Print Order Created**
   - Company creates a print order
   - System automatically creates a Sale Order in Odoo
   - Order number is used as reference
   - Company is created as Partner (if doesn't exist)

2. **Quotation Uploaded**
   - Printer uploads quotation document
   - Document is automatically attached to the Sale Order in Odoo
   - File is stored in Odoo attachments

3. **Invoice Uploaded**
   - Printer uploads invoice document
   - System creates Invoice in Odoo from the Sale Order
   - Invoice document is attached to both Sale Order and Invoice

4. **Delivery Note Uploaded**
   - Printer uploads delivery note
   - Document is attached to the Sale Order in Odoo

### Data Mapping

**Print Order → Odoo Sale Order:**
- Order Number → Sale Order Reference
- Company Name → Partner Name
- Company Email → Partner Email
- Employee Count × Quantity → Product Quantity
- Notes → Sale Order Notes

**Documents → Odoo Attachments:**
- PO Document → Attached to Sale Order
- Quotation → Attached to Sale Order
- Invoice → Attached to Sale Order and Invoice record
- Delivery Note → Attached to Sale Order

## API Methods

The integration uses Odoo's XML-RPC API:

### Authentication
```php
OdooIntegration::authenticate()
// Returns: ['success' => true, 'uid' => 123] or error
```

### Sync Order
```php
OdooIntegration::syncOrderToOdoo($orderId)
// Creates Sale Order in Odoo
```

### Sync Quotation
```php
OdooIntegration::syncQuotationToOdoo($orderId, $quotationFilePath)
// Attaches quotation to Sale Order
```

### Sync Invoice
```php
OdooIntegration::syncInvoiceToOdoo($orderId, $invoiceFilePath)
// Creates Invoice and attaches file
```

### Sync Delivery Note
```php
OdooIntegration::syncDeliveryNoteToOdoo($orderId, $deliveryNoteFilePath)
// Attaches delivery note to Sale Order
```

## Troubleshooting

### Connection Failed

**Symptoms:** "Connection failed" error when testing

**Solutions:**
1. Verify Odoo URL is correct (no trailing slash)
2. Check if XML-RPC is enabled in Odoo
3. Verify database name is correct
4. Check username and password
5. Ensure Odoo instance is accessible from your server
6. Check firewall rules

### Authentication Failed

**Symptoms:** "Odoo authentication failed" error

**Solutions:**
1. Verify username and password are correct
2. Check if user account is active in Odoo
3. Verify user has required permissions
4. Check Odoo logs for authentication errors

### Sync Failed

**Symptoms:** Documents not syncing to Odoo

**Solutions:**
1. Check if integration is enabled
2. Verify Odoo connection is working (test connection)
3. Check user permissions in Odoo
4. Review server error logs
5. Verify file paths are accessible

### PHP XML-RPC Not Available

**Symptoms:** "Call to undefined function xmlrpc_encode_request()"

**Solutions:**
```bash
# Install XML-RPC extension
# Ubuntu/Debian
sudo apt-get install php-xmlrpc
sudo systemctl restart apache2  # or nginx/php-fpm

# Or compile PHP with --with-xmlrpc
```

## Security Considerations

1. **Credentials Storage**
   - Odoo credentials are stored in `system_settings` table
   - Password is stored in plain text (consider encryption for production)
   - Only super admins can access settings

2. **Network Security**
   - Use HTTPS for Odoo connection
   - Consider VPN or private network for Odoo access
   - Restrict Odoo user permissions to minimum required

3. **API Access**
   - Use dedicated Odoo user for integration
   - Limit user permissions to required modules only
   - Regularly review and audit API access logs

## Advanced Configuration

### Custom Product Mapping

To use a different product in Odoo, modify `getPrintProductId()` in `OdooIntegration.php`:

```php
private static function getPrintProductId() {
    // Search for your custom product
    $searchResult = self::executeRPC('product.product', 'search', [[
        ['name', '=', 'Your Custom Product Name']
    ]]);
    // ...
}
```

### Custom Partner Fields

To add custom fields when creating partners, modify `findOrCreatePartner()`:

```php
$partnerData = [
    'name' => $name,
    'email' => $email,
    'customer_rank' => 1,
    'is_company' => true,
    'phone' => $phone,  // Add custom fields
    'vat' => $vat,      // Add VAT number
    // ...
];
```

## Testing

### Test Connection
1. Go to `/admin/odoo_settings.php`
2. Enter Odoo credentials
3. Click **"Test Connection"**
4. Should show "Connection successful! UID: [number]"

### Test Order Sync
1. Create a print order
2. Check Odoo → Sales → Orders
3. Verify order appears with correct details

### Test Document Sync
1. Upload a quotation/invoice/delivery note
2. Check Odoo → Sales → Orders → [Order] → Attachments
3. Verify document is attached

## Limitations

- **One-way Sync**: Currently syncs from Business Card Generator to Odoo only
- **Manual Trigger**: Sync happens when documents are uploaded (not real-time)
- **No Status Updates**: Order status changes in Odoo don't sync back
- **Single Product**: Uses one product for all print orders (can be customized)

## Future Enhancements

- Two-way sync (status updates from Odoo)
- Real-time webhook integration
- Custom field mapping
- Multiple product support
- Batch sync operations
- Sync history and logs

---

**Last Updated:** December 2024  
**Odoo Version:** Compatible with Odoo 12+  
**PHP Version:** Requires PHP 7.4+ with XML-RPC extension
