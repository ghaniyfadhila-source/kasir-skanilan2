<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CashierMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Expect Sanctum token via Authorization header or session
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        if ($user->role !== 'cashier') {
            return response()->json([
                'success' => false,
                'message' => 'Access denied. Cashier role required.'
            ], 403);
        }

        // Verify user has device_id linked to an active console
        if (!$user->device_id) {
            return response()->json([
                'success' => false,
                'message' => 'Cashier not linked to any console. Contact admin.'
            ], 403);
        }

        $console = \App\Models\Console::where('device_id', $user->device_id)
            ->where('is_active', true)
            ->first();

        if (!$console) {
            return response()->json([
                'success' => false,
                'message' => 'Linked console not found or inactive.'
            ], 403);
        }

        // Attach console to request for easy access
        $request->merge(['console' => $console]);

        return $next($request);
    }
}