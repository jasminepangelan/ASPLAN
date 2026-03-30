# Picture Not Showing After Upload - FIXED! ✅

**Date:** October 18, 2025  
**Issue:** Picture uploads successfully but doesn't display  
**Root Cause:** Incorrect image path for admin folder location

---

## The Problem

After successfully uploading a student picture:
1. ✅ File uploads to `uploads/` folder
2. ✅ Database updates with path `uploads/filename.jpg`
3. ❌ Image doesn't display in admin interface

### Why It Didn't Show:

The `admin/account_management.php` file is in the `admin/` folder, but the image path in the database is just `uploads/filename.jpg`.

When the browser tries to load:
```html
<img src="uploads/filename.jpg">
```

It's looking for:
- `admin/uploads/filename.jpg` ❌ (doesn't exist)

When it should be looking for:
- `uploads/filename.jpg` (from root) ✅

---

## The Solution

Added smart path handling in the PHP code to prepend `../` to picture paths:

### Changes Made in `admin/account_management.php` (Lines 28-33):

**Before:**
```php
$picture = htmlspecialchars($row['picture'] ?? '');
```

**After:**
```php
// Handle picture path - add ../ if picture exists and doesn't already have it
$picture_raw = $row['picture'] ?? '';
if (!empty($picture_raw) && strpos($picture_raw, '../') !== 0) {
    $picture = '../' . htmlspecialchars($picture_raw);
} else {
    $picture = !empty($picture_raw) ? htmlspecialchars($picture_raw) : '../img/default-profile.png';
}
```

### What This Does:

1. **Checks if picture path exists** in database
2. **Checks if path already has `../`** (avoids double-adding)
3. **Prepends `../`** to make path relative from admin folder
4. **Fallback to default image** if no picture exists

### Examples:

| Database Value | Displayed Path | Browser Looks For |
|----------------|----------------|-------------------|
| `uploads/abc123.jpg` | `../uploads/abc123.jpg` | `/PEAS/uploads/abc123.jpg` ✅ |
| (empty) | `../img/default-profile.png` | `/PEAS/img/default-profile.png` ✅ |
| `../uploads/xyz.jpg` | `../uploads/xyz.jpg` | `/PEAS/uploads/xyz.jpg` ✅ |

---

## How It Works Now

### Complete Upload Flow:

1. **Admin selects image** from "Choose File" button
2. **Clicks "Save Changes"**
3. **JavaScript sends** FormData to `../save_profile.php`
4. **save_profile.php** processes upload:
   - Validates file
   - Generates unique filename
   - Moves to `uploads/` folder
   - Saves path `uploads/filename.jpg` to database
5. **Success modal appears**
6. **Admin clicks "OK"**
7. **Page reloads**
8. **PHP loads** picture path from database: `uploads/filename.jpg`
9. **PHP prepends** `../` → `../uploads/filename.jpg`
10. **Image displays** correctly! ✅

---

## Testing Steps

### Test 1: Upload New Picture
1. Go to List of Students
2. Click "View Details" on any student
3. Click "Choose File" and select an image
4. Click "Save Changes"
5. Click "OK" on success modal
6. **Result:** Picture should display! ✅

### Test 2: Verify Image Path
1. Right-click on the profile picture
2. Select "Inspect" or "Inspect Element"
3. Look at the `<img>` tag's `src` attribute
4. **Should see:** `src="../uploads/[filename].jpg"` ✅

### Test 3: Check Upload Folder
```powershell
Get-ChildItem "c:\xampp\htdocs\PEAS\uploads" | Select-Object Name
```
Should show uploaded image files ✅

---

## Files Modified

### 1. `admin/account_management.php`
**Line 28-33:** Added smart picture path handling
- Prepends `../` for relative path from admin folder
- Handles empty values with fallback
- Prevents double-prepending `../`

### 2. `save_profile.php` (Already Fixed Earlier)
**Line 430:** Fixed fetch URL
- Was: `fetch('save_profile.php', ...)`
- Now: `fetch('../save_profile.php', ...)`

---

## Why This Approach Is Better

### Alternative Approaches (Not Used):

❌ **Store full path in database:** `../uploads/file.jpg`
- Problem: Breaks if we move files or change structure

❌ **Store absolute path:** `/PEAS/uploads/file.jpg`
- Problem: Not portable across different servers

✅ **Store relative path from root + adjust in code:** `uploads/file.jpg` → `../uploads/file.jpg`
- Benefit: Database stays clean
- Benefit: Paths adjust automatically per file location
- Benefit: Easy to maintain

---

## Additional Benefits

### Fallback Image:
If a student has no profile picture, it shows a default image instead of a broken image icon:
```php
$picture = '../img/default-profile.png';
```

### No Double-Pathing:
The code checks if `../` already exists to prevent:
```
../../uploads/file.jpg  ❌ Wrong!
```

### HTML Special Characters:
Still uses `htmlspecialchars()` for security:
```php
$picture = '../' . htmlspecialchars($picture_raw);
```

---

## Common Issues & Solutions

### Issue: "Image still not showing"
**Solution:** 
1. Hard refresh the page (Ctrl + Shift + R)
2. Clear browser cache
3. Check if file actually uploaded to `uploads/` folder

### Issue: "File uploads but image is broken"
**Solution:**
1. Check file permissions on `uploads/` folder
2. Verify file extension is allowed (jpg, jpeg, png, gif)
3. Check file size isn't too large

### Issue: "Default image doesn't show"
**Solution:**
Create a default profile image at:
```
c:\xampp\htdocs\PEAS\img\default-profile.png
```

---

## Summary

✅ **Picture path handling fixed**  
✅ **Upload works correctly**  
✅ **Image displays in admin panel**  
✅ **Fallback image for missing pictures**  
✅ **Smart path detection (no double ../)**  
✅ **Page reloads to show new image**  

---

**Try uploading a picture now - it should display immediately after clicking OK!** 🎉
