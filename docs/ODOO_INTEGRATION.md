# Odoo ERP Integration Guide

## Overview

The Business Card Generator SaaS platform can integrate with Odoo ERP in two ways:

1. **System-wide Integration** (Super Admin) - Global Odoo connection for platform management
2. **Print Shop Integration** (Print Shops) - Individual print shops can connect their own Odoo instances

## Features

### System-wide (Super Admin)
- ✅ Global order sync for all print orders
- ✅ Centralized document management
- ✅ Platform-level reporting

### Print Shop Level
- ✅ **Individual Odoo Connection** - Each print shop connects their own Odoo instance
- ✅ **Automatic Quotation Creation** - Create Sale Orders (quotations) in Odoo from orders
- ✅ **Invoice Generation** - Generate invoices in Odoo with one click
- ✅ **Document Sync** - Upload documents and auto-attach to Odoo records
- ✅ **Customer Sync** - Companies are created as Partners in Odoo
- ✅ **Payment Tracking** - Mark invoices as paid and sync to Odoo
- ✅ **Delivery Note Integration** - Create and track delivery notes
- ✅ **Product Management** - Business Card Printing product created automatically

## Requirements

### PHP Extensions
- **XML-RPC** extension must be enabled in PHP
  
  **Quick Check:**
  ```bash
  php -m | grep xmlrpc
  ```
  
  **Installation:**
  - **Ubuntu/Debian:** `sudo apt-get install php-xmlrpc`
  - **CentOS/RHEL:** `sudo yum install php-xmlrpc` or `sudo dnf install php-xmlrpc`
  - **macOS:** Usually included with Homebrew PHP
  - **Windows:** Usually included with XAMPP/WAMP (enable in php.ini)
  
  **📖 Detailed Installation Guide:** See [INSTALL_XMLRPC.md](INSTALL_XMLRPC.md) for complete instructions for all operating systems.

### Odoo Requirements
- Odoo instance with XML-RPC enabled (default in most installations)
- User account with permissions to:
  - Create Sale Orders
  - Create Partners (Customers)
  - Create Products
  - Create Invoices
  - Attach files to records

## Setup Instructions

### Option A: Print Shop Integration (Recommended)

Each print shop can connect their own Odoo instance:

1. Log in as **Print Shop** user
2. Navigate to **Settings** (`/printshop/settings.php`)
3. Scroll to **ERP Integration** section
4. Toggle **Enable ERP Integration**
5. Select **Odoo** as the ERP system
6. Enter your Odoo details:
   - **Odoo URL**: Your Odoo instance URL (e.g., `https://odoo.yourcompany.com`)
   - **Database Name**: Your Odoo database name
   - **Username**: Odoo user email/login
   - **API Key**: API key from Odoo Settings → Users → API Keys
7. Click **"Test Connection"** to verify
8. Enable **Auto-Sync** if you want automatic order syncing
9. Click **"Save ERP Settings"**

### Option B: System-wide Integration (Super Admin)

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

## Document Settings

Print shops can configure their document workflow in Settings:

### Order Workflow Options
- **Require Quotation** - Must issue quotation before accepting orders
- **Require PO** - Customer must submit purchase order before production
- **Auto Invoice** - Automatically generate invoice when order ships

### Document Prefixes
Customize your document numbering:
- Quotation: `QT-2026-0001`
- Invoice: `INV-2026-0001`
- Delivery Note: `DN-2026-0001`

### Terms & Tax
- **Quotation Validity**: How long quotations are valid (default 30 days)
- **Payment Terms**: Days until invoice is due (default 30 days)
- **Tax Rate**: VAT/GST percentage to apply
- **Tax Number**: Your business tax registration number

## How It Works

### Print Shop Integration Flow

1. **Order Received**
   - Company places print order
   - Print shop receives notification

2. **Issue Quotation** (if required)
   - Upload quotation PDF manually, OR
   - Click "Create in Odoo" to auto-generate in Odoo
   - Quotation synced to Odoo as Draft Sale Order

3. **Receive PO** (if required)
   - Customer submits purchase order
   - Upload and record PO in system
   - Approve PO to proceed

4. **Issue Invoice**
   - Upload invoice PDF manually, OR
   - Click "Create in Odoo" to generate from Sale Order
   - Invoice created in Odoo with proper amounts

5. **Mark as Paid**
   - Record payment received
   - Payment status synced to Odoo

6. **Delivery Note**
   - Issue delivery note when shipping
   - Document attached to Odoo records

### Legacy System-wide Sync Flow

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

### Print Shop Odoo Integration (PrintShopOdoo class)

```php
// Initialize for a specific print shop
$odoo = new PrintShopOdoo($printShopId);

// Test connection
$result = $odoo->testConnection();
// Returns: ['success' => true, 'message' => 'Connection successful! UID: 123']

// Create quotation in Odoo
$result = $odoo->createQuotation($orderId);
// Returns: ['success' => true, 'quotation_number' => 'S00001', 'odoo_order_id' => 123]

// Confirm quotation (convert to sale order)
$result = $odoo->confirmQuotation($orderId);

// Create invoice in Odoo
$result = $odoo->createInvoice($orderId);
// Returns: ['success' => true, 'invoice_number' => 'INV/2026/0001', 'invoice_id' => 456]

// Mark invoice as paid
$result = $odoo->markInvoicePaid($orderId);

// Create delivery note
$result = $odoo->createDeliveryNote($orderId);

// Attach file to Odoo record
$result = $odoo->attachFile($odooRecordId, $filePath, 'Quotation', 'sale.order');

// Full order sync
$result = $odoo->syncOrder($orderId);

// Generate document numbers
$quotationNum = $odoo->generateDocumentNumber('quotation'); // QT-20260127-A1B2C
$invoiceNum = $odoo->generateDocumentNumber('invoice');     // INV-20260127-X9Y8Z
```

### System-wide Integration (OdooIntegration class)

The integration uses Odoo's XML-RPC API:

```php
OdooIntegration::authenticate()
// Returns: ['success' => true, 'uid' => 123] or error

OdooIntegration::syncOrderToOdoo($orderId)
// Creates Sale Order in Odoo

OdooIntegration::syncQuotationToOdoo($orderId, $quotationFilePath)
// Attaches quotation to Sale Order

OdooIntegration::syncInvoiceToOdoo($orderId, $invoiceFilePath)
// Creates Invoice and attaches file

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

## Supported ERP Systems

| System | Status | Notes |
|--------|--------|-------|
| Odoo | ✅ Fully Supported | v12+ compatible |
| Zoho Books | 🔜 Coming Soon | Planned |
| QuickBooks | 🔜 Coming Soon | Planned |
| SAP Business One | 🔜 Coming Soon | Enterprise |

## Limitations

- **One-way Sync**: Currently syncs from Business Card Generator to Odoo only
- **Manual Trigger for Docs**: Document uploads trigger sync (quotations/invoices can be auto-created)
- **No Status Updates**: Order status changes in Odoo don't sync back automatically
- **Single Product**: Uses one product for all print orders (can be customized)
- **API Key Recommended**: Password auth works but API keys are more secure

## Future Enhancements

- Two-way sync (status updates from Odoo via webhooks)
- Real-time webhook integration
- Custom field mapping
- Multiple product support per print shop
- Batch sync operations
- Sync history and audit logs
- Zoho Books integration
- QuickBooks Online integration
- PDF generation for invoices (without external upload)

---

**Last Updated:** January 2026  
**Odoo Version:** Compatible with Odoo 12+  
**PHP Version:** Requires PHP 7.4+ with XML-RPC extension
