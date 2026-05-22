<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->header('x-user-id');
        $role = $request->header('x-user-role');

        if (! $userId || strtolower((string) $role) !== UserRole::Admin->value) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Cross-check role against the database to prevent header spoofing
        $user = User::find($userId);

        if (! $user || $user->role !== UserRole::Admin) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
