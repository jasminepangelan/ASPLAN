# Authentication Files - Reference Map

**Created:** October 18, 2025  
**Purpose:** Track all references before moving auth files to `/auth/` folder

---

## Files to Move

### From Root → `/auth/`
1. `login_process.php` - Student login handler
2. `forgot_password.php` - Password reset request
3. `reset_password.php` - Password reset form
4. `reset_password_new.php` - Alternative reset
5. `change_password.php` - Change password handler
6. `signout.php` - Logout handler
7. `verify_code.php` - Email verification code
8. `final_verification.php` - Email verification

---

## Reference Analysis

### 1. `login_process.php` (Student Login)
**Current Location:** `/login_process.php`  
**New Location:** `/auth/login_process.php`  

**Referenced By:**
- ✅ `index.html` line 1462: `fetch('login_process.php', {`
  - **Update to:** `fetch('auth/login_process.php', {`

**NOTE:** Admin and Adviser have their OWN login_process.php files:
- `/admin/login_process.php` - NO CHANGE NEEDED
- `/adviser/login_process.php` - NO CHANGE NEEDED

---

### 2. `forgot_password.php`
**Current Location:** `/forgot_password.php`  
**New Location:** `/auth/forgot_password.php`  

**Referenced By:**
- ✅ `index.html` line 925: `fetch('forgot_password.php', {`
  - **Update to:** `fetch('auth/forgot_password.php', {`
  
- ✅ `adviser/login.php` line 169: `<a href="../forgot_password.php">`
  - **Update to:** `<a href="../auth/forgot_password.php">`

---

### 3. `reset_password.php`
**Current Location:** `/reset_password.php`  
**New Location:** `/auth/reset_password.php`  

**Referenced By:**
- ✅ `index.html` line 1085: `fetch('reset_password.php', {`
  - **Update to:** `fetch('auth/reset_password.php', {`

**NOTE:** Admin has its own reset_password.php:
- `/admin/reset_password.php` - NO CHANGE NEEDED

---

### 4. `verify_code.php`
**Current Location:** `/verify_code.php`  
**New Location:** `/auth/verify_code.php`  

**Referenced By:**
- ✅ `index.html` line 987: `fetch('verify_code.php', {`
  - **Update to:** `fetch('auth/verify_code.php', {`

---

### 5. `change_password.php`
**Current Location:** `/change_password.php`  
**New Location:** `/auth/change_password.php`  

**Referenced By:**
- ✅ `student/acc_mng.php` line 731: `fetch("../change_password.php", {`
  - **Update to:** `fetch("../auth/change_password.php", {`

**Check if admin/adviser use it too:**
- Need to search admin and adviser folders

---

### 6. `signout.php`
**Current Location:** `/signout.php`  
**New Location:** `/auth/signout.php`  

**Referenced By:**
- ✅ `student/home_page_student.php` line 364: `<a href="../signout.php">`
  - **Update to:** `<a href="../auth/signout.php">`
  
- ✅ `student/home_page_student.php` line 390: `<a href="../signout.php"`
  - **Update to:** `<a href="../auth/signout.php"`
  
- ✅ `student/checklist_stud.php` line 429: `<a href="../signout.php">`
  - **Update to:** `<a href="../auth/signout.php">`
  
- ✅ `student/acc_mng.php` line 638: `<a href="../signout.php">`
  - **Update to:** `<a href="../auth/signout.php">`

**Check if admin/adviser use it too:**
- Need to search admin and adviser folders

---

### 7. `reset_password_new.php`
**Current Location:** `/reset_password_new.php`  
**New Location:** `/auth/reset_password_new.php`  

**Referenced By:**
- (Need to search for references)

---

### 8. `final_verification.php`
**Current Location:** `/final_verification.php`  
**New Location:** `/auth/final_verification.php`  

**Referenced By:**
- (Need to search for references)

---

## Files That Need Path Updates

### Root Files
1. ✅ `index.html` - 4 updates needed

### Student Module
2. ✅ `student/home_page_student.php` - 2 signout.php references
3. ✅ `student/checklist_stud.php` - 1 signout.php reference
4. ✅ `student/acc_mng.php` - 2 references (signout, change_password)

### Adviser Module
5. ✅ `adviser/login.php` - 1 forgot_password.php reference

### Admin Module
6. ⏳ Need to check for signout.php, change_password.php references

---

## Internal References (Auth files calling each other)

These files may reference each other and need internal updates:
- `forgot_password.php` → may redirect to verify_code.php
- `verify_code.php` → may redirect to reset_password.php
- `reset_password.php` → may use other auth utilities
- `change_password.php` → may redirect after success

**Action:** Read each auth file to check internal references

---

## Migration Steps

### Step 1: Create /auth/ folder
```powershell
New-Item -ItemType Directory -Path "c:\xampp\htdocs\PEAS\auth" -Force
```

### Step 2: Move files to /auth/
```powershell
Move-Item "login_process.php" "auth/"
Move-Item "forgot_password.php" "auth/"
Move-Item "reset_password.php" "auth/"
Move-Item "reset_password_new.php" "auth/"
Move-Item "change_password.php" "auth/"
Move-Item "signout.php" "auth/"
Move-Item "verify_code.php" "auth/"
Move-Item "final_verification.php" "auth/"
```

### Step 3: Update external references
1. Update `index.html` (4 references)
2. Update `student/home_page_student.php` (2 references)
3. Update `student/checklist_stud.php` (1 reference)
4. Update `student/acc_mng.php` (2 references)
5. Update `adviser/login.php` (1 reference)
6. Check and update admin module files

### Step 4: Update internal auth file references
1. Read each auth file
2. Update any references to other auth files
3. Update redirect paths

### Step 5: Test
1. Test student login from `index.html`
2. Test forgot password flow
3. Test change password from student dashboard
4. Test logout from student module
5. Test adviser forgot password link
6. Test admin module (if affected)

---

## Estimated Impact

- **Files to Move:** 8 files
- **Files to Update:** ~10 files (estimated)
- **Test Cases:** 6-8 flows to verify

**Risk Level:** MEDIUM
- These are critical authentication files
- Affects all user roles
- Must test thoroughly after changes

