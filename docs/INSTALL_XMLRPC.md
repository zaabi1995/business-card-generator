# Installing PHP XML-RPC Extension

This guide explains how to install the XML-RPC extension for PHP, which is required for Odoo ERP integration.

## Check if XML-RPC is Already Installed

First, check if XML-RPC is already available:

```bash
php -m | grep xmlrpc
```

Or create a test file:

```php
<?php
if (function_exists('xmlrpc_encode_request')) {
    echo "XML-RPC is available!\n";
} else {
    echo "XML-RPC is NOT available\n";
}
```

## Installation Methods

### Method 0: aaPanel (Recommended for VPS)

**aaPanel** is a popular web hosting control panel. Here's how to install XML-RPC:

#### Option A: Via aaPanel Web Interface (Easiest)

1. **Login to aaPanel:**
   - Open your browser and go to: `http://your-server-ip:7800` (or your configured port)
   - Login with your aaPanel credentials

2. **Navigate to PHP Settings:**
   - Click **"App Store"** in the left menu
   - Find **"PHP"** (or your PHP version like "PHP 8.1")
   - Click **"Settings"** (gear icon)

3. **Install Extension:**
   - Click on **"Install Extensions"** tab
   - Search for **"xmlrpc"**
   - Check the box next to **"xmlrpc"**
   - Click **"Install"** or **"Submit"**
   - Wait for installation to complete

4. **Verify Installation:**
   - Go to **"PHP Info"** tab
   - Search for "xmlrpc"
   - You should see XML-RPC extension listed

5. **Restart Services:**
   - Go back to **"App Store"**
   - Find your web server (Nginx/Apache)
   - Click **"Restart"**

#### Option B: Via SSH/Terminal (If web interface doesn't work)

```bash
# Connect to your VPS via SSH
ssh root@your-server-ip

# Check which PHP version is active
php -v

# Install XML-RPC for your PHP version
# For PHP 8.1:
yum install php81-php-xmlrpc -y
# OR
apt-get install php8.1-xmlrpc -y

# For PHP 8.2:
yum install php82-php-xmlrpc -y
# OR
apt-get install php8.2-xmlrpc -y

# Restart PHP-FPM
systemctl restart php-fpm-81  # Adjust version number
# OR
systemctl restart php8.1-fpm

# Restart web server
systemctl restart nginx
# OR
systemctl restart httpd
```

#### Option C: Manual Installation for aaPanel

If the above methods don't work:

```bash
# SSH into your VPS
ssh root@your-server-ip

# Find PHP installation directory
which php
# Usually: /www/server/php/81/bin/php (for PHP 8.1)

# Check PHP version
/www/server/php/81/bin/php -v

# Install XML-RPC extension
# For CentOS/RHEL (aaPanel usually uses this):
yum install -y php81-php-xmlrpc

# For Debian/Ubuntu:
apt-get update
apt-get install -y php8.1-xmlrpc

# Enable extension in php.ini
# Find php.ini location
/www/server/php/81/bin/php --ini

# Edit php.ini (usually at /www/server/php/81/etc/php.ini)
nano /www/server/php/81/etc/php.ini

# Add or uncomment this line:
extension=xmlrpc.so

# Save and exit (Ctrl+X, then Y, then Enter)

# Restart PHP-FPM
systemctl restart php-fpm-81

# Restart web server
systemctl restart nginx
# OR
systemctl restart httpd
```

#### Verify in aaPanel

1. Go to **App Store** → **PHP** → **Settings**
2. Click **"PHP Info"** tab
3. Search for "xmlrpc"
4. Should see: `xmlrpc support => enabled`

#### aaPanel Troubleshooting

**Problem:** Extension not showing in aaPanel interface

**Solution:**
```bash
# Check if extension file exists
find /www/server/php -name "xmlrpc.so"

# Check PHP configuration
/www/server/php/81/bin/php -i | grep xmlrpc

# Manually add to php.ini
echo "extension=xmlrpc.so" >> /www/server/php/81/etc/php.ini

# Restart services
systemctl restart php-fpm-81
systemctl restart nginx
```

**Problem:** Multiple PHP versions installed

**Solution:**
```bash
# List all PHP versions
ls /www/server/php/

# Install for each version you use
yum install php81-php-xmlrpc php82-php-xmlrpc -y

# Enable in each php.ini
nano /www/server/php/81/etc/php.ini
nano /www/server/php/82/etc/php.ini
# Add: extension=xmlrpc.so to each

# Restart all PHP-FPM services
systemctl restart php-fpm-81
systemctl restart php-fpm-82
```

### Method 1: Ubuntu/Debian (apt)

```bash
# Update package list
sudo apt-get update

# Install XML-RPC extension
sudo apt-get install php-xmlrpc

# For specific PHP version (e.g., PHP 8.1)
sudo apt-get install php8.1-xmlrpc

# Restart web server
sudo systemctl restart apache2
# OR
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx
```

### Method 2: CentOS/RHEL/Fedora (yum/dnf)

```bash
# For CentOS/RHEL 7
sudo yum install php-xmlrpc

# For CentOS/RHEL 8+ or Fedora
sudo dnf install php-xmlrpc

# Restart web server
sudo systemctl restart httpd
# OR
sudo systemctl restart php-fpm
sudo systemctl restart nginx
```

### Method 3: macOS (Homebrew)

```bash
# Install PHP with XML-RPC (if installing PHP)
brew install php

# XML-RPC is usually included by default with Homebrew PHP
# If not, you may need to compile PHP with --with-xmlrpc flag

# Restart PHP-FPM
brew services restart php
```

### Method 4: Windows

#### Using XAMPP/WAMP

1. **XAMPP:**
   - XML-RPC is usually included by default
   - Check `php.ini` file in `C:\xampp\php\php.ini`
   - Look for: `extension=xmlrpc`
   - If commented out, uncomment it: `;extension=xmlrpc` → `extension=xmlrpc`
   - Restart Apache

2. **WAMP:**
   - Click WAMP icon → PHP → PHP Extensions
   - Check "php_xmlrpc"
   - Restart all services

#### Manual Installation

1. Download PHP XML-RPC DLL from [PECL](https://pecl.php.net/package/xmlrpc)
2. Extract `php_xmlrpc.dll` to PHP extensions directory
3. Edit `php.ini` and add: `extension=xmlrpc`
4. Restart web server

### Method 5: Compile from Source

If you compiled PHP from source:

```bash
# Download PHP source
wget https://www.php.net/distributions/php-8.1.0.tar.gz
tar -xzf php-8.1.0.tar.gz
cd php-8.1.0

# Configure with XML-RPC support
./configure --with-xmlrpc

# Compile and install
make
sudo make install
```

## Enable in php.ini

After installation, verify it's enabled in `php.ini`:

```bash
# Find php.ini location
php --ini

# Edit php.ini
sudo nano /etc/php/8.1/apache2/php.ini  # Adjust path/version

# Look for and uncomment (or add):
extension=xmlrpc
```

## Verify Installation

After installation, verify it works:

```bash
# Check PHP modules
php -m | grep xmlrpc

# Test functions
php -r "echo function_exists('xmlrpc_encode_request') ? 'OK' : 'FAIL';"
```

## Troubleshooting

### Extension Not Loading

**Problem:** Extension installed but not loading

**Solution:**
1. Check `php.ini` location:
   ```bash
   php --ini
   ```

2. Verify extension file exists:
   ```bash
   find /usr -name "xmlrpc.so" 2>/dev/null
   ```

3. Check `php.ini` for correct path:
   ```ini
   extension=xmlrpc.so
   # OR full path
   extension=/usr/lib/php/20210902/xmlrpc.so
   ```

4. Check for errors:
   ```bash
   php -i | grep xmlrpc
   ```

### Permission Issues

**Problem:** Cannot write to php.ini

**Solution:**
```bash
# Use sudo to edit
sudo nano /etc/php/8.1/apache2/php.ini

# Or change ownership temporarily
sudo chown $USER /etc/php/8.1/apache2/php.ini
```

### Web Server Not Restarting

**Problem:** Changes not taking effect

**Solution:**
```bash
# Apache
sudo systemctl restart apache2
sudo service apache2 restart

# Nginx + PHP-FPM
sudo systemctl restart php8.1-fpm
sudo systemctl restart nginx

# Check status
sudo systemctl status apache2
sudo systemctl status php8.1-fpm
```

### Multiple PHP Versions

**Problem:** Wrong PHP version being used

**Solution:**
```bash
# Check which PHP version
php -v

# Install for specific version
sudo apt-get install php8.1-xmlrpc  # For PHP 8.1
sudo apt-get install php8.2-xmlrpc  # For PHP 8.2

# Verify correct version
php -m | grep xmlrpc
```

## Alternative: Use JSON-RPC Instead

If XML-RPC installation is problematic, the integration can be updated to use JSON-RPC, which:
- ✅ Works with standard PHP (no extension needed)
- ✅ Uses cURL (usually already installed)
- ✅ More modern and efficient
- ✅ Fully supported by Odoo

**Note:** The current implementation uses XML-RPC, but we can easily switch to JSON-RPC if needed.

## Automated Installation Script (aaPanel)

For **aaPanel users**, we've created an automated installation script:

```bash
# Download the script
wget https://raw.githubusercontent.com/zaabi1995/business-card-generator/main/install_xmlrpc_aapanel.sh

# Make it executable
chmod +x install_xmlrpc_aapanel.sh

# Run as root
sudo ./install_xmlrpc_aapanel.sh
```

**What the script does:**
- ✅ Detects all PHP versions installed in aaPanel
- ✅ Installs XML-RPC extension for each version
- ✅ Enables extension in php.ini
- ✅ Restarts PHP-FPM services
- ✅ Restarts web server
- ✅ Verifies installation

**Or use the script from the repository:**
```bash
cd /path/to/business-card-generator
chmod +x install_xmlrpc_aapanel.sh
sudo ./install_xmlrpc_aapanel.sh
```

## Quick Test Script

Create `test_xmlrpc.php`:

```php
<?php
echo "PHP Version: " . PHP_VERSION . "\n";
echo "XML-RPC Available: " . (function_exists('xmlrpc_encode_request') ? 'YES' : 'NO') . "\n";

if (function_exists('xmlrpc_encode_request')) {
    echo "✓ XML-RPC is ready to use!\n";
} else {
    echo "✗ XML-RPC is not available. Please install php-xmlrpc extension.\n";
}
```

Run:
```bash
php test_xmlrpc.php
```

## Production Server Checklist

- [ ] XML-RPC extension installed
- [ ] Extension enabled in php.ini
- [ ] Web server restarted
- [ ] PHP-FPM restarted (if using)
- [ ] Test script confirms availability
- [ ] Odoo connection test passes

## aaPanel Quick Checklist

If using **aaPanel** on VPS:

1. ✅ Login to aaPanel web interface (`http://your-ip:7800`)
2. ✅ Go to **App Store** → **PHP** → **Settings**
3. ✅ Click **Install Extensions** tab
4. ✅ Search and install **xmlrpc** extension
5. ✅ Restart PHP-FPM service
6. ✅ Restart web server (Nginx/Apache)
7. ✅ Verify in **PHP Info** tab
8. ✅ Test with: `php -m | grep xmlrpc`

**Common aaPanel PHP Paths:**
- PHP binaries: `/www/server/php/81/bin/php` (adjust version)
- PHP config: `/www/server/php/81/etc/php.ini`
- PHP extensions: `/www/server/php/81/lib/php/extensions/`
- PHP-FPM: `systemctl restart php-fpm-81`

---

**Need Help?** If you encounter issues, check:
1. PHP error logs: `/var/log/php/error.log`
2. Web server logs: `/var/log/apache2/error.log` or `/var/log/nginx/error.log`
3. PHP info: `php -i | grep xmlrpc`
