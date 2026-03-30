<?php

namespace App\Http\Controllers\LegacyCompat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SignoutController extends Controller
{
    public function signout(Request $request): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'redirect' => '../index.html',
            'clear_cookie' => true,
            'invalidate_session' => true,
        ]);
    }
}
