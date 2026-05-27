#!/bin/bash
#
# XML-RPC Installation Script for aaPanel
# This script automatically installs and configures XML-RPC extension
#

echo "=========================================="
echo "XML-RPC Installation for aaPanel"
echo "=========================================="
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "❌ Please run as root (use sudo)"
    exit 1
fi

# Detect OS
if [ -f /etc/redhat-release ]; then
    OS="centos"
    echo "✓ Detected: CentOS/RHEL"
elif [ -f /etc/debian_version ]; then
    OS="debian"
    echo "✓ Detected: Debian/Ubuntu"
else
    echo "❌ Unsupported OS"
    exit 1
fi

# Find PHP versions in aaPanel
echo ""
echo "Searching for PHP installations..."
PHP_VERSIONS=$(ls -d /www/server/php/*/ 2>/dev/null | grep -oP '\d+\.\d+' | sort -u)

if [ -z "$PHP_VERSIONS" ]; then
    echo "❌ No PHP installations found in /www/server/php/"
    echo "Please install PHP via aaPanel first"
    exit 1
fi

echo "Found PHP versions: $PHP_VERSIONS"
echo ""

# Function to install XML-RPC for a PHP version
install_xmlrpc() {
    local php_version=$1
    local major_minor=$(echo $php_version | cut -d. -f1,2)
    
    echo "----------------------------------------"
    echo "Installing XML-RPC for PHP $php_version"
    echo "----------------------------------------"
    
    # Install extension based on OS
    if [ "$OS" = "centos" ]; then
        # Try different package naming conventions
        if yum install -y php${major_minor//./}-php-xmlrpc 2>/dev/null; then
            echo "✓ Installed via yum"
        elif yum install -y php-xmlrpc 2>/dev/null; then
            echo "✓ Installed generic php-xmlrpc"
        else
            echo "⚠ Could not install via yum, trying manual method..."
            return 1
        fi
    else
        # Debian/Ubuntu
        if apt-get update && apt-get install -y php${major_minor}-xmlrpc 2>/dev/null; then
            echo "✓ Installed via apt"
        elif apt-get install -y php-xmlrpc 2>/dev/null; then
            echo "✓ Installed generic php-xmlrpc"
        else
            echo "⚠ Could not install via apt, trying manual method..."
            return 1
        fi
    fi
    
    # Enable in php.ini
    local php_ini="/www/server/php/$php_version/etc/php.ini"
    
    if [ -f "$php_ini" ]; then
        # Check if already enabled
        if grep -q "^extension=xmlrpc.so" "$php_ini" || grep -q "^;extension=xmlrpc.so" "$php_ini"; then
            # Uncomment if commented
            sed -i 's/^;extension=xmlrpc.so/extension=xmlrpc.so/' "$php_ini"
            echo "✓ Enabled in php.ini"
        else
            # Add if not present
            echo "" >> "$php_ini"
            echo "; XML-RPC Extension" >> "$php_ini"
            echo "extension=xmlrpc.so" >> "$php_ini"
            echo "✓ Added to php.ini"
        fi
    else
        echo "⚠ php.ini not found at $php_ini"
        return 1
    fi
    
    # Restart PHP-FPM
    local fpm_service="php-fpm-$major_minor"
    if systemctl list-units --type=service | grep -q "$fpm_service"; then
        systemctl restart "$fpm_service"
        echo "✓ Restarted PHP-FPM service: $fpm_service"
    else
        echo "⚠ PHP-FPM service not found: $fpm_service"
    fi
    
    # Verify installation
    local php_bin="/www/server/php/$php_version/bin/php"
    if [ -f "$php_bin" ]; then
        if "$php_bin" -m | grep -q xmlrpc; then
            echo "✅ XML-RPC is now available for PHP $php_version"
            return 0
        else
            echo "❌ XML-RPC not detected for PHP $php_version"
            return 1
        fi
    fi
    
    return 0
}

# Install for each PHP version
SUCCESS=0
FAILED=0

for version in $PHP_VERSIONS; do
    if install_xmlrpc "$version"; then
        SUCCESS=$((SUCCESS + 1))
    else
        FAILED=$((FAILED + 1))
    fi
    echo ""
done

# Restart web server
echo "----------------------------------------"
echo "Restarting web server..."
if systemctl list-units --type=service | grep -q apache2; then
    systemctl restart apache2
    echo "✓ Restarted Apache2"
elif systemctl list-units --type=service | grep -q httpd; then
    systemctl restart httpd
    echo "✓ Restarted Apache (httpd)"
elif systemctl list-units --type=service | grep -q nginx; then
    systemctl restart nginx
    echo "✓ Restarted Nginx"
else
    echo "⚠ Could not detect web server, please restart manually"
fi

# Final verification
echo ""
echo "=========================================="
echo "Installation Summary"
echo "=========================================="
echo "✅ Successfully configured: $SUCCESS PHP version(s)"
if [ $FAILED -gt 0 ]; then
    echo "❌ Failed: $FAILED PHP version(s)"
fi
echo ""

# Test with default PHP
echo "Testing XML-RPC availability..."
if php -m | grep -q xmlrpc; then
    echo "✅ XML-RPC is available!"
    php -r "echo 'XML-RPC functions: '; echo function_exists('xmlrpc_encode_request') ? 'Available' : 'Not Available'; echo PHP_EOL;"
else
    echo "❌ XML-RPC is not available"
    echo ""
    echo "Please check:"
    echo "1. PHP version: php -v"
    echo "2. Loaded modules: php -m | grep xmlrpc"
    echo "3. php.ini location: php --ini"
fi

echo ""
echo "=========================================="
echo "Installation Complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "1. Test Odoo connection in admin panel"
echo "2. Check PHP Info in aaPanel: App Store → PHP → Settings → PHP Info"
echo "3. Search for 'xmlrpc' to verify it's loaded"
echo ""
