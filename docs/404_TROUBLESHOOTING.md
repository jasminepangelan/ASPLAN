# 404 Error Troubleshooting Guide

## If You're Getting "Not Found" Errors

### Step 1: Identify Which URL Failed
Check your browser's address bar. The URL will tell us what file it's looking for.

### Common 404 Scenarios:

#### Scenario A: Clicking "Admin" Link from Index
- **Expected URL:** `http://localhost/PEAS/admin/login.php`
- **If 404:** Admin login file not found
- **Solution:** File exists, check Apache is running

#### Scenario B: Clicking Sidebar Links in Admin Dashboard
- **Possible Failed URLs:**
  - `http://localhost/PEAS/admin/list_of_students.php` ❌ (Should be `../list_of_students.php`)
  - `http://localhost/PEAS/admin/settings.html` ❌ (Should be `../settings.html`)
  - `http://localhost/PEAS/admin/pending_accounts_old.php` ✅ (This file exists)

#### Scenario C: Login Form Submission
- **Student Login:** Should POST to `login_process.php` (root directory)
- **Admin Login:** Should POST to `admin/login_process.php`

---

## Quick Fixes

### Fix 1: Ensure Apache & MySQL are Running
```powershell
# Open XAMPP Control Panel
# Check that Apache and MySQL have green "Running" status
```

### Fix 2: Verify File Exists
```powershell
# Check if the file exists
Test-Path "c:\xampp\htdocs\PEAS\admin\login.php"
# Should return: True
```

### Fix 3: Clear Browser Cache
- Press `Ctrl + Shift + Delete`
- Clear cached images and files
- Try again

### Fix 4: Check File Permissions
- Right-click the PEAS folder
- Properties → Security
- Ensure "Users" has Read & Execute permissions

---

## Testing Each Component

### Test 1: Direct URL Access
Try accessing each URL directly in your browser:

1. **Main Page:**
   ```
   http://localhost/PEAS/
   ```
   ✅ Should show student login page

2. **Admin Login:**
   ```
   http://localhost/PEAS/admin/login.php
   ```
   ✅ Should show admin login form

3. **Admin Dashboard (will fail if not logged in):**
   ```
   http://localhost/PEAS/admin/index.php
   ```
   ↩️ Should redirect to admin/login.php

### Test 2: Navigation Flow
1. Go to `http://localhost/PEAS/`
2. Click "Admin" link (bottom left)
3. **Expected:** Redirects to `http://localhost/PEAS/admin/login.php`
4. Login with admin credentials
5. **Expected:** Redirects to `http://localhost/PEAS/admin/index.php`

---

## What to Report

If you're still getting 404 errors, please provide:

1. **The exact URL** shown in browser address bar
2. **What you clicked** to trigger the error
3. **The error message** (copy/paste)

Example:
```
URL: http://localhost/PEAS/admin/list_of_students.php
Clicked: "List of Students" in admin sidebar
Error: 404 Not Found
```

---

## Files That Should Exist

### In `/admin/` folder:
- ✅ `index.php` (admin dashboard)
- ✅ `login.php` (admin login page)
- ✅ `login_process.php` (handles admin login)
- ✅ `logout.php` (handles logout)
- ✅ `pending_accounts.php`
- ✅ `account_management.php`
- ✅ `approve_account.php`
- ✅ `reject_account.php`
- ✅ `input_form.html`

### Still in ROOT folder (not moved yet):
- ✅ `login_process.php` (student login handler)
- ✅ `adviser_login.php`
- ✅ `adviser_login_process.php`
- ✅ `list_of_students.php`
- ✅ `settings.html`
- ✅ `index.html`

---

## Next Steps

1. **Identify the exact 404 URL**
2. **Tell me what you clicked**
3. **I'll fix the specific path issue**

The 404 is likely caused by a link in the admin dashboard pointing to a file that:
- Hasn't been moved yet (like `list_of_students.php` - still in root)
- Has the wrong path (missing `../` prefix)
- Doesn't exist

Once you tell me the specific URL, I can fix it immediately! 🔧
