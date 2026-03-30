# Student Module Testing Plan

**Date:** October 18, 2025  
**Phase:** Phase 3 - Student Module Testing  
**Status:** In Progress

---

## Test Environment

- ✅ **Apache:** Running (Port 80)
- ✅ **MySQL:** Running (Port 3306)
- ✅ **Base URL:** http://localhost/PEAS/
- ✅ **Syntax Errors:** None detected

---

## Test Categories

### 1. Student Authentication Flow
### 2. Student Dashboard Navigation
### 3. Student Checklist Features
### 4. Student Profile Management
### 5. Pre-Enrollment System
### 6. Cross-Module Access (Admin/Adviser)
### 7. API Endpoints

---

## Detailed Test Cases

### 🔐 1. Student Authentication Flow

#### Test 1.1: Student Login Redirect
**URL:** http://localhost/PEAS/index.html

**Steps:**
1. Open login page
2. Select "Student" role
3. Enter valid student credentials:
   - Student ID: `[Use existing student ID from database]`
   - Password: `[Use existing password]`
4. Click "Login"

**Expected Result:**
- ✅ Should redirect to: `http://localhost/PEAS/student/home_page_student.php`
- ✅ Session should contain: `$_SESSION['student_id']`
- ✅ Should display student name and profile picture

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 1.2: Session Validation
**URL:** http://localhost/PEAS/student/home_page_student.php (direct access without login)

**Steps:**
1. Clear browser cookies/session
2. Try to access student dashboard directly
3. Should redirect to login page

**Expected Result:**
- ✅ Redirect to: `http://localhost/PEAS/index.html`
- ✅ No access without valid session

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 🏠 2. Student Dashboard Navigation

#### Test 2.1: Dashboard Display
**URL:** http://localhost/PEAS/student/home_page_student.php

**Steps:**
1. Login as student
2. Verify dashboard elements load correctly

**Expected Result:**
- ✅ CvSU logo visible
- ✅ Student name displayed in header
- ✅ Profile picture loaded (from `../uploads/` or default)
- ✅ Background image loaded (`../pix/school.jpg`)
- ✅ Three main options visible:
  - Checklist button
  - Your Profile button
  - Sign Out button

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 2.2: Navigate to Checklist
**URL:** http://localhost/PEAS/student/home_page_student.php

**Steps:**
1. From dashboard, click "My Checklist" in sidebar
2. OR click "Checklist" icon in main content area

**Expected Result:**
- ✅ Navigate to: `http://localhost/PEAS/student/checklist_stud.php`
- ✅ Checklist page loads successfully
- ✅ Student data persists

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 2.3: Navigate to Profile
**URL:** http://localhost/PEAS/student/home_page_student.php

**Steps:**
1. From dashboard, click "My Profile" in sidebar
2. OR click "Your Profile" icon in main content area

**Expected Result:**
- ✅ Navigate to: `http://localhost/PEAS/acc_mng.php`
- ✅ Profile page loads successfully
- ✅ Student data populates form fields

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 2.4: Sign Out
**URL:** http://localhost/PEAS/student/home_page_student.php

**Steps:**
1. From dashboard, click "Sign Out" in sidebar
2. OR click "Sign Out" icon in main content area

**Expected Result:**
- ✅ Navigate to: `http://localhost/PEAS/signout.php`
- ✅ Session destroyed
- ✅ Redirect to login page
- ✅ Cannot access student pages after logout

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 📋 3. Student Checklist Features

#### Test 3.1: View Checklist
**URL:** http://localhost/PEAS/student/checklist_stud.php

**Steps:**
1. Login as student
2. Navigate to checklist page
3. Verify checklist data loads

**Expected Result:**
- ✅ Student information displayed at top
- ✅ Course list loads from database
- ✅ Existing grades displayed (if any)
- ✅ All navigation links work (Home, Profile, Sign Out)
- ✅ Icons load from `../pix/`

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 3.2: Edit Checklist Grades
**URL:** http://localhost/PEAS/student/checklist_stud.php

**Steps:**
1. Open checklist page
2. Enter/modify grades in input fields
3. Click "Save" button

**Expected Result:**
- ✅ POST request sent to: `student/save_checklist_stud.php`
- ✅ Success message displayed
- ✅ Data persists on page reload
- ✅ No errors in browser console

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 3.3: Get Checklist Data (API)
**URL:** http://localhost/PEAS/student/get_checklist_data.php?student_id=[STUDENT_ID]

**Steps:**
1. Open checklist page
2. Open browser DevTools → Network tab
3. Verify fetch request to `get_checklist_data.php`

**Expected Result:**
- ✅ API returns JSON response
- ✅ Response contains: `{"status": "success", "courses": [...]}`
- ✅ Data populates form fields correctly

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 3.4: Back to Dashboard
**URL:** http://localhost/PEAS/student/checklist_stud.php

**Steps:**
1. From checklist page, click "Back" button
2. OR click "Home" in sidebar

**Expected Result:**
- ✅ Navigate back to: `student/home_page_student.php`
- ✅ Dashboard loads successfully

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 👤 4. Student Profile Management

#### Test 4.1: View Profile from Student Dashboard
**URL:** http://localhost/PEAS/acc_mng.php

**Steps:**
1. Login as student
2. Navigate to "My Profile" from dashboard
3. Verify profile data loads

**Expected Result:**
- ✅ Student information displayed in form fields
- ✅ Profile picture visible
- ✅ All fields editable
- ✅ Sidebar links work (Home, Checklist)

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 4.2: Edit Profile Information
**URL:** http://localhost/PEAS/acc_mng.php

**Steps:**
1. Open profile page
2. Modify student information (e.g., contact number, address)
3. Click "Save" button

**Expected Result:**
- ✅ POST request sent to: `student/save_profile.php`
- ✅ Success message: "Profile updated successfully!"
- ✅ Changes persist on page reload
- ✅ No errors in browser console

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 4.3: Upload Profile Picture
**URL:** http://localhost/PEAS/acc_mng.php

**Steps:**
1. Open profile page
2. Click "Choose File" for profile picture
3. Select an image file
4. Click "Save"

**Expected Result:**
- ✅ Image uploads to `/uploads/` folder
- ✅ Database updated with new picture path
- ✅ Profile picture updates immediately
- ✅ Picture persists across sessions

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 4.4: Change Password
**URL:** http://localhost/PEAS/acc_mng.php

**Steps:**
1. Open profile page
2. Enter current password
3. Enter new password twice
4. Click "Change Password"

**Expected Result:**
- ✅ Password hashed and stored in database
- ✅ Success message displayed
- ✅ Can login with new password
- ✅ Cannot login with old password

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 📝 5. Pre-Enrollment System

#### Test 5.1: Access Pre-Enrollment Form
**URL:** http://localhost/PEAS/student/pre_enroll.php

**Steps:**
1. Login as student
2. Navigate to pre-enrollment page (if accessible from dashboard)
3. OR access directly via URL

**Expected Result:**
- ✅ Form loads successfully
- ✅ Student information pre-filled
- ✅ Course selection fields visible
- ✅ All form controls functional

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 5.2: Submit Pre-Enrollment
**URL:** http://localhost/PEAS/student/pre_enroll.php

**Steps:**
1. Open pre-enrollment form
2. Fill in required fields
3. Select courses for enrollment
4. Click "Submit"

**Expected Result:**
- ✅ POST request to: `student/save_pre_enrollment.php`
- ✅ Success message displayed
- ✅ Enrollment record created in database
- ✅ Transaction history updated

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 5.3: Load Pre-Enrollment Data
**URL:** http://localhost/PEAS/student/load_pre_enrollment.php?student_id=[STUDENT_ID]

**Steps:**
1. Submit a pre-enrollment
2. Reload the pre-enrollment form
3. Verify previously submitted data loads

**Expected Result:**
- ✅ API returns saved enrollment data
- ✅ Form fields populate with saved data
- ✅ Can edit and resubmit

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 5.4: View Transaction History
**URL:** http://localhost/PEAS/student/get_transaction_history.php?student_id=[STUDENT_ID]

**Steps:**
1. Open pre-enrollment page
2. Open DevTools → Network tab
3. Verify transaction history API call

**Expected Result:**
- ✅ API returns JSON array of transactions
- ✅ History displays in pre-enrollment form
- ✅ Shows date, status, and enrollment details

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 5.5: View Enrollment Details
**URL:** http://localhost/PEAS/student/get_enrollment_details.php?enrollment_id=[ID]

**Steps:**
1. From transaction history, click on an enrollment
2. Verify details modal/page opens

**Expected Result:**
- ✅ API returns specific enrollment details
- ✅ Shows all courses, grades, and status
- ✅ Data formatted correctly

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 🔗 6. Cross-Module Access (Admin/Adviser)

#### Test 6.1: Admin Edit Student Profile
**URL:** http://localhost/PEAS/admin/account_management.php

**Steps:**
1. Login as admin
2. Navigate to account management
3. Search for and select a student
4. Click "Edit"
5. Modify student information
6. Click "Save"

**Expected Result:**
- ✅ POST request to: `../student/save_profile.php`
- ✅ Student profile updates successfully
- ✅ Changes visible when logging in as that student
- ✅ No permission errors

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 6.2: Adviser View Student Checklist
**URL:** http://localhost/PEAS/adviser/checklist.php?student_id=[STUDENT_ID]

**Steps:**
1. Login as adviser
2. Navigate to checklist evaluation
3. Select a student
4. View their checklist

**Expected Result:**
- ✅ Fetch request to: `../student/get_checklist_data.php`
- ✅ Student checklist data loads
- ✅ Adviser can view/edit grades
- ✅ No errors in console

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 6.3: Adviser Access Pre-Enrollment Form
**URL:** http://localhost/PEAS/adviser/checklist_eval.php

**Steps:**
1. Login as adviser
2. Navigate to checklist evaluation
3. Find student in list
4. Click "Form" button

**Expected Result:**
- ✅ Navigate to: `../student/pre_enroll.php?student_id=[ID]`
- ✅ Student's pre-enrollment form opens
- ✅ Adviser can view student's submissions
- ✅ All data loads correctly

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

### 🔌 7. API Endpoints Testing

#### Test 7.1: GET /student/get_checklist_data.php
**Method:** GET  
**Parameters:** `student_id=[ID]`

**Expected Response:**
```json
{
  "status": "success",
  "courses": [
    {
      "course_code": "CS101",
      "course_name": "Intro to Programming",
      "grade": "1.5",
      ...
    }
  ]
}
```

**Test:**
```powershell
curl http://localhost/PEAS/student/get_checklist_data.php?student_id=[ID]
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.2: POST /student/save_checklist_stud.php
**Method:** POST  
**Content-Type:** application/json

**Expected Response:**
```json
{
  "status": "success",
  "message": "Checklist saved successfully"
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.3: POST /student/save_profile.php
**Method:** POST  
**Content-Type:** multipart/form-data

**Expected Response:**
```json
{
  "status": "success",
  "message": "Profile updated successfully"
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.4: POST /student/save_pre_enrollment.php
**Method:** POST  
**Content-Type:** application/json

**Expected Response:**
```json
{
  "status": "success",
  "enrollment_id": 123,
  "message": "Pre-enrollment saved successfully"
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.5: GET /student/load_pre_enrollment.php
**Method:** GET  
**Parameters:** `student_id=[ID]`

**Expected Response:**
```json
{
  "status": "success",
  "enrollment": {
    "student_id": "2021-12345",
    "courses": [...],
    "submission_date": "2025-10-18"
  }
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.6: GET /student/get_enrollment_details.php
**Method:** GET  
**Parameters:** `enrollment_id=[ID]`

**Expected Response:**
```json
{
  "status": "success",
  "details": {
    "enrollment_id": 123,
    "student_id": "2021-12345",
    "courses": [...],
    "status": "pending"
  }
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

#### Test 7.7: GET /student/get_transaction_history.php
**Method:** GET  
**Parameters:** `student_id=[ID]`

**Expected Response:**
```json
{
  "status": "success",
  "transactions": [
    {
      "id": 1,
      "date": "2025-10-18",
      "status": "approved",
      ...
    }
  ]
}
```

**Actual Result:**
- [ ] Pass / [ ] Fail
- Notes: ___________

---

## Common Issues Checklist

### Path Issues
- [ ] Config files load correctly (`../config/config.php`)
- [ ] Images load from `../img/` and `../pix/`
- [ ] CSS/JS files load correctly
- [ ] Upload folder accessible (`../uploads/`)

### Database Issues
- [ ] All student queries work correctly
- [ ] No missing columns or tables
- [ ] Foreign key constraints respected
- [ ] Data persists across sessions

### Session Issues
- [ ] Student session properly set on login
- [ ] Session persists across page navigation
- [ ] Session destroyed on logout
- [ ] Protected pages redirect when not logged in

### Security Issues
- [ ] SQL injection prevented (prepared statements)
- [ ] XSS prevented (htmlspecialchars)
- [ ] File upload validates file types
- [ ] Password properly hashed

---

## Browser Console Errors

Check browser console (F12) for JavaScript errors:

**Expected:** No errors  
**Actual:**
- [ ] No errors
- [ ] Errors found (list below):

___________

---

## Network Tab Analysis

Check browser DevTools → Network tab:

**Files to verify load successfully:**
- [ ] `student/home_page_student.php` - 200 OK
- [ ] `student/checklist_stud.php` - 200 OK
- [ ] `student/save_profile.php` - 200 OK (on save)
- [ ] `student/get_checklist_data.php` - 200 OK
- [ ] `../img/cav.png` - 200 OK
- [ ] `../pix/school.jpg` - 200 OK
- [ ] `../pix/*.png` (icons) - 200 OK

**Files returning errors (404/500):**
- [ ] None
- [ ] List errors: ___________

---

## Test Summary

**Total Tests:** 38  
**Passed:** ___  
**Failed:** ___  
**Skipped:** ___

**Critical Issues Found:**
1. ___________
2. ___________
3. ___________

**Minor Issues Found:**
1. ___________
2. ___________
3. ___________

**Recommendations:**
1. ___________
2. ___________
3. ___________

---

## Next Steps

- [ ] Fix critical issues
- [ ] Fix minor issues
- [ ] Retest failed cases
- [ ] Document workarounds
- [ ] Update user documentation
- [ ] Proceed to Phase 4

---

**Tester Name:** ___________  
**Test Date:** October 18, 2025  
**Test Duration:** ___________  
**Overall Status:** [ ] Pass / [ ] Fail / [ ] Partial

