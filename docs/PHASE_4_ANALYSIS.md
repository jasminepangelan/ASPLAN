# Phase 4: Root Directory Analysis

**Date:** October 18, 2025  
**Current Root Files Count:** 70+ files

---

## File Categorization

### 🔐 Authentication Files (7 files) → Move to `/auth/`
- `login_process.php` - Main login handler
- `forgot_password.php` - Password reset request
- `reset_password.php` - Password reset form
- `reset_password_new.php` - Alternative reset
- `change_password.php` - Change password handler
- `signout.php` - Logout handler
- `verify_code.php` - Email verification

### 🔧 Utility Files (4 files) → Move to `/utils/`
- `admin_connection.php` - Admin auth check (consider renaming)
- `adviser_connection.php` - Adviser auth check (consider renaming)
- `name_utils.php` - Name formatting utilities
- `connect.php` - Legacy DB connection (DEPRECATED, use config/database.php)

### 📝 HTML Entry Forms (7 files) → Keep in Root OR Move to `/forms/`
- `index.html` - Main login page (KEEP IN ROOT)
- `student_input_form_1.html` - Student registration step 1
- `student_input_form_2.html` - Student registration step 2
- `adviser_input_form.html` - Adviser registration
- `prog_year_select.html` - Program/year selection
- `Curriculum.html` - Curriculum display
- `Archive_rec.html` - Archive records

### 📄 HTML Pages (3 files) → Evaluate usage
- `settings.html` - System settings page
- `system_dashboard.html` - System dashboard
- `home_page_admin.php` - Admin home (DUPLICATE? Check admin/index.php)

### 🎨 Assets (2 items) → Already Organized
- `img/` - Images folder ✅
- `pix/` - Icons folder ✅
- `uploads/` - User uploads ✅

### 🔨 Processing/Handler Files (7 files) → Evaluate
- `student_input_process.php` - Student registration handler (move to /student/?)
- `approve_account_admin.php` - Admin approval handler (move to /admin/?)
- `fetchPrograms.php` - Fetch programs API (move to /api/?)
- `savePrograms.php` - Save programs API (move to /api/?)
- `save_checklist.php` - Save checklist (which role? move to appropriate folder)
- `final_verification.php` - Email verification
- `init_account_system.php` - Account system initialization

### 🧪 Test Files (7 files) → Move to `/dev/test/` or Delete
- `test_batch_assignment.php`
- `test_db.php`
- `test_middle_name_handling.php`
- `test_pending_removal.php`
- `test_registration.php`
- `system_test.php`
- `debug_save_checklist_stud.log`

### 🐛 Debug Files (6 files) → Move to `/dev/debug/` or Delete
- `debug_db_structure.php`
- `debug_form.php`
- `debug_profile_update.php`
- `debug_student_process.php`
- `debug_subjects.php`

### 🔧 Fix/Migration Scripts (11 files) → Move to `/dev/scripts/`
- `batch_migrate.php`
- `batch_update.php`
- `fix_batch_constraints.php`
- `fix_constraint.php`
- `fix_constraints_v2.php`
- `fix_email_column.php`
- `update_middle_name_column.php`
- `migration_helper.php`

### ✅ Check/Validation Scripts (9 files) → Move to `/dev/scripts/`
- `check_courses.php`
- `check_data.php`
- `check_db_structure.php`
- `check_student_email.php`
- `check_student_id.php`
- `check_student_status.php`
- `check_system_settings.php`
- `check_table_columns.php`
- `check_table_structure.php`

### 📚 Documentation Files (11 files) → Already in `/docs/` OR Move There
- `ACCOUNT_APPROVAL_SYSTEM.md` → Move to /docs/
- `BATCH_2_COMPLETE.md` → Move to /docs/
- `BATCH_3_COMPLETE.md` → Move to /docs/
- `MIDDLE_NAME_SOLUTION.md` → Move to /docs/
- `MIGRATION_COMPLETE.md` → Move to /docs/
- `MIGRATION_REFERENCE.md` → Move to /docs/
- `MOVE_FILES_SCRIPT.md` → Move to /docs/
- `NEW_STRUCTURE_README.md` → Move to /docs/
- `STEP_1_COMPLETE.md` → Move to /docs/
- `STEP_2_COMPLETE.md` → Move to /docs/
- `STEP_3_ORGANIZATION_PLAN.md` → Move to /docs/

### 📦 Special Files (4 files) → Review Usage
- `PHPMailerAutoload.php` - PHPMailer autoloader (KEEP IN ROOT)
- `sample_student_import.csv` - Sample data (move to /docs/samples/)
- `registration_fix_summary.html` - Fix summary (move to /docs/)
- `MySQL Local.session.sql` - SQL session file (move to /dev/)

### ✅ Already Organized Folders
- `/admin/` - Admin module ✅
- `/adviser/` - Adviser module ✅
- `/student/` - Student module ✅
- `/config/` - Configuration ✅
- `/docs/` - Documentation (partial) ✅
- `/api/` - API endpoints (exists but may need files)
- `/auth/` - Empty, will populate
- `/includes/` - May have some files
- `/assets/` - May have some files
- `/src/` - Check contents
- `/dev/` - Check contents

---

## Proposed New Structure

```
PEAS/
├── /admin/          ✅ Organized (18 files)
├── /adviser/        ✅ Organized (11 files)
├── /student/        ✅ Organized (12 files)
├── /config/         ✅ Organized (4 files)
│
├── /auth/           🔄 CREATE & POPULATE (7 files)
│   ├── login_process.php
│   ├── forgot_password.php
│   ├── reset_password.php
│   ├── change_password.php
│   ├── signout.php
│   ├── verify_code.php
│   └── final_verification.php
│
├── /utils/          🔄 CREATE & POPULATE (4 files)
│   ├── admin_connection.php (or auth_admin.php)
│   ├── adviser_connection.php (or auth_adviser.php)
│   ├── name_utils.php
│   └── connect.php (DEPRECATED - mark for removal)
│
├── /forms/          🔄 CONSIDER CREATING (6 files)
│   ├── student_input_form_1.html
│   ├── student_input_form_2.html
│   ├── adviser_input_form.html
│   ├── prog_year_select.html
│   ├── Curriculum.html
│   └── Archive_rec.html
│
├── /api/            🔄 POPULATE (3+ files)
│   ├── fetchPrograms.php
│   ├── savePrograms.php
│   └── (other API endpoints)
│
├── /docs/           🔄 CONSOLIDATE (20+ files)
│   ├── /samples/
│   │   └── sample_student_import.csv
│   ├── PHASE_3_FINAL_STATUS.md
│   └── (all .md documentation files)
│
├── /dev/            🔄 ORGANIZE (30+ files)
│   ├── /test/       (test_*.php files)
│   ├── /debug/      (debug_*.php files)
│   ├── /scripts/    (fix_*.php, check_*.php, batch_*.php)
│   └── MySQL Local.session.sql
│
├── /img/            ✅ Images
├── /pix/            ✅ Icons
├── /uploads/        ✅ User uploads
│
├── ROOT (10 files max)
│   ├── index.html                    ✅ Main entry point
│   ├── PHPMailerAutoload.php        ✅ Library
│   ├── README.md                     ✅ Main readme
│   ├── .gitignore                    ✅ Git config
│   ├── settings.html                 ? Review
│   ├── system_dashboard.html         ? Review
│   ├── home_page_admin.php           ? Check if duplicate
│   ├── student_input_process.php     ? Move to /student/?
│   ├── approve_account_admin.php     ? Move to /admin/?
│   └── init_account_system.php       ? Review
```

---

## Action Plan

### Priority 1: Authentication Files (High Impact)
- [ ] Create `/auth/` folder
- [ ] Move 7 authentication files
- [ ] Update ALL references throughout codebase
- [ ] Test login/logout/password reset

### Priority 2: Documentation Cleanup (Easy)
- [ ] Move 11 .md files to `/docs/`
- [ ] Move sample CSV to `/docs/samples/`
- [ ] Update any references

### Priority 3: Development Files (Low Risk)
- [ ] Create `/dev/test/`, `/dev/debug/`, `/dev/scripts/`
- [ ] Move 30+ development files
- [ ] These files likely have no references in production code

### Priority 4: Utilities (Medium Impact)
- [ ] Create `/utils/` folder
- [ ] Move 4 utility files
- [ ] Update references throughout codebase
- [ ] Consider renaming connection files

### Priority 5: Handler/Processing Files (Medium Impact)
- [ ] Review each handler file
- [ ] Determine correct module location
- [ ] Move and update references

### Priority 6: Forms Consideration (Optional)
- [ ] Decide if `/forms/` folder is needed
- [ ] May be better to keep HTML forms in root for easy access
- [ ] Or move to respective module folders

---

## Expected Results

**Before Phase 4:**
- Root directory: 70+ files
- Cluttered and hard to navigate

**After Phase 4:**
- Root directory: ~10 essential files
- Clear organization by purpose
- Easy to find and maintain files

---

## Next Steps

1. Start with **Authentication files** (highest impact, most references)
2. Then **Documentation** (easiest, low risk)
3. Then **Development files** (easy, almost no references)
4. Then **Utilities** (medium complexity)
5. Finally **Handler files** (needs careful review)

**Ready to begin Phase 4?**

