#!/bin/bash
#
# Fix Permissions Script for Cardify on aaPanel
# This script sets correct permissions for the application
#

APP_DIR="/www/wwwroot/cardify.om"
WEB_USER="www"

# Detect web user (aaPanel uses 'www', some systems use 'www-data')
if id "www" &>/dev/null; then
    WEB_USER="www"
elif id "www-data" &>/dev/null; then
    WEB_USER="www-data"
else
    echo "⚠ Could not detect web user, using 'www'"
    WEB_USER="www"
fi

echo "=========================================="
echo "Fixing Permissions for Cardify"
echo "=========================================="
echo ""
echo "Application Directory: $APP_DIR"
echo "Web User: $WEB_USER"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Check if directory exists
if [ ! -d "$APP_DIR" ]; then
    echo "❌ Directory not found: $APP_DIR"
    echo "Please update APP_DIR in this script"
    exit 1
fi

cd "$APP_DIR"

echo "1. Setting ownership to $WEB_USER..."
chown -R $WEB_USER:$WEB_USER .

echo "2. Setting directory permissions (755)..."
find . -type d -exec chmod 755 {} \;

echo "3. Setting file permissions (644)..."
find . -type f -exec chmod 644 {} \;

echo "4. Creating required directories..."
mkdir -p data
mkdir -p data/companies
mkdir -p uploads
mkdir -p uploads/companies
mkdir -p uploads/templates
mkdir -p uploads/cards
mkdir -p uploads/excel
mkdir -p uploads/po
mkdir -p uploads/quotations
mkdir -p uploads/invoices
mkdir -p uploads/delivery_notes
mkdir -p logs

echo "5. Setting writable permissions for data and uploads..."
chmod -R 755 data uploads logs

echo "6. Setting ownership for writable directories..."
chown -R $WEB_USER:$WEB_USER data uploads logs

echo ""
echo "=========================================="
echo "Verification"
echo "=========================================="
echo ""

# Verify
if [ -w "data" ] && [ -w "uploads" ]; then
    echo "✅ Directories are writable"
else
    echo "⚠ Directories may not be writable, trying 777..."
    chmod -R 777 data uploads
    chown -R $WEB_USER:$WEB_USER data uploads
fi

# Check ownership
echo ""
echo "Current ownership:"
ls -ld data uploads | awk '{print $3, $4, $9}'

echo ""
echo "=========================================="
echo "Restarting Apache..."
echo "=========================================="
systemctl restart apache2 2>/dev/null && echo "✅ Apache restarted" || echo "⚠ Could not restart Apache"

echo ""
echo "=========================================="
echo "Done!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Clear browser cache"
echo "2. Visit: http://cardify.om/install/"
echo "3. Complete the installation wizard"
echo ""
echo "If you still see permission errors, run:"
echo "chmod -R 777 $APP_DIR/data $APP_DIR/uploads"
echo ""
