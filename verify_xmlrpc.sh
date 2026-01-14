#!/bin/bash
# Quick XML-RPC Verification Script for Ubuntu/aaPanel

echo "=========================================="
echo "XML-RPC Verification"
echo "=========================================="
echo ""

# Check with system PHP (ignoring warnings)
echo "1. Checking XML-RPC module (ignoring warnings):"
php -m 2>/dev/null | grep -i xmlrpc && echo "   ✓ XML-RPC module is loaded!" || echo "   ✗ XML-RPC module NOT found"

echo ""
echo "2. Testing XML-RPC functions:"
php -r "echo function_exists('xmlrpc_encode_request') ? '   ✓ xmlrpc_encode_request: Available\n' : '   ✗ xmlrpc_encode_request: Not Available\n';" 2>/dev/null
php -r "echo function_exists('xmlrpc_decode') ? '   ✓ xmlrpc_decode: Available\n' : '   ✗ xmlrpc_decode: Not Available\n';" 2>/dev/null

echo ""
echo "3. Checking aaPanel PHP (if exists):"
if [ -d "/www/server/php" ]; then
    AAPANEL_PHP=$(find /www/server/php -name "php" -type f | head -1)
    if [ -n "$AAPANEL_PHP" ]; then
        echo "   Found: $AAPANEL_PHP"
        $AAPANEL_PHP -m 2>/dev/null | grep -i xmlrpc && echo "   ✓ XML-RPC loaded in aaPanel PHP!" || echo "   ✗ XML-RPC not in aaPanel PHP"
    else
        echo "   No PHP binary found in /www/server/php/"
    fi
else
    echo "   aaPanel PHP directory not found (using system PHP)"
fi

echo ""
echo "4. Restarting Apache..."
systemctl restart apache2 2>/dev/null && echo "   ✓ Apache restarted" || echo "   ⚠ Could not restart Apache (may need sudo)"

echo ""
echo "=========================================="
echo "Summary"
echo "=========================================="
echo ""
if php -r "echo function_exists('xmlrpc_encode_request') ? 'OK' : 'FAIL';" 2>/dev/null | grep -q "OK"; then
    echo "✅ XML-RPC is WORKING!"
    echo ""
    echo "You can now:"
    echo "1. Go to /admin/odoo_settings.php"
    echo "2. Configure your Odoo connection"
    echo "3. Test the connection"
else
    echo "❌ XML-RPC is NOT working"
    echo ""
    echo "Try:"
    echo "1. Check php.ini: php --ini"
    echo "2. Look for: extension=xmlrpc.so"
    echo "3. Restart Apache: systemctl restart apache2"
fi
echo ""
