<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class VerifySanctumFromCentral
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        try {
            $response = Http::withToken($token)
                ->timeout(5)
                ->get(config('services.auth.url') . '/api/me');

            if (!$response->successful()) {
                return response()->json([
                    'message' => 'Invalid token.'
                ], 401);
            }

            $user = $response->json('user');

            // Simpan user hasil validasi
            $request->attributes->set('central_user', $user);

            return $next($request);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Auth service unavailable.'
            ], 503);
        }
    }
}