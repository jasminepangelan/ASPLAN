# Laravel Gradual Migration (UI-Preserving)

This project now includes a sidecar Laravel app at `laravel-app/` and a first migrated endpoint.

## What is already done

- Laravel app scaffolded in `laravel-app/`
- First migrated endpoint implemented:
  - Laravel route: `POST|GET /api/check-student-id`
  - Controller: `app/Http/Controllers/LegacyCompat/StudentIdController.php`
- Programs endpoints implemented:
  - Laravel route: `POST|GET /api/fetch-programs`
  - Laravel route: `POST /api/save-programs`
  - Controller: `app/Http/Controllers/LegacyCompat/ProgramsController.php`
- Checklist endpoint implemented:
  - Laravel route: `POST /api/save-checklist`
  - Controller: `app/Http/Controllers/LegacyCompat/ChecklistController.php`
  - Laravel route: `POST /api/checklist/view`
  - Checklist read path now bridges the legacy `get_checklist_data.php` endpoint
  - Student, adviser, and program coordinator checklist pages now prefer Laravel for their initial render data
  - Shared checklist save endpoint now prefers Laravel when `USE_LARAVEL_BRIDGE=1`
  - Student checklist save path now prefers Laravel when `USE_LARAVEL_BRIDGE=1`
- Auth endpoint implemented:
  - Laravel route: `POST /api/unified-login`
  - Controller: `app/Http/Controllers/LegacyCompat/AuthController.php`
- Auto-login endpoint implemented:
  - Laravel route: `POST /api/check-auto-login`
  - Controller: `app/Http/Controllers/LegacyCompat/AutoLoginController.php`
- Signout endpoint implemented:
  - Laravel route: `POST /api/signout`
  - Controller: `app/Http/Controllers/LegacyCompat/SignoutController.php`
- CSRF token endpoint implemented:
  - Laravel route: `GET /api/get-csrf-token`
  - Controller: `app/Http/Controllers/LegacyCompat/CsrfTokenController.php`
- Verify code endpoint implemented:
  - Laravel route: `POST /api/verify-code`
  - Controller: `app/Http/Controllers/LegacyCompat/VerifyCodeController.php`
- Reset password endpoint implemented:
  - Laravel route: `POST /api/reset-password`
  - Controller: `app/Http/Controllers/LegacyCompat/ResetPasswordController.php`
  - Password-reset confirmation email is sent after a successful update
- Forgot password endpoint implemented:
  - Laravel route: `POST /api/forgot-password`
  - Controller: `app/Http/Controllers/LegacyCompat/ForgotPasswordController.php`
- Final verification endpoint implemented:
  - Laravel route: `GET /api/final-verification`
  - Controller: `app/Http/Controllers/LegacyCompat/FinalVerificationController.php`
- Change password endpoint implemented:
  - Laravel route: `POST /api/change-password`
  - Controller: `app/Http/Controllers/LegacyCompat/ChangePasswordController.php`
  - Password-change confirmation email is sent after a successful update
- Pending account action endpoints implemented:
  - Laravel route: `POST /api/admin/pending-accounts/list`
  - Laravel route: `POST /api/admin/pending-accounts/approve`
  - Laravel route: `POST /api/admin/pending-accounts/reject`
  - Laravel route: `POST /api/adviser/pending-accounts/list`
  - Laravel route: `POST /api/adviser/pending-accounts/approve`
  - Laravel route: `POST /api/adviser/pending-accounts/reject`
  - Controller: `app/Http/Controllers/LegacyCompat/PendingAccountController.php`
- Legacy login-process compatibility completed:
  - Existing URL remains: `auth/login_process.php`
  - Delegates to migrated `auth/unified_login_process.php`
- Admin login-process compatibility completed:
  - Existing URL remains: `admin/login_process.php`
  - Uses bridge-first auth with legacy fallback and preserved redirect behavior
- Adviser login-process compatibility completed:
  - Existing URL remains: `adviser/login_process.php`
  - Uses bridge-first auth with legacy fallback and preserved redirect behavior
  - Includes program coordinator credential handling and redirect to `program_coordinator/index.php`
- Program coordinator logout compatibility completed:
  - Existing URL remains: `program_coordinator/logout.php`
  - Uses bridge-first signout with legacy fallback and preserved redirect behavior
- Admin logout compatibility completed:
  - Existing URL remains: `admin/logout.php`
  - Uses bridge-first signout with legacy fallback and preserved redirect behavior
- Adviser logout compatibility completed:
  - Existing URL remains: `adviser/logout.php`
  - Uses bridge-first signout with legacy fallback and preserved redirect behavior
- Account action hardening completed:
  - `admin/approve_account.php` now validates student ID format and handles not-found updates cleanly
  - `admin/reject_account.php` now requires admin auth, validates input, and marks status as rejected instead of deleting records
  - `adviser/approve_account.php` now enforces role checks, validates input, and removes debug output leakage
  - `adviser/reject_account.php` now enforces role+batch ownership and marks status as rejected instead of deleting records
- Pending account listing bridge completed:
  - `admin/pending_accounts.php` now loads pending accounts and auto-approval state from Laravel when `USE_LARAVEL_BRIDGE=1`
  - `adviser/pending_accounts.php` now loads batch-filtered pending accounts from Laravel when `USE_LARAVEL_BRIDGE=1`
  - Legacy rendering remains unchanged when the bridge is off or unavailable
- Account management/settings query bridge completed:
  - `admin/account_management.php` now loads student profile data from Laravel when `USE_LARAVEL_BRIDGE=1`
  - `adviser/account_management.php` now loads student profile data from Laravel when `USE_LARAVEL_BRIDGE=1`
  - `admin/account_approval_settings.php` now loads overview data from Laravel when `USE_LARAVEL_BRIDGE=1`
  - Legacy queries remain available as fallback
- Profile update bridge completed:
  - `student/save_profile.php` now posts profile updates and optional picture uploads to Laravel when `USE_LARAVEL_BRIDGE=1`
  - `adviser/account_management.php` now posts profile updates and optional picture uploads to Laravel when `USE_LARAVEL_BRIDGE=1`
  - Legacy update logic remains available as fallback
- Student account-management view bridge completed:
  - `student/acc_mng.php` now loads the student profile from Laravel when `USE_LARAVEL_BRIDGE=1`
  - Legacy session/database view remains available as fallback
- Pre-enrollment endpoint bridge completed:
  - Existing URLs remain: `adviser/get_transaction_history.php`, `adviser/get_enrollment_details.php`
  - Existing URLs remain: `adviser/load_pre_enrollment.php`, `adviser/save_pre_enrollment.php`
  - Laravel route: `POST /api/pre-enrollment/transaction-history`
  - Laravel route: `POST /api/pre-enrollment/enrollment-details`
  - Laravel route: `POST /api/pre-enrollment/load`
  - Laravel route: `POST /api/pre-enrollment/save`
  - `adviser/pre_enroll.php` now prefers Laravel for its bootstrap data when `USE_LARAVEL_BRIDGE=1`
  - Controller: `app/Http/Controllers/LegacyCompat/PreEnrollmentController.php`
- Checklist save bridge completed:
  - Existing URL remains: `save_checklist.php`
  - Existing URL remains: `student/save_checklist_stud.php`
  - Existing URL remains: `program_coordinator/checklist.php` for the coordinator save flow
- Curriculum and study-plan bridge completed:
  - Existing URLs remain: `program_coordinator/save_curriculum.php`, `program_coordinator/delete_curriculum.php`
  - Existing URL remains: `program_coordinator/save_study_plan_override.php`
  - Laravel route: `POST /api/curriculum/save`
  - Laravel route: `POST /api/curriculum/delete-year`
  - Laravel route: `POST /api/study-plan/override`
  - Controller: `app/Http/Controllers/LegacyCompat/CurriculumManagementController.php`
- Program coordinator account-creation bridge completed:
  - Existing URL remains: `handlers/program_coordinator_connection.php`
  - Laravel route: `POST /api/program-coordinator/create`
  - Controller: `app/Http/Controllers/LegacyCompat/ProgramCoordinatorConnectionController.php`
- Admin/adviser account-creation bridge completed:
  - Existing URLs remain: `handlers/admin_connection.php`, `handlers/adviser_connection.php`
  - Laravel route: `POST /api/admin/create-account`
  - Laravel route: `POST /api/adviser/create-account`
  - Controller: `app/Http/Controllers/LegacyCompat/AccountCreationController.php`
- Account viewer bridge completed:
  - Existing URL remains: `admin/accounts_view.php`
  - Laravel route: `POST /api/accounts-view/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/AccountsViewController.php`
- Student directory bridge completed:
  - Existing URL remains: `admin/list_of_students.php`
  - Laravel route: `POST /api/list-of-students/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/ListOfStudentsController.php`
- Dashboard summary bridge completed:
  - Existing URLs remain: `adviser/index.php`, `program_coordinator/index.php`, `student/home_page_student.php`
  - Laravel route: `POST /api/dashboard/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/DashboardController.php`
- Program coordinator profile bridge completed:
  - Existing URL remains: `program_coordinator/profile.php`
  - Laravel route: `POST /api/program-coordinator/profile/view`
  - Laravel route: `POST /api/program-coordinator/profile/update`
  - Controller: `app/Http/Controllers/LegacyCompat/ProgramCoordinatorProfileController.php`
- Adviser study-plan list bridge completed:
  - Existing URL remains: `adviser/study_plan_list.php`
  - Laravel route: `POST /api/adviser/study-plan/list/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/AdviserStudyPlanListController.php`
- Adviser checklist-eval bridge completed:
  - Existing URL remains: `adviser/checklist_eval.php`
  - Laravel route: `POST /api/adviser/checklist-eval/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/AdviserChecklistEvalController.php`
- Program coordinator curriculum view bridge completed:
  - Existing URL remains: `program_coordinator/view_curriculum.php`
  - Laravel route: `POST /api/program-coordinator/view-curriculum/overview`
  - Controller: `app/Http/Controllers/LegacyCompat/ProgramCoordinatorCurriculumViewController.php`
- Program coordinator curriculum management bridge completed:
  - Existing URL remains: `program_coordinator/curriculum_management.php`
  - Laravel route: `POST /api/program-coordinator/curriculum-management/bootstrap`
  - Controller: `app/Http/Controllers/LegacyCompat/ProgramCoordinatorCurriculumManagementController.php`
- Study plan bootstrap bridge completed:
  - Existing URLs remain: `student/study_plan.php`, `adviser/study_plan_view.php`
  - Laravel route: `POST /api/study-plan/student/bootstrap`
  - Laravel route: `POST /api/study-plan/adviser/bootstrap`
  - Controller: `app/Http/Controllers/LegacyCompat/StudyPlanBootstrapController.php`
- Program coordinator study plan view bridge completed:
  - Existing URL remains: `program_coordinator/study_plan_view.php`
  - Reuses `POST /api/study-plan/student/bootstrap`
- Curriculum save aliases bridged:
  - Existing URLs remain: `admin/save_curriculum.php`, `program_coordinator/update_profile.php`
  - Both prefer `POST /api/curriculum/save` when `USE_LARAVEL_BRIDGE=1`
- Admin password reset bridge completed:
  - Existing URL remains: `admin/reset_password.php`
  - Laravel route: `POST /api/admin/reset-password`
  - Controller: `app/Http/Controllers/LegacyCompat/AdminPasswordResetController.php`
- Admin account-check utility bridge completed:
  - Existing URL remains: `admin/check_accounts.php`
  - Laravel route: `POST /api/admin/check-accounts`
  - Controller: `app/Http/Controllers/LegacyCompat/AdminAccountsCheckController.php`
- Adviser batch-assignment bridge completed:
  - Existing URLs remain: `batch_update.php`, `batch_update_all.php`
  - Existing URL remains: `program_coordinator/adviser_management.php`
  - Existing URL remains: `admin/adviser_management.php`
  - Laravel route: `POST /api/adviser-management/overview`
  - Laravel route: `POST /api/adviser-management/batch-update`
  - Laravel route: `POST /api/adviser-management/batch-update-all`
  - Controller: `app/Http/Controllers/LegacyCompat/AdviserManagementController.php`
- Legacy endpoint bridge added:
  - Existing URL remains: `api/check_student_id.php`
  - Existing URLs remain: `api/fetchPrograms.php`, `api/savePrograms.php`
  - Existing URL remains: `api/save_checklist.php`
  - Existing URL remains: `auth/unified_login_process.php`
  - Existing URL remains: `auth/check_auto_login.php`
  - Existing URL remains: `auth/signout.php`
  - Existing URL remains: `auth/get_csrf_token.php`
  - Existing URL remains: `auth/verify_code.php`
  - Existing URL remains: `auth/reset_password.php`
  - Existing URL remains: `auth/reset_password_new.php`
  - Existing URL remains: `auth/forgot_password.php`
  - Existing URL remains: `auth/final_verification.php`
  - Existing URL remains: `auth/change_password.php`
  - Existing URL remains: `auth/login_process.php`
  - Existing URL remains: `admin/login_process.php`
  - Existing URL remains: `adviser/login_process.php`
  - Existing URL remains: `program_coordinator/logout.php`
  - Existing URL remains: `admin/logout.php`
  - Existing URL remains: `adviser/logout.php`
  - Existing URLs remain: `admin/approve_account.php`, `admin/reject_account.php`
  - Existing URLs remain: `adviser/approve_account.php`, `adviser/reject_account.php`
  - Optional forwarding to Laravel controlled by `USE_LARAVEL_BRIDGE`
  - Optional auth forwarding controlled by `USE_LARAVEL_AUTH_BRIDGE`

## How to enable bridge for the first endpoint

1. Open root `.env`
2. Set:

   `USE_LARAVEL_BRIDGE=1`

  Optional auth bridge:

  `USE_LARAVEL_AUTH_BRIDGE=1`

3. Keep it as `0` to use legacy code path.

## Validation URLs

- Legacy URL (always):
  - `http://localhost/ASPLAN_v10/api/check_student_id.php?student_id=TEST123`
- Laravel sidecar URL (direct):
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/check-student-id?student_id=TEST123`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/fetch-programs`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/save-programs`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/save-checklist`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/unified-login`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/check-auto-login`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/signout`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/get-csrf-token`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/verify-code`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/reset-password`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/forgot-password`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/final-verification`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/change-password`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/admin/pending-accounts/list`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/admin/pending-accounts/approve`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/admin/pending-accounts/reject`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/pending-accounts/list`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/pending-accounts/approve`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/adviser/pending-accounts/reject`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/account-management/student-profile`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/view`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/account-approval-settings/overview`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/student-profile/update`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/transaction-history`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/enrollment-details`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/load`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/save`
  - `http://localhost/ASPLAN_v10/laravel-app/public/api/pre-enrollment/bootstrap`

## Useful commands

Run from `ASPLAN_v10/laravel-app`:

```powershell
C:\xampp\php\php.exe artisan route:list --path=api/check-student-id
C:\xampp\php\php.exe artisan key:generate
```

## Next endpoint to migrate

- The pre-enrollment adviser page now prefers Laravel for its bootstrap data in `adviser/pre_enroll.php`
- The four active pre-enrollment request endpoints are bridged through Laravel
- Next practical follow-up is any remaining legacy page that still renders initial data directly from SQL instead of the bridge

Use the same pattern: keep legacy URL, add optional bridge toggle, preserve exact response shape.
