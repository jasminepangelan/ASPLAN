<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountCreationController extends Controller
{
    public function adminCreate(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $fullName = trim((string) $request->input('full_name', ''));
            $username = trim((string) $request->input('username', ''));
            $password = (string) $request->input('password', '');

            if ($fullName === '' || $username === '' || $password === '') {
                return response()->json(['status' => 'error', 'message' => 'Missing required fields: full_name, username, password'], 422);
            }

            $table = $this->resolveTableVariant('admins');
            if ($table === null) {
                return response()->json(['status' => 'error', 'message' => 'Admin table not found.'], 500);
            }

            if (DB::table($table)->where('username', $username)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Username already exists. Please choose a different username.'], 422);
            }

            DB::table($table)->insert([
                'full_name' => $fullName,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Admin account created successfully!',
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    public function adviserCreate(Request $request): JsonResponse
    {
        try {
            if (!$this->isBridgeAuthorized($request)) {
                return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 403);
            }

            $required = ['last_name', 'first_name', 'username', 'password', 'sex', 'pronoun', 'program'];
            foreach ($required as $field) {
                $value = $request->input($field);
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return response()->json(['status' => 'error', 'message' => 'Missing required fields: ' . $field], 422);
                }
            }

            $table = $this->resolveTableVariant('adviser');
            if ($table === null) {
                return response()->json(['status' => 'error', 'message' => 'Adviser table not found.'], 500);
            }

            $lastName = trim((string) $request->input('last_name'));
            $firstName = trim((string) $request->input('first_name'));
            $middleName = trim((string) $request->input('middle_name', ''));
            $username = trim((string) $request->input('username'));
            $password = (string) $request->input('password');
            $sex = trim((string) $request->input('sex'));
            $pronoun = trim((string) $request->input('pronoun'));
            $program = trim((string) $request->input('program'));

            if (DB::table($table)->where('username', $username)->exists()) {
                return response()->json(['status' => 'error', 'message' => 'Username already exists. Please choose a different username.'], 422);
            }

            $nextId = (int) (DB::table($table)->max('id') ?? 0) + 1;

            DB::table($table)->insert([
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'sex' => $sex,
                'pronoun' => $pronoun,
                'program' => $program,
                'id' => $nextId,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Adviser account created successfully!',
            ]);
        } catch (Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
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
}
