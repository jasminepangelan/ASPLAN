<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class CsrfTokenController extends Controller
{
    public function getToken(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'token' => bin2hex(random_bytes(32)),
        ]);
    }
}
