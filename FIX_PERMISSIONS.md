# Fixing Permission Issues on aaPanel/VPS

## Problem

You're seeing errors like:
```
Warning: mkdir(): Permission denied in /www/wwwroot/bc.bhd.om/includes/functions.php on line 695
Warning: file_put_contents(/www/wwwroot/bc.bhd.om/data/companies.json): Failed to open stream: Permission denied
```

This happens because the web server user doesn't have write permissions to create directories and files.

## Quick Fix for aaPanel

### Step 1: Set Correct Permissions

Run these commands on your VPS:

```bash
# Navigate to your application directory
cd /www/wwwroot/bc.bhd.om

# Set ownership to www (aaPanel's web server user)
chown -R www:www .

# Set directory permissions
find . -type d -exec chmod 755 {} \;

# Set file permissions
find . -type f -exec chmod 644 {} \;

# Make specific directories writable
chmod -R 755 data uploads logs
chmod -R 777 data uploads  # If 755 doesn't work, use 777 temporarily

# Create required directories if they don't exist
mkdir -p data companies data/companies uploads uploads/companies uploads/templates uploads/cards uploads/excel uploads/po uploads/quotations uploads/invoices uploads/delivery_notes logs

# Set ownership for these directories
chown -R www:www data uploads logs
```

### Step 2: Verify Permissions

```bash
# Check ownership
ls -la /www/wwwroot/bc.bhd.om/data
ls -la /www/wwwroot/bc.bhd.om/uploads

# Should show: www www (or www-data www-data)
```

### Step 3: Restart Apache

```bash
systemctl restart apache2
```

## Automated Fix Script

Create and run this script:

```bash
#!/bin/bash
# Fix permissions for Business Card Generator on aaPanel

APP_DIR="/www/wwwroot/bc.bhd.om"
WEB_USER="www"

echo "Fixing permissions for $APP_DIR..."

# Set ownership
chown -R $WEB_USER:$WEB_USER $APP_DIR

# Set directory permissions
find $APP_DIR -type d -exec chmod 755 {} \;

# Set file permissions
find $APP_DIR -type f -exec chmod 644 {} \;

# Create required directories
mkdir -p $APP_DIR/data
mkdir -p $APP_DIR/data/companies
mkdir -p $APP_DIR/uploads
mkdir -p $APP_DIR/uploads/companies
mkdir -p $APP_DIR/uploads/templates
mkdir -p $APP_DIR/uploads/cards
mkdir -p $APP_DIR/uploads/excel
mkdir -p $APP_DIR/uploads/po
mkdir -p $APP_DIR/uploads/quotations
mkdir -p $APP_DIR/uploads/invoices
mkdir -p $APP_DIR/uploads/delivery_notes
mkdir -p $APP_DIR/logs

# Set writable permissions for data and uploads
chmod -R 755 $APP_DIR/data
chmod -R 755 $APP_DIR/uploads
chmod -R 755 $APP_DIR/logs

# Set ownership for writable directories
chown -R $WEB_USER:$WEB_USER $APP_DIR/data
chown -R $WEB_USER:$WEB_USER $APP_DIR/uploads
chown -R $WEB_USER:$WEB_USER $APP_DIR/logs

echo "✓ Permissions fixed!"
echo ""
echo "If you still see permission errors, try:"
echo "chmod -R 777 $APP_DIR/data $APP_DIR/uploads"
```

Save as `fix_permissions.sh`, make executable, and run:
```bash
chmod +x fix_permissions.sh
sudo ./fix_permissions.sh
```

## Via aaPanel File Manager

1. Login to aaPanel
2. Go to **Files** → Navigate to `/www/wwwroot/bc.bhd.om`
3. Right-click on `data` folder → **Permissions** → Set to `755` or `777`
4. Right-click on `uploads` folder → **Permissions** → Set to `755` or `777`
5. Set **Owner** to `www` (or `www-data`)

## Check Installation Wizard Access

After fixing permissions, verify the installer is accessible:

1. Go to: `http://bc.bhd.om/install/`
2. Should see the installation wizard
3. If not, check:
   - Is `/install/` directory accessible?
   - Check Apache error logs: `tail -f /www/wwwlogs/bc.bhd.om-error.log`

## Common Issues

### Issue: Still getting permission errors

**Solution:**
```bash
# More permissive (less secure but works)
chmod -R 777 /www/wwwroot/bc.bhd.om/data
chmod -R 777 /www/wwwroot/bc.bhd.om/uploads
chown -R www:www /www/wwwroot/bc.bhd.om/data
chown -R www:www /www/wwwroot/bc.bhd.om/uploads
```

### Issue: Installation wizard not showing

**Solution:**
```bash
# Check if install directory exists
ls -la /www/wwwroot/bc.bhd.om/install

# Check Apache configuration
apache2ctl -S

# Check if .htaccess is blocking install
# Temporarily rename .htaccess
mv /www/wwwroot/bc.bhd.om/.htaccess /www/wwwroot/bc.bhd.om/.htaccess.bak
# Try accessing /install/ again
# If it works, check .htaccess rules
```

### Issue: SELinux blocking (if enabled)

**Solution:**
```bash
# Check if SELinux is enabled
getenforce

# If enforcing, set context
chcon -R -t httpd_sys_rw_content_t /www/wwwroot/bc.bhd.om/data
chcon -R -t httpd_sys_rw_content_t /www/wwwroot/bc.bhd.om/uploads
```

## After Fixing Permissions

1. **Clear browser cache** and reload `http://bc.bhd.om`
2. **Check if installer appears** at `http://bc.bhd.om/install/`
3. **Run installation wizard** to complete setup
4. **Verify** no more permission errors

## Security Note

Using `777` permissions is less secure. For production:
- Use `755` for directories
- Use `644` for files
- Ensure correct ownership (`www:www`)
- Only `data/` and `uploads/` need write access

---

**Quick Command Summary:**
```bash
cd /www/wwwroot/bc.bhd.om
chown -R www:www .
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;
chmod -R 755 data uploads
chown -R www:www data uploads
systemctl restart apache2
```
