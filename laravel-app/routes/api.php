<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LegacyCompat\StudentIdController;
use App\Http\Controllers\LegacyCompat\ProgramsController;
use App\Http\Controllers\LegacyCompat\ChecklistController;
use App\Http\Controllers\LegacyCompat\AuthController;
use App\Http\Controllers\LegacyCompat\AutoLoginController;
use App\Http\Controllers\LegacyCompat\SignoutController;
use App\Http\Controllers\LegacyCompat\CsrfTokenController;
use App\Http\Controllers\LegacyCompat\VerifyCodeController;
use App\Http\Controllers\LegacyCompat\ResetPasswordController;
use App\Http\Controllers\LegacyCompat\ForgotPasswordController;
use App\Http\Controllers\LegacyCompat\FinalVerificationController;
use App\Http\Controllers\LegacyCompat\ChangePasswordController;
use App\Http\Controllers\LegacyCompat\PendingAccountController;
use App\Http\Controllers\LegacyCompat\AccountManagementController;
use App\Http\Controllers\LegacyCompat\StudentProfileController;
use App\Http\Controllers\LegacyCompat\ProgramShiftController;
use App\Http\Controllers\LegacyCompat\PreEnrollmentController;
use App\Http\Controllers\LegacyCompat\CurriculumManagementController;
use App\Http\Controllers\LegacyCompat\ProgramCoordinatorConnectionController;
use App\Http\Controllers\LegacyCompat\AccountCreationController;
use App\Http\Controllers\LegacyCompat\AdviserManagementController;
use App\Http\Controllers\LegacyCompat\AccountsViewController;
use App\Http\Controllers\LegacyCompat\ListOfStudentsController;
use App\Http\Controllers\LegacyCompat\DashboardController;
use App\Http\Controllers\LegacyCompat\ProgramCoordinatorProfileController;
use App\Http\Controllers\LegacyCompat\ProgramCoordinatorStudentListController;
use App\Http\Controllers\LegacyCompat\AdviserStudyPlanListController;
use App\Http\Controllers\LegacyCompat\AdviserChecklistEvalController;
use App\Http\Controllers\LegacyCompat\ProgramCoordinatorCurriculumViewController;
use App\Http\Controllers\LegacyCompat\ProgramCoordinatorCurriculumManagementController;
use App\Http\Controllers\LegacyCompat\StudyPlanBootstrapController;
use App\Http\Controllers\LegacyCompat\AdminPasswordResetController;
use App\Http\Controllers\LegacyCompat\AdminAccountsCheckController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::match(['GET', 'POST'], '/check-student-id', [StudentIdController::class, 'check']);
Route::match(['GET', 'POST'], '/fetch-programs', [ProgramsController::class, 'index']);
Route::post('/save-programs', [ProgramsController::class, 'save']);
Route::post('/checklist/view', [ChecklistController::class, 'view']);
Route::post('/save-checklist', [ChecklistController::class, 'save']);
Route::post('/unified-login', [AuthController::class, 'unifiedLogin']);
Route::post('/check-auto-login', [AutoLoginController::class, 'check']);
Route::post('/signout', [SignoutController::class, 'signout']);
Route::get('/get-csrf-token', [CsrfTokenController::class, 'getToken']);
Route::post('/verify-code', [VerifyCodeController::class, 'verify']);
Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
Route::post('/forgot-password', [ForgotPasswordController::class, 'sendCode']);
Route::get('/final-verification', [FinalVerificationController::class, 'render']);
Route::post('/change-password', [ChangePasswordController::class, 'change']);
Route::post('/student-profile/view', [StudentProfileController::class, 'view']);
Route::post('/student-profile/update', [StudentProfileController::class, 'update']);
Route::post('/account-management/student-profile', [AccountManagementController::class, 'studentProfile']);
Route::post('/account-approval-settings/overview', [AccountManagementController::class, 'approvalSettingsOverview']);
Route::post('/account-approval-settings/update', [AccountManagementController::class, 'approvalSettingsUpdate']);
Route::post('/admin/pending-accounts/list', [PendingAccountController::class, 'adminList']);
Route::post('/admin/pending-accounts/approve', [PendingAccountController::class, 'adminApprove']);
Route::post('/admin/pending-accounts/reject', [PendingAccountController::class, 'adminReject']);
Route::post('/adviser/pending-accounts/list', [PendingAccountController::class, 'adviserList']);
Route::post('/adviser/pending-accounts/approve', [PendingAccountController::class, 'adviserApprove']);
Route::post('/adviser/pending-accounts/reject', [PendingAccountController::class, 'adviserReject']);
Route::post('/program-shift/adviser/queue', [ProgramShiftController::class, 'adviserQueue']);
Route::post('/program-shift/adviser/decision', [ProgramShiftController::class, 'adviserDecision']);
Route::post('/program-shift/coordinator/queue', [ProgramShiftController::class, 'coordinatorQueue']);
Route::post('/program-shift/coordinator/decision', [ProgramShiftController::class, 'coordinatorDecision']);
Route::post('/program-shift/student/overview', [ProgramShiftController::class, 'studentOverview']);
Route::post('/program-shift/student/submit', [ProgramShiftController::class, 'studentSubmit']);
Route::post('/pre-enrollment/bootstrap', [PreEnrollmentController::class, 'bootstrap']);
Route::post('/pre-enrollment/transaction-history', [PreEnrollmentController::class, 'transactionHistory']);
Route::post('/pre-enrollment/enrollment-details', [PreEnrollmentController::class, 'enrollmentDetails']);
Route::post('/pre-enrollment/load', [PreEnrollmentController::class, 'loadPreEnrollment']);
Route::post('/pre-enrollment/save', [PreEnrollmentController::class, 'save']);
Route::post('/curriculum/save', [CurriculumManagementController::class, 'save']);
Route::post('/curriculum/delete-year', [CurriculumManagementController::class, 'deleteYear']);
Route::post('/study-plan/override', [CurriculumManagementController::class, 'studyPlanOverride']);
Route::post('/program-coordinator/create', [ProgramCoordinatorConnectionController::class, 'create']);
Route::post('/admin/create-account', [AccountCreationController::class, 'adminCreate']);
Route::post('/adviser/create-account', [AccountCreationController::class, 'adviserCreate']);
Route::post('/adviser-management/overview', [AdviserManagementController::class, 'overview']);
Route::post('/adviser-management/batch-update', [AdviserManagementController::class, 'batchUpdate']);
Route::post('/adviser-management/batch-update-all', [AdviserManagementController::class, 'batchUpdateAll']);
Route::post('/accounts-view/overview', [AccountsViewController::class, 'overview']);
Route::post('/list-of-students/overview', [ListOfStudentsController::class, 'overview']);
Route::post('/dashboard/overview', [DashboardController::class, 'overview']);
Route::post('/program-coordinator/profile/view', [ProgramCoordinatorProfileController::class, 'view']);
Route::post('/program-coordinator/profile/update', [ProgramCoordinatorProfileController::class, 'update']);
Route::post('/program-coordinator/list-of-students/overview', [ProgramCoordinatorStudentListController::class, 'overview']);
Route::post('/program-coordinator/view-curriculum/overview', [ProgramCoordinatorCurriculumViewController::class, 'overview']);
Route::post('/program-coordinator/curriculum-management/bootstrap', [ProgramCoordinatorCurriculumManagementController::class, 'bootstrap']);
Route::post('/study-plan/student/bootstrap', [StudyPlanBootstrapController::class, 'studentBootstrap']);
Route::post('/study-plan/adviser/bootstrap', [StudyPlanBootstrapController::class, 'adviserBootstrap']);
Route::post('/admin/reset-password', [AdminPasswordResetController::class, 'reset']);
Route::post('/admin/check-accounts', [AdminAccountsCheckController::class, 'check']);
Route::post('/adviser/study-plan/list/overview', [AdviserStudyPlanListController::class, 'overview']);
Route::post('/adviser/checklist-eval/overview', [AdviserChecklistEvalController::class, 'overview']);
