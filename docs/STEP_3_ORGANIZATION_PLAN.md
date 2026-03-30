# STEP 3: File Organization Plan

## 🎯 Goal: Transform Flat Structure → Professional Organization

### Current Problem:
```
PEAS/
├── 174+ PHP files (all mixed together!)
├── Test files mixed with production
├── Debug files still present
├── No clear separation of concerns
└── Hard to find anything!
```

### Proposed Structure:
```
PEAS/
├── config/                      ✅ DONE (Already created!)
│   ├── config.php
│   ├── database.php
│   ├── email.php
│   └── app.php
│
├── admin/                       🆕 Create admin area
│   ├── index.php (home_page_admin.php)
│   ├── login.php (admin_login.php)
│   ├── login_process.php
│   ├── pending_accounts.php
│   ├── approve_account.php
│   ├── reject_account.php
│   ├── account_management.php
│   └── logout.php
│
├── adviser/                     🆕 Create adviser area
│   ├── index.php (home_page_adviser.php)
│   ├── login.php (adviser_login.php)
│   ├── login_process.php
│   ├── pending_accounts.php
│   ├── approve_account.php
│   ├── reject_account.php
│   ├── management.php
│   ├── checklist.php
│   ├── checklist_eval.php
│   ├── pre_enroll.php
│   └── logout.php
│
├── student/                     🆕 Create student area
│   ├── index.php (home_page_student.php)
│   ├── login_process.php
│   ├── register.php (student_input_form_1.html)
│   ├── register_process.php
│   ├── profile.php
│   ├── save_profile.php
│   ├── checklist.php
│   ├── save_checklist.php
│   ├── pre_enrollment.php
│   └── save_pre_enrollment.php
│
├── auth/                        🆕 Create auth utilities
│   ├── forgot_password.php
│   ├── verify_code.php
│   ├── reset_password.php
│   ├── change_password.php
│   └── signout.php
│
├── api/                         🆕 API endpoints
│   ├── get_checklist_data.php
│   ├── get_enrollment_details.php
│   ├── get_transaction_history.php
│   ├── fetchPrograms.php
│   ├── savePrograms.php
│   └── load_pre_enrollment.php
│
├── assets/                      🆕 Static resources
│   ├── css/
│   ├── js/
│   ├── img/ (move from root)
│   └── pix/ (move from root)
│
├── uploads/                     ✅ Keep as is
│
├── includes/                    🆕 Reusable components
│   ├── header.php
│   ├── footer.php
│   ├── sidebar.php
│   └── functions.php
│
├── dev/                         🆕 Development files
│   ├── test_*.php (all test files)
│   ├── debug_*.php (all debug files)
│   ├── check_*.php (all check files)
│   └── fix_*.php (all fix files)
│
├── docs/                        🆕 Documentation
│   ├── MIGRATION_REFERENCE.md
│   ├── STEP_1_COMPLETE.md
│   ├── STEP_2_COMPLETE.md
│   ├── BATCH_*.md
│   ├── README.md
│   └── ACCOUNT_APPROVAL_SYSTEM.md
│
├── index.html                   ✅ Keep (main entry point)
├── .gitignore                   ✅ Keep
└── README.md                    ✅ Keep
```

---

## 📋 Migration Strategy:

### Phase 1: Create Folder Structure (Safe - No Risk)
- Create all new directories
- No files moved yet

### Phase 2: Move Files Systematically (One Category at a Time)
1. Admin files → admin/
2. Adviser files → adviser/
3. Student files → student/
4. Auth files → auth/
5. API files → api/
6. Assets → assets/
7. Dev/Test files → dev/
8. Documentation → docs/

### Phase 3: Update Path References
- Update require_once paths in moved files
- Update image/asset paths
- Update form action paths
- Update redirect paths

### Phase 4: Test & Verify
- Test all login flows
- Test all redirects
- Verify all includes work

---

## 🚨 Important Notes:

### Files to Handle Carefully:
- `index.html` - Keep in root (main entry point)
- `connect.php` - Can be deprecated (we use config now)
- Files with hardcoded paths - Need path updates

### Files to Review Before Moving:
- Database migration files
- System initialization files
- Utility scripts

---

## 🎯 Expected Benefits:

1. **Easy Navigation** - Find files in seconds
2. **Clear Separation** - Admin, Student, Adviser areas isolated
3. **Better Security** - Role-based directory access
4. **Professional** - Industry-standard structure
5. **Scalable** - Easy to add new features
6. **Clean Root** - Only essential files visible

---

## 📊 File Count After Organization:

```
Root directory:     5-10 files (vs current 100+)
Admin folder:       10-15 files
Adviser folder:     10-15 files
Student folder:     10-15 files
API folder:         10-15 files
Dev folder:         30-40 files (hidden from production)
Assets folder:      Organized by type
```

---

## ⚠️ Risks & Mitigation:

### Risk: Broken paths after moving files
**Mitigation:** 
- Update paths systematically
- Test after each category
- Keep backup before moving

### Risk: Include/require errors
**Mitigation:**
- Use `__DIR__` for relative paths
- Update config includes
- Test thoroughly

### Risk: Redirects to wrong locations
**Mitigation:**
- Search & replace redirect paths
- Update form actions
- Test all user flows

---

## 🎯 Ready to Start?

I'll create the folder structure first (safe, no risk), then we'll move files category by category.

**Shall I proceed?**
