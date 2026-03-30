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

        return response()->json([
            'exists' => $exists,
        ]);
    }
}
