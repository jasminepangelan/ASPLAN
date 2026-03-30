# 🧪 PEAS Testing Checklist - Admin & Adviser Modules

**Date**: October 18, 2025  
**Scope**: Phase 1 (Admin) & Phase 2 (Adviser) Verification  
**Purpose**: Ensure all features work before Phase 3

---

## 🎯 TESTING OVERVIEW

### What We're Testing:
1. ✅ Admin Module (12 files)
2. ✅ Adviser Module (11 files)
3. ✅ Shared features (pre-enrollment form)
4. ✅ Image loading
5. ✅ Database connections
6. ✅ Navigation flows

---

## 📋 ADMIN MODULE TESTING

### Test 1: Admin Login Flow ✅
**URL**: `http://localhost/PEAS/admin/login.php`

**Steps**:
1. [ ] Open admin login page
2. [ ] Verify CvSU logo displays (favicon and header)
3. [ ] Verify background image loads
4. [ ] Enter admin credentials
5. [ ] Click "Login"
6. [ ] Verify redirect to `admin/index.php`

**Expected Result**: Successful login, dashboard displays

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 2: Admin Dashboard Navigation ✅
**URL**: `http://localhost/PEAS/admin/index.php`

**Steps**:
1. [ ] Verify header displays with admin name
2. [ ] Verify all sidebar icons load
3. [ ] Click "Pending Accounts" → Should go to `admin/pending_accounts.php`
4. [ ] Click "Account Management" → Should go to `admin/account_management.php`
5. [ ] Click "List of Students" → Should open student list
6. [ ] Click "Dashboard" → Should return to `admin/index.php`

**Expected Result**: All navigation works, no 404 errors

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 3: Admin Account Management ✅
**URL**: `http://localhost/PEAS/admin/account_management.php`

**Steps**:
1. [ ] Page loads without errors
2. [ ] Student list displays
3. [ ] Search function works
4. [ ] Click on a student → Should show student details
5. [ ] Verify student picture displays (if available)
6. [ ] Try editing student info (optional)

**Expected Result**: Student data loads correctly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 4: Admin Pending Accounts ✅
**URL**: `http://localhost/PEAS/admin/pending_accounts.php`

**Steps**:
1. [ ] Page loads without errors
2. [ ] Pending accounts list displays (if any)
3. [ ] All images load correctly
4. [ ] Approve/Reject buttons visible (if pending accounts exist)

**Expected Result**: Pending accounts page displays correctly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 5: Admin Picture Upload ✅
**URL**: `http://localhost/PEAS/admin/account_management.php`

**Steps**:
1. [ ] Open a student profile
2. [ ] Click picture upload area
3. [ ] Select an image file
4. [ ] Click "Save" or "Update"
5. [ ] Verify picture uploads successfully
6. [ ] Refresh page → Picture should display

**Expected Result**: Picture uploads and displays correctly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 6: Admin Logout ✅
**URL**: From any admin page

**Steps**:
1. [ ] Click "Sign Out" in sidebar
2. [ ] Should redirect to `admin/login.php`
3. [ ] Try accessing `admin/index.php` directly
4. [ ] Should redirect back to login (not logged in)

**Expected Result**: Logout works, session cleared

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

## 📋 ADVISER MODULE TESTING

### Test 7: Adviser Login Flow ✅
**URL**: `http://localhost/PEAS/index.html` → Select "Adviser"

**Steps**:
1. [ ] Open main page `http://localhost/PEAS/index.html`
2. [ ] Click on login area or role selector
3. [ ] Select "Adviser" role
4. [ ] Should redirect to `adviser/login.php`
5. [ ] Verify page loads with logo and form
6. [ ] Enter adviser credentials
7. [ ] Click "Login"
8. [ ] Should redirect to `adviser/index.php`

**Expected Result**: Successful login, adviser dashboard displays

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 8: Adviser Dashboard Navigation ✅
**URL**: `http://localhost/PEAS/adviser/index.php`

**Steps**:
1. [ ] Verify adviser name displays in header
2. [ ] Verify all sidebar icons load correctly
3. [ ] Click "Pending Accounts" → `adviser/pending_accounts.php`
4. [ ] Click "List of Students" → `adviser/checklist_eval.php`
5. [ ] Click "Dashboard" → Return to `adviser/index.php`
6. [ ] All images load without 404 errors

**Expected Result**: All navigation works smoothly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 9: Adviser Pending Accounts ✅
**URL**: `http://localhost/PEAS/adviser/pending_accounts.php`

**Steps**:
1. [ ] Page loads without PHP errors
2. [ ] Config connection works (no database errors)
3. [ ] Pending students list displays (if any)
4. [ ] Images and icons load correctly
5. [ ] Approve button visible (if students pending)
6. [ ] Reject button visible (if students pending)

**Expected Result**: Pending accounts page works

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 10: Adviser Student List ✅
**URL**: `http://localhost/PEAS/adviser/checklist_eval.php`

**Steps**:
1. [ ] Page loads successfully
2. [ ] Student list displays
3. [ ] For each student, verify buttons:
   - [ ] "Grades" button → Should go to `adviser/checklist.php?student_id=XXX`
   - [ ] "Form" button → Should go to `../pre_enroll.php?student_id=XXX`
   - [ ] "Profile" button → Should go to `adviser/account_management.php?student_id=XXX`
4. [ ] All buttons work without 404 errors

**Expected Result**: Student list and action buttons work

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 11: Adviser Checklist (Grades) ✅
**URL**: Click "Grades" from student list

**Steps**:
1. [ ] Checklist page loads for selected student
2. [ ] Student info displays at top
3. [ ] Course list displays
4. [ ] Grade input fields are editable
5. [ ] Click "Save" button
6. [ ] Verify save operation works (check for success message)
7. [ ] Click "Back" button
8. [ ] Should return to `adviser/checklist_eval.php`

**Expected Result**: Checklist loads, saves work, back button works

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 12: Pre-Enrollment Form ✅
**URL**: Click "Form" button from student list

**Steps**:
1. [ ] Pre-enrollment form loads
2. [ ] No database connection errors
3. [ ] Student details display correctly
4. [ ] Subject selection works
5. [ ] Form is interactive
6. [ ] Click "Back" button
7. [ ] Should return to adviser student list

**Expected Result**: Form works, no errors, back button works

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 13: Adviser Account Management ✅
**URL**: Click "Profile" from student list

**Steps**:
1. [ ] Student profile page loads
2. [ ] All student information displays
3. [ ] Images/icons load correctly
4. [ ] Navigation sidebar works
5. [ ] Can view student details

**Expected Result**: Profile displays correctly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 14: Adviser Sign Out ✅
**URL**: From any adviser page

**Steps**:
1. [ ] Click "Sign Out" in sidebar
2. [ ] Should redirect to `adviser/login.php`
3. [ ] Try accessing `adviser/index.php` directly
4. [ ] Should redirect back to login (session expired)

**Expected Result**: Logout works, session cleared

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

## 📋 CROSS-MODULE TESTING

### Test 15: Image Loading ✅

**Check These Paths**:
1. [ ] `/img/cav.png` - Logo loads on all pages
2. [ ] `/pix/home1.png` - Home icon in sidebars
3. [ ] `/pix/pending.png` - Pending icon
4. [ ] `/pix/checklist.png` - Checklist icon
5. [ ] `/pix/singout.png` - Sign out icon
6. [ ] `/pix/school.jpg` - Background images
7. [ ] `/uploads/` - Student pictures (if any)

**Expected Result**: All images load, no broken image icons

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 16: Database Connections ✅

**Check**:
1. [ ] Admin login - Database query works
2. [ ] Adviser login - Database query works
3. [ ] Student list loads - Data fetched successfully
4. [ ] Pending accounts - Data fetched successfully
5. [ ] No "undefined variable" warnings
6. [ ] No "mysqli" connection errors
7. [ ] All pages use centralized config

**Expected Result**: All database operations work smoothly

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 17: PHP Error Check ✅

**Check Browser Console and PHP Logs**:
1. [ ] No PHP warnings displayed on pages
2. [ ] No "undefined variable" errors
3. [ ] No "deprecated" warnings
4. [ ] No "failed to open stream" errors
5. [ ] Clean execution - no visible errors

**Expected Result**: No PHP errors anywhere

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

## 🔍 BROWSER DEVELOPER TOOLS CHECK

### Test 18: Network Tab Inspection ✅

**Steps**:
1. [ ] Open browser DevTools (F12)
2. [ ] Go to Network tab
3. [ ] Reload admin/adviser pages
4. [ ] Check for:
   - [ ] No 404 errors (missing files)
   - [ ] No 500 errors (server errors)
   - [ ] All CSS/JS files load
   - [ ] All images load (200 status)
   - [ ] AJAX calls succeed

**Expected Result**: All resources load successfully (200 status codes)

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

### Test 19: Console Tab Inspection ✅

**Steps**:
1. [ ] Open browser DevTools (F12)
2. [ ] Go to Console tab
3. [ ] Check for:
   - [ ] No JavaScript errors
   - [ ] No "Failed to load resource" messages
   - [ ] No CORS errors
   - [ ] Clean console output

**Expected Result**: No errors in browser console

**Status**: ⬜ Not Tested | ✅ Pass | ❌ Fail

---

## 📊 TESTING SUMMARY

### Admin Module Tests (6 tests)
- [ ] Test 1: Admin Login
- [ ] Test 2: Admin Navigation
- [ ] Test 3: Account Management
- [ ] Test 4: Pending Accounts
- [ ] Test 5: Picture Upload
- [ ] Test 6: Admin Logout

**Status**: ___/6 Passed

---

### Adviser Module Tests (8 tests)
- [ ] Test 7: Adviser Login
- [ ] Test 8: Adviser Navigation
- [ ] Test 9: Pending Accounts
- [ ] Test 10: Student List
- [ ] Test 11: Checklist/Grades
- [ ] Test 12: Pre-Enrollment Form
- [ ] Test 13: Account Management
- [ ] Test 14: Adviser Logout

**Status**: ___/8 Passed

---

### Cross-Module Tests (5 tests)
- [ ] Test 15: Image Loading
- [ ] Test 16: Database Connections
- [ ] Test 17: PHP Error Check
- [ ] Test 18: Network Tab
- [ ] Test 19: Console Tab

**Status**: ___/5 Passed

---

## 🐛 ISSUES FOUND

### Critical Issues:
_(None expected - document any found here)_

1. 

---

### Minor Issues:
_(Document any non-blocking issues)_

1. 

---

### Suggestions for Improvement:
_(Note any UI/UX improvements)_

1. 

---

## ✅ FINAL VERIFICATION

Once all tests pass:
- [ ] All 19 tests completed
- [ ] No critical issues found
- [ ] All navigation works
- [ ] All images load
- [ ] No PHP/JS errors
- [ ] Database connections stable
- [ ] Ready for Phase 3

---

## 🚀 AFTER TESTING

### If All Tests Pass ✅:
**Recommendation**: Proceed to Phase 3 (Student Module)

### If Issues Found ❌:
**Action**: Document issues and fix before Phase 3

---

## 📝 TESTING NOTES

**Browser Used**: ____________  
**PHP Version**: 8.1.25  
**Testing Date**: ____________  
**Tester**: ____________  

**Additional Notes**:
_____________________________________________
_____________________________________________
_____________________________________________

---

**Ready to start testing? Let me know which test you'd like to start with, or if you want me to guide you through the process step by step!** 🧪
