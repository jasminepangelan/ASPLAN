<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProgramCoordinatorConnectionController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $required = ['last_name', 'first_name', 'username', 'password', 'sex', 'pronoun', 'program'];
            foreach ($required as $field) {
                $value = $request->input($field);
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Missing required fields: ' . $field,
                    ], 422);
                }
            }

            $lastName = trim((string) $request->input('last_name'));
            $firstName = trim((string) $request->input('first_name'));
            $middleName = trim((string) $request->input('middle_name', ''));
            $username = trim((string) $request->input('username'));
            $password = (string) $request->input('password');
            $sex = trim((string) $request->input('sex'));
            $pronoun = trim((string) $request->input('pronoun'));
            $programInput = $request->input('program');

            $table = $this->resolveTableVariant('program_coordinator');
            if ($table === null) {
                return response()->json(['status' => 'error', 'message' => 'Program coordinator table not found.'], 500);
            }

            $programs = $this->normalizePrograms($programInput);
            if (empty($programs)) {
                return response()->json(['status' => 'error', 'message' => 'At least one valid program is required.'], 422);
            }

            if (DB::table($table)->where('username', $username)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Username already exists. Please choose a different username.'], 422);
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $programColumnExists = Schema::hasColumn($table, 'program');
            $program = implode(', ', $programs);

            if ($programColumnExists) {
                DB::table($table)->insert([
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'username' => $username,
                    'password' => $hash,
                    'sex' => $sex,
                    'pronoun' => $pronoun,
                    'program' => $program,
                ]);
            } else {
                DB::table($table)->insert([
                    'last_name' => $lastName,
                    'first_name' => $firstName,
                    'middle_name' => $middleName !== '' ? $middleName : null,
                    'username' => $username,
                    'password' => $hash,
                    'sex' => $sex,
                    'pronoun' => $pronoun,
                ]);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Program Coordinator account has been created successfully.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function isBridgeAuthorized(Request $request): bool
    {
        return filter_var($request->input('bridge_authorized', false), FILTER_VALIDATE_BOOL);
    }

    private function resolveTableVariant(string $name): ?string
    {
        if (Schema::hasTable($name)) {
            return $name;
        }

        $plural = $name . 's';
        if (Schema::hasTable($plural)) {
            return $plural;
        }

        return null;
    }

    private function normalizePrograms(mixed $programInput): array
    {
        $allowedPrograms = [
            'Bachelor of Science in Computer Science',
            'Bachelor of Science in Information Technology',
            'Bachelor of Science in Computer Engineering',
            'Bachelor of Science in Industrial Technology',
            'Bachelor of Science in Hospitality Management',
            'Bachelor of Science in Business Administration - Major in Marketing Management',
            'Bachelor of Science in Business Administration - Major in Human Resource Management',
            'Bachelor of Secondary Education major in English',
            'Bachelor of Secondary Education major Math',
            'Bachelor of Secondary Education major in Science',
        ];

        $values = is_array($programInput) ? $programInput : [$programInput];
        $programs = [];
        foreach ($values as $program) {
            $program = trim((string) $program);
            if ($program !== '' && in_array($program, $allowedPrograms, true)) {
                $programs[] = $program;
            }
        }

        return array_values(array_unique($programs));
    }
}
