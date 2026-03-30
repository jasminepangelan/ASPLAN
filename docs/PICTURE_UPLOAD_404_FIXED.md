# 404 Error Fixed - Student Picture Upload ✅

**Date:** October 18, 2025  
**Issue:** 404 Not Found when trying to change/add student picture  
**Location:** `admin/account_management.php` (Edit Student Profile page)

---

## The Problem

When an admin tried to upload or change a student's picture, the form submission failed with a **404 Not Found** error.

### Root Cause:
The JavaScript fetch request was looking for `save_profile.php` in the wrong location:

```javascript
// WRONG - Looking in admin/ folder
fetch('save_profile.php', { ... })
```

Since the page is located at `admin/account_management.php`, the browser tried to fetch:
- `http://localhost/PEAS/admin/save_profile.php` ❌ (doesn't exist)

But the actual file is at:
- `http://localhost/PEAS/save_profile.php` ✅ (root directory)

---

## The Fix

Updated the fetch URL to use a relative path that goes up one directory:

```javascript
// CORRECT - Looking in root folder
fetch('../save_profile.php', { ... })
```

### Changed in: `admin/account_management.php` (Line 430)

**Before:**
```javascript
fetch('save_profile.php', {
  method: 'POST',
  body: formData
})
```

**After:**
```javascript
fetch('../save_profile.php', {
  method: 'POST',
  body: formData
})
```

---

## What This Fixes

✅ **Picture Upload** - Can now upload student profile pictures  
✅ **Profile Editing** - Can edit any student field (name, email, etc.)  
✅ **Admin Management** - Admins can update student information  
✅ **Form Submission** - No more 404 errors  

---

## How It Works Now

### Student Profile Update Flow:

1. **Admin opens** student profile from list of students
2. **Page loads** at: `admin/account_management.php?student_id=XXX`
3. **Admin edits** fields (name, picture, contact, etc.)
4. **Clicks "Save Changes"**
5. **JavaScript fetches** `../save_profile.php` (goes up to root)
6. **save_profile.php** processes the update:
   - Validates data
   - Handles file upload (picture)
   - Updates database
   - Returns JSON response
7. **Success modal** shows if update succeeds

---

## Files Involved

### 1. `admin/account_management.php`
- **Role:** Display student profile edit form
- **Fixed:** Fetch URL to save_profile.php
- **Location:** `/admin/` folder

### 2. `save_profile.php`
- **Role:** Process form data and update database
- **Status:** Already using config system ✅
- **Location:** Root folder (`/`)

---

## Testing Checklist

Now test the student profile editing:

### Test 1: Picture Upload
- [ ] Go to List of Students
- [ ] Click "View Details" on any student
- [ ] Click "Choose File" and select an image
- [ ] Click "Save Changes"
- [ ] Should show success modal ✅
- [ ] Picture should update ✅

### Test 2: Edit Other Fields
- [ ] Change student's last name
- [ ] Change email address
- [ ] Change contact number
- [ ] Click "Save Changes"
- [ ] Should show success modal ✅
- [ ] Data should update in database ✅

### Test 3: Verify No Errors
- [ ] Open browser console (F12)
- [ ] Perform an edit
- [ ] Should see NO 404 errors ✅
- [ ] Should see successful fetch response ✅

---

## Related Path Issues (All Fixed Now)

This was another consequence of moving files to the `admin/` folder without updating all paths. Similar issues we've already fixed:

1. ✅ Config includes (`../config/config.php`)
2. ✅ Image paths (`../img/`, `../pix/`)
3. ✅ Navigation links (`index.php`, `logout.php`)
4. ✅ API endpoints (`../save_profile.php`) ← **Just fixed**

---

## Upload Directory

Make sure the upload directory exists and has proper permissions:

```
PEAS/
├── uploads/              ← Student pictures saved here
│   └── students/         ← Student profile pictures
```

If uploads fail, check:
1. **Directory exists:** `c:\xampp\htdocs\PEAS\uploads\students\`
2. **Permissions:** Folder should be writable
3. **File size limits:** Check `php.ini` for `upload_max_filesize`

---

## Additional Notes

### File Upload Handling:
The `save_profile.php` script:
- Accepts `multipart/form-data` (for file uploads)
- Validates file types (images only)
- Sanitizes filenames
- Moves uploaded files to `uploads/students/`
- Updates database with new picture path
- Returns JSON response with success/error

### Security:
- ✅ Uses prepared statements
- ✅ Sanitizes user input with `htmlspecialchars()`
- ✅ Validates file types
- ✅ Checks admin session

---

**Picture upload should now work perfectly!** 🎉

Try uploading a student picture again - the 404 error should be gone!
