<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StudentIdController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $studentId = (string) ($request->input('student_id', ''));

        if ($studentId === '') {
            return response()->json([
                'exists' => false,
                'error' => 'No student_id provided',
            ]);
        }

        $exists = DB::table('student_info')
            ->where('student_number', $studentId)
            ->exists();

        if ($exists) {
            return response()->json([
                'exists' => true,
                'allowed' => false,
                'message' => 'Student number already exists in the system.',
            ]);
        }

        $this->ensureMasterlistTable();
        $allowed = DB::table('student_masterlist')
            ->where('student_number', $studentId)
            ->exists();

        return response()->json([
            'exists' => false,
            'allowed' => $allowed,
            'message' => $allowed ? '' : 'This student number is not included in the official masterlist. Please contact the administrator.',
        ]);
    }

    private function ensureMasterlistTable(): void
    {
        DB::statement("CREATE TABLE IF NOT EXISTS student_masterlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_number VARCHAR(32) NOT NULL,
            last_name VARCHAR(150) NOT NULL,
            first_name VARCHAR(150) NOT NULL,
            middle_initial VARCHAR(8) NULL,
            program VARCHAR(255) NOT NULL,
            source_filename VARCHAR(255) NULL,
            uploaded_by VARCHAR(120) NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_student_masterlist_student_number (student_number),
            KEY idx_student_masterlist_program (program),
            KEY idx_student_masterlist_uploaded_at (uploaded_at)
        )");
    }
}
