# File Organization Script
# This script helps you move files to their new organized locations

## IMPORTANT: Run these commands ONE CATEGORY AT A TIME and test after each!

### ============================================
### PHASE 1: ADMIN FILES
### ============================================

# PowerShell commands to move admin files:
Move-Item -Path "home_page_admin.php" -Destination "admin/index.php"
Move-Item -Path "admin_login.php" -Destination "admin/login.php"
Move-Item -Path "admin_login_process.php" -Destination "admin/login_process.php"
Move-Item -Path "pending_accs_admin.php" -Destination "admin/pending_accounts.php"
Move-Item -Path "approve_account_admin.php" -Destination "admin/approve_account.php"
Move-Item -Path "reject_admin.php" -Destination "admin/reject_account.php"
Move-Item -Path "acc_mng_admin.php" -Destination "admin/account_management.php"
Move-Item -Path "admin_pending_accounts.php" -Destination "admin/pending_list.php"
Move-Item -Path "logout_admin.php" -Destination "admin/logout.php"
Move-Item -Path "admin_connection.php" -Destination "admin/connection.php"
Move-Item -Path "admin_input_form.html" -Destination "admin/input_form.html"
Move-Item -Path "reset_admin_password.php" -Destination "admin/reset_password.php"

# After moving, update paths in admin files:
# - Change: require_once __DIR__ . '/config/config.php'
# - To: require_once __DIR__ . '/../config/config.php'


### ============================================
### PHASE 2: ADVISER FILES
### ============================================

Move-Item -Path "home_page_adviser.php" -Destination "adviser/index.php"
Move-Item -Path "adviser_login.php" -Destination "adviser/login.php"
Move-Item -Path "adviser_login_process.php" -Destination "adviser/login_process.php"
Move-Item -Path "pending_accs_adviser.php" -Destination "adviser/pending_accounts.php"
Move-Item -Path "approve_account_adviser.php" -Destination "adviser/approve_account.php"
Move-Item -Path "reject_adviser.php" -Destination "adviser/reject_account.php"
Move-Item -Path "adviser_management.php" -Destination "adviser/management.php"
Move-Item -Path "checklist_adviser.php" -Destination "adviser/checklist.php"
Move-Item -Path "checklist_eval_adviser.php" -Destination "adviser/checklist_eval.php"
Move-Item -Path "pre_enroll.php" -Destination "adviser/pre_enroll.php"
Move-Item -Path "logout_adviser.php" -Destination "adviser/logout.php"
Move-Item -Path "adviser_connection.php" -Destination "adviser/connection.php"
Move-Item -Path "adviser_input_form.html" -Destination "adviser/input_form.html"

# Update paths in adviser files


### ============================================
### PHASE 3: STUDENT FILES
### ============================================

Move-Item -Path "home_page_student.php" -Destination "student/index.php"
Move-Item -Path "login_process.php" -Destination "student/login_process.php"
Move-Item -Path "student_input_form_1.html" -Destination "student/register_step1.html"
Move-Item -Path "student_input_form_2.html" -Destination "student/register_step2.html"
Move-Item -Path "student_input_process.php" -Destination "student/register_process.php"
Move-Item -Path "profile.php" -Destination "student/profile.php"
Move-Item -Path "save_profile.php" -Destination "student/save_profile.php"
Move-Item -Path "checklist_stud.php" -Destination "student/checklist.php"
Move-Item -Path "save_checklist_stud.php" -Destination "student/save_checklist.php"
Move-Item -Path "save_pre_enrollment.php" -Destination "student/save_pre_enrollment.php"
Move-Item -Path "signout.php" -Destination "student/signout.php"

# Update paths in student files


### ============================================
### PHASE 4: AUTH FILES
### ============================================

Move-Item -Path "forgot_password.php" -Destination "auth/forgot_password.php"
Move-Item -Path "verify_code.php" -Destination "auth/verify_code.php"
Move-Item -Path "reset_password.php" -Destination "auth/reset_password.php"
Move-Item -Path "reset_password_new.php" -Destination "auth/reset_password_new.php"
Move-Item -Path "change_password.php" -Destination "auth/change_password.php"

# Update paths in auth files


### ============================================
### PHASE 5: API FILES
### ============================================

Move-Item -Path "get_checklist_data.php" -Destination "api/get_checklist_data.php"
Move-Item -Path "get_enrollment_details.php" -Destination "api/get_enrollment_details.php"
Move-Item -Path "get_transaction_history.php" -Destination "api/get_transaction_history.php"
Move-Item -Path "fetchPrograms.php" -Destination "api/fetchPrograms.php"
Move-Item -Path "savePrograms.php" -Destination "api/savePrograms.php"
Move-Item -Path "load_pre_enrollment.php" -Destination "api/load_pre_enrollment.php"
Move-Item -Path "save_checklist.php" -Destination "api/save_checklist.php"
Move-Item -Path "list_of_students.php" -Destination "api/list_of_students.php"
Move-Item -Path "batch_update.php" -Destination "api/batch_update.php"
Move-Item -Path "bulk_student_import.php" -Destination "api/bulk_student_import.php"

# Update paths in API files


### ============================================
### PHASE 6: DEVELOPMENT/TEST FILES
### ============================================

Move-Item -Path "test_*.php" -Destination "dev/"
Move-Item -Path "debug_*.php" -Destination "dev/"
Move-Item -Path "check_*.php" -Destination "dev/"
Move-Item -Path "fix_*.php" -Destination "dev/"
Move-Item -Path "migration_helper.php" -Destination "dev/"
Move-Item -Path "batch_migrate.php" -Destination "dev/"

# Wildcard moves (run in PowerShell)
Get-ChildItem -Path . -Filter "test_*.php" | Move-Item -Destination "dev/"
Get-ChildItem -Path . -Filter "debug_*.php" | Move-Item -Destination "dev/"
Get-ChildItem -Path . -Filter "check_*.php" | Move-Item -Destination "dev/"
Get-ChildItem -Path . -Filter "fix_*.php" | Move-Item -Destination "dev/"


### ============================================
### PHASE 7: DOCUMENTATION FILES
### ============================================

Move-Item -Path "*.md" -Destination "docs/"
# BUT keep README.md in root:
Move-Item -Path "docs/README.md" -Destination "README.md"

# Or be selective:
Move-Item -Path "STEP_*.md" -Destination "docs/"
Move-Item -Path "BATCH_*.md" -Destination "docs/"
Move-Item -Path "MIGRATION_*.md" -Destination "docs/"
Move-Item -Path "ACCOUNT_APPROVAL_SYSTEM.md" -Destination "docs/"
Move-Item -Path "MIDDLE_NAME_SOLUTION.md" -Destination "docs/"


### ============================================
### PHASE 8: STATIC FILES (HTML/Archive)
### ============================================

Move-Item -Path "*.html" -Destination "assets/"
# BUT keep index.html in root:
Move-Item -Path "assets/index.html" -Destination "index.html"

# Or move specific files:
Move-Item -Path "Archive_rec.html" -Destination "assets/archive_rec.html"
Move-Item -Path "Curriculum.html" -Destination "assets/curriculum.html"
Move-Item -Path "programs.html" -Destination "assets/programs.html"
Move-Item -Path "prog_year_select.html" -Destination "assets/prog_year_select.html"
Move-Item -Path "settings.html" -Destination "assets/settings.html"
Move-Item -Path "system_dashboard.html" -Destination "assets/system_dashboard.html"


### ============================================
### AFTER MOVING FILES: UPDATE PATHS
### ============================================

# Common path updates needed:

# 1. Config includes (in subdirectories):
#    OLD: require_once __DIR__ . '/config/config.php';
#    NEW: require_once __DIR__ . '/../config/config.php';

# 2. Redirects:
#    OLD: header("Location: admin_login.php");
#    NEW: header("Location: /PEAS/admin/login.php");
#    OR:  header("Location: ../admin/login.php");

# 3. Form actions:
#    OLD: action="login_process.php"
#    NEW: action="/PEAS/student/login_process.php"

# 4. Image paths:
#    OLD: src="img/logo.png"
#    NEW: src="../img/logo.png" (from subdirectory)
#    OR:  src="/PEAS/img/logo.png" (absolute)

# 5. Include paths:
#    OLD: include 'header.php';
#    NEW: include __DIR__ . '/../includes/header.php';


### ============================================
### TESTING CHECKLIST
### ============================================

After each phase, test:
1. □ Login works
2. □ Redirects work
3. □ Images load
4. □ No 404 errors
5. □ Database operations work
6. □ Sessions work
7. □ No PHP errors


### ============================================
### ROLLBACK PLAN (If Something Breaks)
### ============================================

# To undo a move (example):
Move-Item -Path "admin/index.php" -Destination "home_page_admin.php"

# Or restore from Git:
git checkout -- <filename>

# Or use your backup!
