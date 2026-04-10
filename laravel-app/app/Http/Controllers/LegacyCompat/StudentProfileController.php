<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class StudentProfileController extends Controller
{
    public function view(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $context = trim((string) $request->input('profile_context', 'student'));
            if (!in_array($context, ['student', 'admin', 'adviser', 'program_coordinator'], true)) {
                $context = 'student';
            }

            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student ID provided.',
                ], 422);
            }

            $authorizationError = $this->authorizeProfileView($request, $studentId, $context);
            if ($authorizationError !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $authorizationError,
                ], 403);
            }

            $student = DB::table('student_info')
                ->select([
                    DB::raw('student_number as student_id'),
                    'last_name',
                    'first_name',
                    'middle_name',
                    'email',
                    DB::raw('contact_number as contact_no'),
                    DB::raw("CONCAT_WS(', ', house_number_street, brgy, town, province) as address"),
                    DB::raw('date_of_admission as admission_date'),
                    'picture',
                ])
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

    public function update(Request $request): JsonResponse
    {
        try {
            if ($request->method() !== 'POST') {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid request method.',
                ], 405);
            }

            if (!$this->isBridgeAuthorized($request)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            $context = trim((string) $request->input('profile_context', 'student'));
            if (!in_array($context, ['student', 'admin', 'adviser', 'program_coordinator'], true)) {
                $context = 'student';
            }

            if ($studentId === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'No student ID provided',
                ], 422);
            }

            $authorizationError = $this->authorizeProfileUpdate($request, $studentId, $context);
            if ($authorizationError !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $authorizationError,
                ], 403);
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

            $validated = $this->validateProfileUpdate($request);
            if (!$validated['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $validated['error'],
                ], 422);
            }

            $updatedFields = $validated['fields'];
            if (!empty($updatedFields)) {
                $dbFields = $this->mapFieldsToDatabase($updatedFields);
                if (!empty($dbFields)) {
                    DB::table('student_info')
                        ->where('student_number', $studentId)
                        ->update($dbFields);
                }
            }

            $pictureResult = ['success' => true, 'path' => null, 'error' => null];
            if ($request->hasFile('picture') && $request->file('picture')->isValid()) {
                $pictureResult = $this->storePicture($studentId, $request->file('picture'));
                if ($pictureResult['success']) {
                    DB::table('student_info')
                        ->where('student_number', $studentId)
                        ->update(['picture' => $pictureResult['path']]);
                } else {
                    if ($context === 'adviser') {
                        return response()->json([
                            'success' => true,
                            'warning' => true,
                            'message' => 'Profile updated (with warning: ' . $pictureResult['error'] . ')',
                            'updated_fields' => $updatedFields,
                            'picture_path' => null,
                        ]);
                    }

                    return response()->json([
                        'success' => false,
                        'message' => $pictureResult['error'],
                    ], 422);
                }
            }

            $response = [
                'success' => true,
                'message' => 'Student profile updated successfully!',
                'updated_fields' => $updatedFields,
                'picture_path' => $pictureResult['path'],
                'context' => $context,
            ];

            if (empty($updatedFields) && !$request->hasFile('picture')) {
                $response['message'] = 'No changes submitted.';
            }

            return response()->json($response);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating profile.',
            ], 500);
        }
    }

    private function validateProfileUpdate(Request $request): array
    {
        $fields = [];
        $errors = [];

        $email = trim((string) $request->input('email', ''));
        if ($email !== '') {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please provide a valid email address.';
            } elseif (!$this->isAllowedEmailDomain($email)) {
                $errors[] = 'Email domain is not allowed for student accounts.';
            } else {
                $fields['email'] = htmlspecialchars($email);
            }
        }

        $password = (string) $request->input('password', '');
        if ($password !== '') {
            $minLength = $this->policySettingInt('min_password_length', 8, 6, 64);
            if (strlen($password) < $minLength) {
                $errors[] = 'Password must be at least ' . $minLength . ' characters long.';
            } else {
                $fields['password'] = password_hash($password, PASSWORD_BCRYPT);
            }
        }

        $contact = trim((string) $request->input('contact_no', ''));
        if ($contact !== '') {
            $normalized = $this->normalizeContactNumber($contact);
            if ($normalized === '' || !preg_match('/^\+?[0-9]{7,15}$/', $normalized)) {
                $errors[] = 'Please enter a valid contact number (7 to 15 digits, optional + prefix).';
            } else {
                $fields['contact_no'] = $normalized;
            }
        }

        foreach (['last_name', 'first_name', 'middle_name', 'address', 'admission_date'] as $field) {
            $value = trim((string) $request->input($field, ''));
            if ($value !== '') {
                $fields[$field] = htmlspecialchars($value);
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'error' => implode(' ', $errors),
                'fields' => [],
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'fields' => $fields,
        ];
    }

    private function mapFieldsToDatabase(array $fields): array
    {
        $map = [
            'last_name' => 'last_name',
            'first_name' => 'first_name',
            'middle_name' => 'middle_name',
            'email' => 'email',
            'password' => 'password',
            'contact_no' => 'contact_number',
            'address' => 'house_number_street',
            'admission_date' => 'date_of_admission',
        ];

        $dbFields = [];
        foreach ($fields as $field => $value) {
            if (!array_key_exists($field, $map)) {
                continue;
            }
            $dbFields[$map[$field]] = $value;
        }

        return $dbFields;
    }

    private function storePicture(string $studentId, $file): array
    {
        try {
            if (!$file->isValid()) {
                return ['success' => false, 'path' => null, 'error' => 'Failed to upload picture.'];
            }

            $path = $file->getPathname();
            if (!@getimagesize($path)) {
                return ['success' => false, 'path' => null, 'error' => 'File is not a valid image.'];
            }

            if ((int) $file->getSize() <= 0) {
                return ['success' => false, 'path' => null, 'error' => 'The uploaded picture is empty.'];
            }

            if ((int) $file->getSize() > 5242880) {
                return ['success' => false, 'path' => null, 'error' => 'Picture file is too large (max 5MB).'];
            }

            $imageInfo = @getimagesize($path);
            if (!is_array($imageInfo) || empty($imageInfo['mime'])) {
                return ['success' => false, 'path' => null, 'error' => 'File is not a valid image.'];
            }

            $width = (int) ($imageInfo[0] ?? 0);
            $height = (int) ($imageInfo[1] ?? 0);
            if ($width <= 0 || $height <= 0) {
                return ['success' => false, 'path' => null, 'error' => 'Unable to read the uploaded image dimensions.'];
            }

            if ($width > 4096 || $height > 4096) {
                return ['success' => false, 'path' => null, 'error' => 'Picture dimensions are too large (max 4096x4096).'];
            }

            $ext = strtolower((string) $file->getClientOriginalExtension());
            $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
            $allowedMimeTypes = [
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'png' => ['image/png'],
                'gif' => ['image/gif'],
            ];
            if (!in_array($ext, $allowedTypes, true)) {
                return ['success' => false, 'path' => null, 'error' => 'Only JPG, JPEG, PNG & GIF files are allowed.'];
            }

            $imageMime = strtolower(trim((string) ($imageInfo['mime'] ?? '')));
            if (!in_array($imageMime, $allowedMimeTypes[$ext] ?? [], true)) {
                return ['success' => false, 'path' => null, 'error' => 'The uploaded image content does not match the file extension.'];
            }

            $detectedMime = '';
            if (class_exists(\finfo::class)) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $detectedMime = strtolower(trim((string) $finfo->file($path)));
            }
            if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimeTypes[$ext] ?? [], true)) {
                return ['success' => false, 'path' => null, 'error' => 'The uploaded image MIME type is not allowed.'];
            }

            $uploadDir = dirname(base_path()) . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                return ['success' => false, 'path' => null, 'error' => 'Failed to create uploads directory.'];
            }

            $uniqueName = uniqid('', true) . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $file->move($uploadDir, $uniqueName);
            @chmod($uploadDir . DIRECTORY_SEPARATOR . $uniqueName, 0644);

            return [
                'success' => true,
                'path' => 'uploads/' . $uniqueName,
                'error' => null,
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'path' => null, 'error' => 'Failed to upload picture.'];
        }
    }

    private function isAllowedEmailDomain(string $email): bool
    {
        $normalizedEmail = strtolower(trim($email));
        if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ((bool) preg_match('/@cvsu\.edu\.ph$/i', $normalizedEmail)) {
            return true;
        }

        $allowedDomainsRaw = (string) ($this->getSettingValue('allowed_email_domains') ?? '');
        $allowedDomains = array_values(array_filter(array_map(
            static fn ($domain): string => strtolower(trim((string) $domain)),
            explode(',', $allowedDomainsRaw)
        ), static fn (string $domain): bool => $domain !== ''));

        if (empty($allowedDomains)) {
            return true;
        }

        $emailDomain = strtolower((string) substr(strrchr($normalizedEmail, '@') ?: '', 1));
        return $emailDomain !== '' && in_array($emailDomain, $allowedDomains, true);
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function authorizeProfileUpdate(Request $request, string $studentId, string $context): ?string
    {
        if ($context === 'student') {
            $sessionStudentId = trim((string) $request->input('session_student_id', ''));
            if ($sessionStudentId === '' || !hash_equals($sessionStudentId, $studentId)) {
                return 'You can only update your own profile.';
            }

            return null;
        }

        if ($context === 'admin') {
            return trim((string) $request->input('admin_id', '')) === '' ? 'Unauthorized' : null;
        }

        if ($context === 'adviser') {
            return trim((string) $request->input('adviser_id', '')) === '' ? 'Unauthorized' : null;
        }

        if ($context === 'program_coordinator') {
            return trim((string) $request->input('coordinator_id', '')) === '' ? 'Unauthorized' : null;
        }

        return 'Unauthorized';
    }

    private function authorizeProfileView(Request $request, string $studentId, string $context): ?string
    {
        if ($context === 'student') {
            $sessionStudentId = trim((string) $request->input('session_student_id', ''));
            if ($sessionStudentId === '' || !hash_equals($sessionStudentId, $studentId)) {
                return 'You can only view your own profile.';
            }

            return null;
        }

        if ($context === 'admin') {
            return trim((string) $request->input('admin_id', '')) === '' ? 'Unauthorized' : null;
        }

        if ($context === 'adviser') {
            return trim((string) $request->input('adviser_id', '')) === '' ? 'Unauthorized' : null;
        }

        if ($context === 'program_coordinator') {
            return trim((string) $request->input('coordinator_id', '')) === '' ? 'Unauthorized' : null;
        }

        return 'Unauthorized';
    }

    private function normalizeContactNumber(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $hasPlusPrefix = str_starts_with($value, '+');
        $digitsOnly = preg_replace('/\D+/', '', $value);
        if ($digitsOnly === null) {
            return '';
        }

        return $hasPlusPrefix ? ('+' . $digitsOnly) : $digitsOnly;
    }

    private function policySettingInt(string $key, int $default, int $min, int $max): int
    {
        $raw = DB::table('system_settings')
            ->where('setting_name', $key)
            ->orderByDesc('id')
            ->value('setting_value');

        $value = is_numeric($raw) ? (int) $raw : $default;
        if ($value < $min) {
            $value = $min;
        }
        if ($value > $max) {
            $value = $max;
        }

        return $value;
    }
}
