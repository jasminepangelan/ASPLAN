<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProgramsController extends Controller
{
    public function index(): JsonResponse
    {
        $programs = [];

        try {
            $programs = DB::table('programs')
                ->orderBy('name')
                ->pluck('name')
                ->map(static fn ($value) => trim((string) $value))
                ->filter(static fn ($value) => $value !== '')
                ->unique()
                ->values()
                ->toArray();
        } catch (Throwable $e) {
            // Fallback for schemas that store program names in curriculum or student tables.
        }

        if (empty($programs)) {
            try {
                $programs = DB::table('cvsucarmona_courses')
                    ->select('programs as name')
                    ->whereNotNull('programs')
                    ->where('programs', '!=', '')
                    ->orderBy('programs')
                    ->pluck('name')
                    ->map(static fn ($value) => trim((string) $value))
                    ->filter(static fn ($value) => $value !== '')
                    ->unique()
                    ->values()
                    ->toArray();
            } catch (Throwable $e) {
                // Continue to next fallback.
            }
        }

        if (empty($programs)) {
            try {
                $programs = DB::table('student_info')
                    ->select('program')
                    ->whereNotNull('program')
                    ->where('program', '!=', '')
                    ->orderBy('program')
                    ->pluck('program')
                    ->map(static fn ($value) => trim((string) $value))
                    ->filter(static fn ($value) => $value !== '')
                    ->unique()
                    ->values()
                    ->toArray();
            } catch (Throwable $e) {
                // Keep empty list if all sources fail.
            }
        }

        return response()->json([
            'programs' => $programs,
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $incoming = $payload['programs'] ?? null;

        if (!is_array($incoming)) {
            return response()->json([
                'success' => false,
                'error' => 'Invalid request: programs array required',
            ], 400);
        }

        $programs = array_values(array_filter(array_map(static fn ($value) => trim((string) $value), $incoming), static fn ($value) => $value !== ''));

        if (empty($programs)) {
            return response()->json([
                'success' => false,
                'error' => 'Programs array cannot be empty',
            ], 400);
        }

        try {
            DB::statement(
                'CREATE TABLE IF NOT EXISTS programs (' .
                'id INT AUTO_INCREMENT PRIMARY KEY,' .
                'name VARCHAR(255) NOT NULL UNIQUE' .
                ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            DB::transaction(function () use ($programs): void {
                DB::table('programs')->delete();
                foreach ($programs as $program) {
                    DB::table('programs')->insert(['name' => $program]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Programs updated successfully',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
