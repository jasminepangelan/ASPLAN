<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class StudyPlanBootstrapController extends Controller
{
    public function studentBootstrap(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $studentId = trim((string) $request->input('student_id', ''));
            if ($studentId === '') {
                return response()->json(['success' => false, 'message' => 'Missing student ID.'], 422);
            }

            $student = DB::table('student_info')
                ->select(['last_name', 'first_name', 'middle_name', 'picture', 'program', 'curriculum_year', 'date_of_admission'])
                ->where('student_number', $studentId)
                ->first();

            if (!$student) {
                return response()->json(['success' => false, 'message' => 'Student record not found.'], 404);
            }

            $admissionYear = $this->resolveAdmissionYear((string) ($student->date_of_admission ?? ''), $studentId);

            return response()->json([
                'success' => true,
                'student' => [
                    'student_number' => $studentId,
                    'last_name' => (string) ($student->last_name ?? ''),
                    'first_name' => (string) ($student->first_name ?? ''),
                    'middle_name' => (string) ($student->middle_name ?? ''),
                    'picture' => !empty($student->picture) ? '../' . ltrim((string) $student->picture, '/\\') : '../pix/anonymous.jpg',
                    'program' => (string) ($student->program ?? ''),
                    'curriculum_year' => (string) ($student->curriculum_year ?? ''),
                    'date_of_admission' => (string) ($student->date_of_admission ?? ''),
                    'admission_year' => $admissionYear,
                    'full_name' => trim((string) ($student->last_name ?? '') . ', ' . (string) ($student->first_name ?? '') . ' ' . (string) ($student->middle_name ?? '')),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load study plan bootstrap.'], 500);
        }
    }

    public function adviserBootstrap(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
            }

            $adviserId = (int) $request->input('adviser_id', 0);
            $studentId = trim((string) $request->input('student_id', ''));
            if ($adviserId <= 0 || $studentId === '') {
                return response()->json(['success' => false, 'message' => 'Missing adviser or student ID.'], 422);
            }

            $adviserProgram = trim((string) DB::table('adviser')->where('id', $adviserId)->value('program'));
            $batches = DB::table('adviser_batch')
                ->where('adviser_id', $adviserId)
                ->pluck('batch')
                ->map(static fn ($batch): string => trim((string) $batch))
                ->filter(static fn (string $batch): bool => $batch !== '')
                ->values()
                ->all();

            if ($adviserProgram === '' || empty($batches)) {
                return response()->json([
                    'success' => true,
                    'access_granted' => false,
                    'message' => 'Access denied. Adviser batch/program assignment is missing.',
                    'adviser_program' => $adviserProgram,
                    'batches' => $batches,
                ]);
            }

            $student = DB::table('student_info')
                ->select(['student_number', 'last_name', 'first_name', 'middle_name', 'program', 'curriculum_year', 'date_of_admission'])
                ->where('student_number', $studentId)
                ->where('program', $adviserProgram)
                ->where(function ($query) use ($batches): void {
                    foreach (array_values($batches) as $index => $batch) {
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}('student_number', 'like', $batch . '%');
                    }
                })
                ->first();

            if (!$student) {
                return response()->json([
                    'success' => true,
                    'access_granted' => false,
                    'message' => 'Access denied. Student is not in your assigned batch/program.',
                    'adviser_program' => $adviserProgram,
                    'batches' => $batches,
                ]);
            }

            $admissionYear = $this->resolveAdmissionYear((string) ($student->date_of_admission ?? ''), $studentId);

            return response()->json([
                'success' => true,
                'access_granted' => true,
                'adviser_program' => $adviserProgram,
                'batches' => $batches,
                'student' => [
                    'student_number' => (string) ($student->student_number ?? ''),
                    'last_name' => (string) ($student->last_name ?? ''),
                    'first_name' => (string) ($student->first_name ?? ''),
                    'middle_name' => (string) ($student->middle_name ?? ''),
                    'program' => (string) ($student->program ?? ''),
                    'curriculum_year' => (string) ($student->curriculum_year ?? ''),
                    'date_of_admission' => (string) ($student->date_of_admission ?? ''),
                    'admission_year' => $admissionYear,
                    'full_name' => trim((string) ($student->last_name ?? '') . ', ' . (string) ($student->first_name ?? '') . ' ' . (string) ($student->middle_name ?? '')),
                ],
            ]);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Failed to load adviser study plan bootstrap.'], 500);
        }
    }

    private function resolveAdmissionYear(string $admissionDate, string $studentId): int
    {
        if ($admissionDate !== '' && strtotime($admissionDate) !== false) {
            return (int) date('Y', strtotime($admissionDate));
        }

        if (strlen($studentId) >= 4) {
            $candidateYear = (int) substr($studentId, 0, 4);
            $currentYear = (int) date('Y');
            if ($candidateYear >= 2000 && $candidateYear <= $currentYear) {
                return $candidateYear;
            }
        }

        return (int) date('Y') - 4;
    }
}
