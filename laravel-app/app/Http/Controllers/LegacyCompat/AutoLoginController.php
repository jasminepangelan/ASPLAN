<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class AutoLoginController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        try {
            $cookieValue = (string) $request->input('remember_me', '');

            if ($cookieValue === '') {
                return response()->json(['redirect' => null]);
            }

            $parts = explode(':', $cookieValue);
            $studentId = '';
            $rememberToken = '';

            if (count($parts) === 3) {
                [$studentId, $rememberToken, $accountType] = $parts;
                if ($accountType !== 'student') {
                    return response()->json(['redirect' => null, 'clear_cookie' => true]);
                }
            } elseif (count($parts) === 2) {
                [$studentId, $rememberToken] = $parts;
            } else {
                return response()->json(['redirect' => null, 'clear_cookie' => true]);
            }

            if (!preg_match('/^[0-9]{1,20}$/', $studentId)) {
                return response()->json(['redirect' => null, 'clear_cookie' => true]);
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
                    'remember_token',
                    'program',
                ])
                ->where('student_number', $studentId)
                ->whereNotNull('remember_token')
                ->whereRaw('remember_token_expiry > NOW()')
                ->first();

            if ($student === null) {
                return response()->json(['redirect' => null, 'clear_cookie' => true]);
            }

            $studentData = (array) $student;
            if (!password_verify($rememberToken, (string) ($studentData['remember_token'] ?? ''))) {
                return response()->json(['redirect' => null, 'clear_cookie' => true]);
            }

            return response()->json([
                'redirect' => 'student/home_page_student.php',
                'session' => [
                    'student_id' => $studentData['student_id'],
                    'last_name' => $studentData['last_name'] ?? '',
                    'first_name' => $studentData['first_name'] ?? '',
                    'middle_name' => $studentData['middle_name'] ?? '',
                    'email' => $studentData['email'] ?? '',
                    'contact_no' => $studentData['contact_no'] ?? '',
                    'address' => $studentData['address'] ?? '',
                    'admission_date' => $studentData['admission_date'] ?? '',
                    'picture' => $studentData['picture'] ?? '',
                    'program' => $studentData['program'] ?? '',
                    'login_time' => time(),
                    'user_ip' => (string) ($request->input('ip', '') ?: $request->ip()),
                    'auto_login' => true,
                    'user_type' => 'student',
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['redirect' => null]);
        }
    }
}
