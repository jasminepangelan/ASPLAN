# Authentication Migration - Quick Test Guide

**Date:** October 18, 2025  
**Purpose:** Quick reference for testing auth migration

---

## ✅ Files Successfully Moved to `/auth/`

```
auth/
├── login_process.php           ✅
├── forgot_password.php         ✅
├── reset_password.php          ✅
├── reset_password_new.php      ✅
├── change_password.php         ✅
├── signout.php                 ✅
├── verify_code.php             ✅
└── final_verification.php      ✅
```

---

## 🧪 Test Cases (Priority Order)

### 1. Student Login ⚡ HIGH PRIORITY
```
URL: http://localhost/PEAS/index.html
Steps:
1. Enter valid student credentials
2. Click Login
3. Expected: Redirect to student/home_page_student.php
4. Verify: Dashboard loads correctly
```

### 2. Student Logout ⚡ HIGH PRIORITY
```
From: student/home_page_student.php
Steps:
1. Click "Sign Out" in sidebar
2. Expected: Redirect to index.html
3. Try accessing student pages directly
4. Expected: Should redirect to login (session destroyed)
```

### 3. Student Change Password 🔧 MEDIUM PRIORITY
```
From: student/acc_mng.php
Steps:
1. Click "Change Password" button
2. Enter current password
3. Enter new password (and confirm)
4. Click "Change Password" in modal
5. Expected: Success message
6. Logout and login with new password
7. Expected: Login successful
```

### 4. Forgot Password Flow 🔧 MEDIUM PRIORITY
```
From: index.html
Steps:
1. Click "FORGOT PASSWORD?"
2. Enter valid student ID
3. Expected: Code sent to email
4. Enter 4-digit code from email
5. Expected: Password reset form
6. Enter new password (and confirm)
7. Expected: Password reset success
8. Login with new password
9. Expected: Login successful
```

### 5. Adviser Forgot Password Link 📝 LOW PRIORITY
```
URL: http://localhost/PEAS/adviser/login.php
Steps:
1. Click "FORGOT PASSWORD?" link
2. Expected: Redirect to password reset flow
3. Verify: Page loads correctly
```

---

## 🔍 What to Check

### During Testing:
- ✅ No 404 errors in browser console
- ✅ No PHP errors on page
- ✅ Smooth redirects (no broken links)
- ✅ Session data persists correctly
- ✅ Database updates work (password changes, etc.)

### Files That Reference Auth:
- `index.html` - 4 fetch() calls
- `student/home_page_student.php` - 2 signout links
- `student/checklist_stud.php` - 1 signout link
- `student/acc_mng.php` - 2 links (signout, change_password)
- `adviser/login.php` - 1 forgot password link
- `auth/signout.php` - 1 redirect to ../index.html

---

## 🚨 If You Find Issues:

### Common Problems:
1. **404 Not Found**
   - Check: File path in fetch() or href
   - Solution: Verify path has `auth/` prefix

2. **Session Not Destroyed on Logout**
   - Check: signout.php redirect
   - Solution: Verify redirect is `../index.html`

3. **Password Change Fails**
   - Check: Browser console for fetch errors
   - Solution: Verify path is `../auth/change_password.php`

4. **Login Redirect Fails**
   - Check: auth/login_process.php line 71
   - Solution: Verify redirect is `student/home_page_student.php` (relative from /auth/)

---

## ✅ Expected Results

All tests should pass with:
- No errors in browser console
- No PHP warnings/errors
- Smooth navigation between pages
- Correct session handling
- Database updates work properly

---

## 📊 Test Status

| Test Case | Status | Notes |
|-----------|--------|-------|
| Student Login | ⏳ Not Tested | |
| Student Logout | ⏳ Not Tested | |
| Change Password | ⏳ Not Tested | |
| Forgot Password | ⏳ Not Tested | |
| Adviser Link | ⏳ Not Tested | |

**Update this table as you test!**

---

## 🎯 Next After Testing

If all tests pass:
1. ✅ Mark Phase 4 - Part 1 (Auth) as COMPLETE
2. ⏩ Proceed to Part 2: Move documentation to /docs/
3. ⏩ Then Part 3: Move dev/test files to /dev/
4. ⏩ Finally Part 4: Move utilities to /utils/

