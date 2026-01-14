# Enhanced Admin Panel Features

## Overview

The Business Card Generator SaaS now includes a comprehensive unified login system with role-based access, company theme customization, department management, shareable design links, and print functionality.

## New Features

### 1. ✅ Unified Login System (`/login.php`)

**Single login page for all user types:**
- **Super Admin** - Platform administration
- **Company Admin** - Company management
- **Employees** - Card generation access

**Features:**
- Automatic role detection
- Company-specific theme on login page
- Smart redirect based on user role
- Legacy support for existing company logins

**Usage:**
- Navigate to `/login.php`
- Enter email and password
- Optionally enter company code for company-specific login
- System automatically detects user type and redirects

### 2. ✅ Role-Based Access Control

**User Roles:**
- `super_admin` - Full platform access
- `admin` - Company admin access
- `company` - Company admin (legacy)
- `employee` - Employee access

**Access Levels:**
- Super Admin: `/admin/super/` - Platform management
- Company Admin: `/admin/` - Company dashboard
- Employees: Homepage - Card generation

### 3. ✅ Company Theme Customization (`/admin/theme.php`)

**Features:**
- Custom primary and secondary colors
- Logo upload
- Favicon upload
- Custom CSS
- Header and footer text
- Theme automatically applied to:
  - Login page
  - Admin dashboard
  - All company pages

**How it works:**
- Each company can have a unique theme
- Theme colors are applied via CSS variables
- Custom CSS allows full branding control
- Logo and favicon displayed on login and admin pages

### 4. ✅ Department Management (`/admin/departments.php`)

**Features:**
- Create departments
- Assign default templates to departments
- Edit department details
- Delete departments
- Employees can be assigned to departments
- Department-specific templates

**Use Cases:**
- Marketing department → Marketing template
- Sales department → Sales template
- IT department → IT template
- Each department can have its own design

### 5. ✅ Shareable Design Links (`/admin/share.php`)

**Features:**
- Create shareable links for templates
- Password protection (optional)
- Expiration dates
- Access limits
- Track access count
- Public or private sharing

**Usage:**
1. Go to `/admin/share.php`
2. Select a template
3. Set password (optional)
4. Set expiration (optional)
5. Set max access (optional)
6. Copy and share the link

**Link Format:**
```
https://bc.bhd.om/share/{share_token}
```

### 6. ✅ Print Orders (`/admin/print.php`)

**Features:**
- Create print orders for multiple employees
- Select template for printing
- Set quantity per employee
- Add notes for printer
- Track order status
- Order history

**Workflow:**
1. Select employees to print cards for
2. Choose template
3. Set quantity
4. Add special instructions
5. Create order
6. Order can be sent to print provider (integration ready)

### 7. ✅ Super Admin Panel (`/admin/super/`)

**Features:**
- Platform-wide statistics
- Company management
- User management
- Transaction monitoring
- System settings

**Access:**
- Only users with `super_admin` role
- Full platform control
- Can manage all companies

## Database Schema Updates

### New Tables

1. **users** - Unified user authentication
2. **company_themes** - Company branding
3. **departments** - Department management
4. **design_links** - Shareable template links
5. **print_orders** - Print job management

### Updated Tables

1. **employees** - Added `department_id`
2. **templates** - Added `theme_id`, `department_id`, `is_shared`

## Migration

Run the migration to add new features:

```bash
php database/migrations/002_enhanced_admin.php
```

Or use the installer - it will run automatically during installation.

## Default Super Admin

**Default Credentials:**
- Email: `admin@bhd.om`
- Password: `admin123`

**⚠️ IMPORTANT:** Change this password immediately after installation!

## Admin Dashboard Navigation

The admin dashboard now includes:
- **Employees** - Manage employees
- **Departments** - Manage departments
- **Share Links** - Create shareable links
- **Print** - Create print orders
- **Theme** - Customize company branding
- **Billing** - Manage subscription
- **Generated** - View generated cards
- **Super Admin** (if super admin) - Platform management

## Company Theme Application

When a company has a theme configured:
1. Login page shows company logo and colors
2. Admin dashboard uses company colors
3. All company pages inherit theme
4. Custom CSS allows full control

## Department-Based Templates

1. Create departments in `/admin/departments.php`
2. Assign default template to department
3. Assign employees to departments
4. Employees in department use department template
5. Can override per employee if needed

## Shareable Links Workflow

1. **Admin creates link:**
   - Go to `/admin/share.php`
   - Select template
   - Configure access settings
   - Copy link

2. **Share link:**
   - Send link to stakeholders
   - They can preview design
   - Password protected if set
   - Expires automatically if configured

3. **Access tracking:**
   - View access count
   - See who accessed (future enhancement)
   - Manage active links

## Print Orders Workflow

1. **Create order:**
   - Select employees
   - Choose template
   - Set quantity
   - Add notes

2. **Order management:**
   - View all orders
   - Track status
   - Update status
   - Export for printer (future enhancement)

3. **Integration ready:**
   - Structure ready for print provider API
   - Can integrate with printing services
   - Order tracking built-in

## Authentication Flow

```
User visits /login.php
    ↓
Enters email + password (+ company code if needed)
    ↓
Auth::login() detects user type
    ↓
Super Admin → /admin/super/
Company Admin → /admin/
Employee → Homepage
```

## Security

- ✅ Password hashing (bcrypt)
- ✅ Role-based access control
- ✅ Session management
- ✅ Company data isolation
- ✅ Shareable link password protection
- ✅ Access tracking

## Files Created

- `includes/Auth.php` - Unified authentication
- `login.php` - Unified login page
- `logout.php` - Unified logout
- `admin/super/index.php` - Super admin panel
- `admin/theme.php` - Theme management
- `admin/departments.php` - Department management
- `admin/share.php` - Shareable links
- `admin/print.php` - Print orders
- `share/index.php` - Shareable link viewer
- `database/schema_updates.sql` - Schema updates
- `database/migrations/002_enhanced_admin.php` - Migration script

## Next Steps

1. **Run Migration:**
   ```bash
   php database/migrations/002_enhanced_admin.php
   ```

2. **Change Default Password:**
   - Login as super admin
   - Change password immediately

3. **Configure Company Themes:**
   - Each company can customize branding
   - Upload logos and set colors

4. **Create Departments:**
   - Organize employees by department
   - Assign department templates

5. **Create Shareable Links:**
   - Share designs with stakeholders
   - Track access

6. **Set Up Print Orders:**
   - Create orders for printing
   - Integrate with print providers (future)

---

**All features are production-ready and integrated!** 🎉
