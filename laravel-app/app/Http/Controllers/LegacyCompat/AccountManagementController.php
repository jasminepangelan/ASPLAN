<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AccountManagementController extends Controller
{
    public function studentProfile(Request $request): JsonResponse
    {
        try {
            $studentId = trim((string) $request->input('student_id', ''));
            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student selected.',
                ], 422);
            }

            $student = DB::table('student_info')
                ->where('student_number', $studentId)
                ->first();

            if ($student === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Student not found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'student' => (array) $student,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load student profile.',
            ], 500);
        }
    }

    public function approvalSettingsOverview(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adminId = trim((string) $request->input('admin_id', ''));
            if ($adminId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Please log in as admin.',
                ], 401);
            }

            $policyKeys = [
                'session_timeout_seconds' => 3600,
                'min_password_length' => 8,
                'password_reset_expiry_seconds' => 600,
                'rate_limit_login_max_attempts' => 5,
                'rate_limit_login_window_seconds' => 300,
                'rate_limit_forgot_password_max_attempts' => 3,
                'rate_limit_forgot_password_window_seconds' => 600,
            ];

            $advancedKeys = [
                'enable_admin_2fa' => '0',
                'password_history_count' => '5',
                'account_lockout_duration_seconds' => '900',
                'allowed_email_domains' => '',
                'rejection_cooldown_days' => '0',
                'pending_alert_threshold' => '50',
                'freeze_approvals' => '0',
                'disable_new_registrations' => '0',
                'default_records_per_page' => '10',
                'registration_open_start' => '',
                'registration_open_end' => '',
            ];

            $settings = DB::table('system_settings')
                ->select(['setting_name', 'setting_value'])
                ->get()
                ->groupBy('setting_name')
                ->map(static fn ($rows) => (string) ($rows->last()->setting_value ?? ''))
                ->all();

            $policySettingValues = [];
            foreach ($policyKeys as $key => $default) {
                $value = $settings[$key] ?? (string) $default;
                $policySettingValues[$key] = is_numeric($value) ? (int) $value : (int) $default;
            }

            $advancedSettingValues = [];
            foreach ($advancedKeys as $key => $default) {
                $value = $settings[$key] ?? $default;
                if (in_array($key, ['enable_admin_2fa', 'freeze_approvals', 'disable_new_registrations'], true)) {
                    $advancedSettingValues[$key] = ((string) $value === '1') ? '1' : '0';
                    continue;
                }

                if (in_array($key, ['password_history_count', 'account_lockout_duration_seconds', 'rejection_cooldown_days', 'pending_alert_threshold', 'default_records_per_page'], true)) {
                    $advancedSettingValues[$key] = is_numeric($value) ? (string) ((int) $value) : $default;
                    continue;
                }

                $advancedSettingValues[$key] = (string) $value;
            }

            $autoApproveEnabled = ((string) ($settings['auto_approve_students'] ?? '0') === '1');
            $freezeApprovalsEnabled = ((string) ($settings['freeze_approvals'] ?? '0') === '1');
            $defaultRecordsPerPage = max(5, min(100, (int) ($advancedSettingValues['default_records_per_page'] ?? 10)));

            $stats = [
                'pending' => 0,
                'approved' => 0,
                'rejected' => 0,
            ];

            $statsRows = DB::table('student_info')
                ->select(['status', DB::raw('COUNT(*) as count')])
                ->groupBy('status')
                ->get();
            foreach ($statsRows as $row) {
                $status = (string) ($row->status ?? '');
                if (array_key_exists($status, $stats)) {
                    $stats[$status] = (int) ($row->count ?? 0);
                }
            }

            $pendingAlertThreshold = (int) ($advancedSettingValues['pending_alert_threshold'] ?? 50);
            $currentPendingCount = (int) ($stats['pending'] ?? 0);
            $pendingThresholdReached = $pendingAlertThreshold > 0 && $currentPendingCount >= $pendingAlertThreshold;

            $registrationStartRaw = (string) ($advancedSettingValues['registration_open_start'] ?? '');
            $registrationEndRaw = (string) ($advancedSettingValues['registration_open_end'] ?? '');
            $registrationStartTs = strtotime(str_replace('T', ' ', $registrationStartRaw));
            $registrationEndTs = strtotime(str_replace('T', ' ', $registrationEndRaw));
            $registrationNow = time();

            $registrationStatusLabel = 'OPEN';
            $registrationStatusClass = 'enabled';
            $registrationStatusDetail = 'Registration is currently accepting new student signups.';

            if (((int) ($advancedSettingValues['disable_new_registrations'] ?? '0')) === 1) {
                $registrationStatusLabel = 'CLOSED (MANUAL OVERRIDE)';
                $registrationStatusClass = 'disabled';
                $registrationStatusDetail = 'Temporarily disabled by administrator setting.';
            } elseif ($registrationStartTs !== false && $registrationNow < $registrationStartTs) {
                $registrationStatusLabel = 'SCHEDULED';
                $registrationStatusClass = 'scheduled';
                $registrationStatusDetail = 'Opens on ' . date('M d, Y h:i A', $registrationStartTs) . '.';
            } elseif ($registrationEndTs !== false && $registrationNow > $registrationEndTs) {
                $registrationStatusLabel = 'CLOSED';
                $registrationStatusClass = 'disabled';
                $registrationStatusDetail = 'Window ended on ' . date('M d, Y h:i A', $registrationEndTs) . '.';
            } elseif ($registrationEndTs !== false) {
                $registrationStatusDetail = 'Open until ' . date('M d, Y h:i A', $registrationEndTs) . '.';
            }

            $auditLogs = [];
            try {
                $auditLogs = DB::table('admin_audit_logs')
                    ->select(['id', 'admin_id', 'action_type', 'action_target', 'summary', 'metadata_json', 'created_at'])
                    ->orderByDesc('created_at')
                    ->limit(30)
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            } catch (Throwable $e) {
                $auditLogs = [];
            }

            $programShiftStats = [
                'pending_adviser' => 0,
                'pending_coordinator' => 0,
                'approved' => 0,
                'rejected' => 0,
                'total' => 0,
            ];
            $programShiftRecent = [];

            $shiftTableExists = !empty(DB::select("SHOW TABLES LIKE 'program_shift_requests'"));
            if ($shiftTableExists) {
                $shiftStatsRows = DB::table('program_shift_requests')
                    ->select(['status', DB::raw('COUNT(*) as count_value')])
                    ->groupBy('status')
                    ->get();
                foreach ($shiftStatsRows as $row) {
                    $statusKey = (string) ($row->status ?? '');
                    if (array_key_exists($statusKey, $programShiftStats)) {
                        $programShiftStats[$statusKey] = (int) ($row->count_value ?? 0);
                    }
                }

                $programShiftStats['total'] =
                    (int) $programShiftStats['pending_adviser'] +
                    (int) $programShiftStats['pending_coordinator'] +
                    (int) $programShiftStats['approved'] +
                    (int) $programShiftStats['rejected'];

                $programShiftRecent = DB::table('program_shift_requests')
                    ->select([
                        'request_code',
                        'student_number',
                        'student_name',
                        'current_program',
                        'requested_program',
                        'status',
                        'requested_at',
                        'adviser_action_at',
                        'coordinator_action_at',
                        'executed_at',
                    ])
                    ->orderByDesc('requested_at')
                    ->orderByDesc('id')
                    ->limit(25)
                    ->get()
                    ->map(static fn ($row): array => (array) $row)
                    ->all();
            }

            return response()->json([
                'success' => true,
                'auto_approve_enabled' => $autoApproveEnabled,
                'policy_setting_values' => $policySettingValues,
                'advanced_setting_values' => $advancedSettingValues,
                'freeze_approvals_enabled' => $freezeApprovalsEnabled,
                'default_records_per_page' => $defaultRecordsPerPage,
                'stats' => $stats,
                'pending_alert_threshold' => $pendingAlertThreshold,
                'current_pending_count' => $currentPendingCount,
                'pending_threshold_reached' => $pendingThresholdReached,
                'registration' => [
                    'start_raw' => $registrationStartRaw,
                    'end_raw' => $registrationEndRaw,
                    'start_ts' => $registrationStartTs === false ? null : $registrationStartTs,
                    'end_ts' => $registrationEndTs === false ? null : $registrationEndTs,
                    'now' => $registrationNow,
                    'status_label' => $registrationStatusLabel,
                    'status_class' => $registrationStatusClass,
                    'status_detail' => $registrationStatusDetail,
                ],
                'audit_logs' => $auditLogs,
                'program_shift_stats' => $programShiftStats,
                'program_shift_recent' => $programShiftRecent,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load approval settings overview.',
            ], 500);
        }
    }

    public function approvalSettingsUpdate(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adminId = trim((string) $request->input('admin_id', ''));
            if ($adminId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied. Please log in as admin.',
                ], 401);
            }

            $schema = $this->approvalSettingsSchema();
            $policySettings = $schema['policy_settings'];
            $advancedSettings = $schema['advanced_settings'];
            $action = (string) $request->input('action', '');

            if ($request->has('update_policy_settings') || $action === 'update_policy_settings') {
                $changedSettings = [];

                foreach ($policySettings as $key => $meta) {
                    $raw = trim((string) $request->input($key, ''));
                    $value = is_numeric($raw) ? (int) $raw : (int) ($meta['default'] ?? 0);

                    if (isset($meta['min']) && $value < $meta['min']) {
                        $value = (int) $meta['min'];
                    }
                    if (isset($meta['max']) && $value > $meta['max']) {
                        $value = (int) $meta['max'];
                    }

                    $currentValue = $this->getSettingValue($key);
                    $this->upsertSystemSetting($key, (string) $value, $adminId);

                    if ((string) $currentValue !== (string) $value) {
                        $changedSettings[] = $key;
                    }
                }

                if (!empty($changedSettings)) {
                    $this->writeAdminAuditLog(
                        $adminId,
                        'settings_update',
                        'policy_settings',
                        'Updated security and rate-limit policy settings.',
                        ['changed_settings' => $changedSettings]
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Security and rate-limit settings updated.',
                ]);
            }

            if ($request->has('update_setting') || $action === 'update_setting') {
                if ($this->isApprovalFreezeEnabled()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Approval actions are currently frozen. Disable freeze first to change auto-approval mode.',
                    ], 423);
                }

                $autoApprove = $request->boolean('auto_approve');
                $approvedCount = $this->updateAutoApproveSetting($adminId, $autoApprove);
                $message = $autoApprove
                    ? 'Auto-approval enabled. ' . $approvedCount . ' pending accounts have been automatically approved.'
                    : 'Auto-approval disabled. New accounts will require manual approval.';

                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'approved_count' => $approvedCount,
                ]);
            }

            if ($request->has('update_advanced_settings') || $action === 'update_advanced_settings') {
                $changedAdvancedSettings = [];

                foreach ($advancedSettings as $key => $meta) {
                    $raw = ($meta['type'] ?? 'text') === 'boolean'
                        ? ($request->boolean($key) ? '1' : '0')
                        : (string) $request->input($key, '');
                    $normalized = $this->normalizeSettingValue($meta, $raw);

                    $currentValue = $this->getSettingValue($key);
                    $this->upsertSystemSetting($key, $normalized, $adminId);

                    if ((string) $currentValue !== $normalized) {
                        $changedAdvancedSettings[] = $key;
                    }
                }

                if (!empty($changedAdvancedSettings)) {
                    $this->writeAdminAuditLog(
                        $adminId,
                        'settings_update',
                        'advanced_controls',
                        'Updated advanced admin controls.',
                        ['changed_settings' => $changedAdvancedSettings]
                    );
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Advanced admin controls updated.',
                ]);
            }

            if ($request->has('account_action') || $action === 'account_action') {
                if ($this->isApprovalFreezeEnabled()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Approval actions are currently frozen by admin settings.',
                    ], 423);
                }

                $studentId = trim((string) $request->input('student_id', ''));
                $studentAction = (string) $request->input('student_action', $request->input('action', ''));
                if ($studentId === '') {
                    return response()->json([
                        'success' => false,
                        'message' => 'No student account selected.',
                    ], 422);
                }

                $message = $this->performStudentAccountAction($adminId, $studentId, $studentAction);
                if ($message['success']) {
                    return response()->json($message);
                }

                return response()->json($message, (int) ($message['status'] ?? 422));
            }

            if ($request->has('bulk_action') || $action === 'bulk_action') {
                if ($this->isApprovalFreezeEnabled()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Bulk actions are disabled while approval freeze is enabled.',
                    ], 423);
                }

                $bulkAction = (string) $request->input('bulk_action', '');
                $selectedStudents = $request->input('selected_students', []);
                if (!is_array($selectedStudents)) {
                    $selectedStudents = [];
                }
                $selectedStudents = array_values(array_filter(array_map(static function ($value): string {
                    return trim((string) $value);
                }, $selectedStudents), static fn (string $value): bool => $value !== ''));

                if (empty($selectedStudents)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No student accounts selected.',
                    ], 422);
                }

                $message = $this->performBulkStudentAccountAction($adminId, $bulkAction, $selectedStudents);
                if ($message['success']) {
                    return response()->json($message);
                }

                return response()->json($message, (int) ($message['status'] ?? 422));
            }

            return response()->json([
                'success' => false,
                'message' => 'No valid approval settings action was provided.',
            ], 422);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update approval settings.',
            ], 500);
        }
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function approvalSettingsSchema(): array
    {
        return [
            'policy_settings' => [
                'session_timeout_seconds' => ['default' => 3600, 'min' => 60, 'max' => 86400],
                'min_password_length' => ['default' => 8, 'min' => 6, 'max' => 64],
                'password_reset_expiry_seconds' => ['default' => 600, 'min' => 60, 'max' => 7200],
                'rate_limit_login_max_attempts' => ['default' => 5, 'min' => 1, 'max' => 100],
                'rate_limit_login_window_seconds' => ['default' => 300, 'min' => 30, 'max' => 86400],
                'rate_limit_forgot_password_max_attempts' => ['default' => 3, 'min' => 1, 'max' => 100],
                'rate_limit_forgot_password_window_seconds' => ['default' => 600, 'min' => 30, 'max' => 86400],
            ],
            'advanced_settings' => [
                'enable_admin_2fa' => ['type' => 'boolean', 'default' => '0', 'min' => 0, 'max' => 1],
                'password_history_count' => ['type' => 'number', 'default' => '5', 'min' => 0, 'max' => 24],
                'account_lockout_duration_seconds' => ['type' => 'number', 'default' => '900', 'min' => 60, 'max' => 86400],
                'allowed_email_domains' => ['type' => 'text', 'default' => '', 'max_length' => 500],
                'rejection_cooldown_days' => ['type' => 'number', 'default' => '0', 'min' => 0, 'max' => 365],
                'pending_alert_threshold' => ['type' => 'number', 'default' => '50', 'min' => 1, 'max' => 5000],
                'freeze_approvals' => ['type' => 'boolean', 'default' => '0', 'min' => 0, 'max' => 1],
                'disable_new_registrations' => ['type' => 'boolean', 'default' => '0', 'min' => 0, 'max' => 1],
                'default_records_per_page' => ['type' => 'number', 'default' => '10', 'min' => 5, 'max' => 100],
                'registration_open_start' => ['type' => 'datetime', 'default' => ''],
                'registration_open_end' => ['type' => 'datetime', 'default' => ''],
            ],
        ];
    }

    private function normalizeSettingValue(array $meta, string $raw): string
    {
        $value = trim($raw);

        if (($meta['type'] ?? 'number') === 'boolean') {
            return ($value === '1' || $value === 'true') ? '1' : '0';
        }

        if (($meta['type'] ?? 'number') === 'number') {
            $numValue = is_numeric($value) ? (int) $value : (int) ($meta['default'] ?? 0);
            if (isset($meta['min']) && $numValue < $meta['min']) {
                $numValue = (int) $meta['min'];
            }
            if (isset($meta['max']) && $numValue > $meta['max']) {
                $numValue = (int) $meta['max'];
            }
            return (string) $numValue;
        }

        if (($meta['type'] ?? 'text') === 'datetime') {
            if ($value === '') {
                return '';
            }

            $timestamp = strtotime(str_replace('T', ' ', $value));
            if ($timestamp === false) {
                return '';
            }

            return date('Y-m-d\TH:i', $timestamp);
        }

        if (isset($meta['max_length'])) {
            $value = substr($value, 0, (int) $meta['max_length']);
        }

        return $value;
    }

    private function upsertSystemSetting(string $key, string $value, string $updatedBy): void
    {
        $updated = DB::table('system_settings')
            ->where('setting_name', $key)
            ->update([
                'setting_value' => $value,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('system_settings')->insert([
                'setting_name' => $key,
                'setting_value' => $value,
                'updated_by' => $updatedBy,
                'updated_at' => now(),
            ]);
        }
    }

    private function getSettingValue(string $settingName): ?string
    {
        try {
            $value = DB::table('system_settings')
                ->where('setting_name', $settingName)
                ->orderByDesc('id')
                ->value('setting_value');

            return $value === null ? null : (string) $value;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function isApprovalFreezeEnabled(): bool
    {
        return $this->getSettingValue('freeze_approvals') === '1';
    }

    private function updateAutoApproveSetting(string $adminId, bool $autoApprove): int
    {
        $autoApproveInt = $autoApprove ? 1 : 0;
        $this->upsertSystemSetting('auto_approve_students', (string) $autoApproveInt, $adminId);

        $approvedCount = 0;
        if ($autoApprove) {
            $approvedCount = DB::table('student_info')
                ->where('status', 'pending')
                ->update(['status' => 'approved']);

            $this->writeAdminAuditLog(
                $adminId,
                'approval_mode_update',
                'auto_approve_students',
                'Enabled auto-approval for student registrations.',
                ['auto_approve' => 1, 'auto_approved_pending_count' => $approvedCount]
            );
        } else {
            $this->writeAdminAuditLog(
                $adminId,
                'approval_mode_update',
                'auto_approve_students',
                'Disabled auto-approval for student registrations.',
                ['auto_approve' => 0]
            );
        }

        return $approvedCount;
    }

    private function performStudentAccountAction(string $adminId, string $studentId, string $action): array
    {
        $action = trim(strtolower($action));
        $updateData = null;
        $message = '';

        if ($action === 'approve') {
            $updateData = ['status' => 'approved', 'approved_by' => $adminId];
            $message = 'Account approved successfully.';
        } elseif ($action === 'reject') {
            $updateData = ['status' => 'rejected', 'approved_by' => $adminId];
            $message = 'Account rejected.';
        } elseif ($action === 'revert_pending') {
            $updateData = ['status' => 'pending', 'approved_by' => null];
            $message = 'Account reverted to pending status.';
        } else {
            return [
                'success' => false,
                'message' => 'Invalid account action.',
                'status' => 422,
            ];
        }

        $updated = DB::table('student_info')
            ->where('student_number', $studentId)
            ->update($updateData);

        if ($updated <= 0) {
            return [
                'success' => false,
                'message' => 'Student account not found.',
                'status' => 404,
            ];
        }

        $this->writeAdminAuditLog(
            $adminId,
            'account_action',
            'student_account',
            'Performed account action on student account.',
            ['student_id' => $studentId, 'action' => $action]
        );

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    private function performBulkStudentAccountAction(string $adminId, string $action, array $selectedStudents): array
    {
        $action = trim(strtolower($action));
        if ($action !== 'approve_selected' && $action !== 'reject_selected') {
            return [
                'success' => false,
                'message' => 'Invalid bulk action.',
                'status' => 422,
            ];
        }

        $status = $action === 'approve_selected' ? 'approved' : 'rejected';
        $updated = DB::table('student_info')
            ->whereIn('student_number', $selectedStudents)
            ->update([
                'status' => $status,
                'approved_by' => $adminId,
            ]);

        $this->writeAdminAuditLog(
            $adminId,
            'bulk_account_action',
            'student_accounts',
            'Performed bulk account action on selected student accounts.',
            [
                'action' => $action,
                'selected_count' => count($selectedStudents),
                'selected_student_ids_preview' => array_slice($selectedStudents, 0, 25),
            ]
        );

        return [
            'success' => true,
            'message' => count($selectedStudents) . ' accounts ' . ($status === 'approved' ? 'approved.' : 'rejected.'),
            'updated_count' => (int) $updated,
        ];
    }

    private function writeAdminAuditLog(string $adminId, string $actionType, string $target, string $summary, array $metadata = []): void
    {
        try {
            DB::table('admin_audit_logs')->insert([
                'admin_id' => $adminId,
                'action_type' => $actionType,
                'action_target' => $target,
                'summary' => $summary,
                'metadata_json' => empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Keep audit failures from breaking the user flow.
        }
    }
}
