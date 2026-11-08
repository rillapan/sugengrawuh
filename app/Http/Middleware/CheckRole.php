<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
     * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        if (!auth()->check()) {
            return redirect('login');
        }

        $user = auth()->user();

        // Admin always has access (unless specific restriction logic is needed, but typically admin bypasses)
        // However, per requirements, admin can manage "all features".
        // If we want to strictly enforce roles for specific routes (e.g., owner only), we might not want admin to automatically pass,
        // but typically Admin is a superuser.
        // Let's assume Admin has access to everything.
        $userRole = strtolower($user->role ?? '');
        $allowedRoles = array_map('strtolower', $roles);

        if ($user->isAdmin() || (in_array('admin', $allowedRoles) && $userRole === 'admin')) {
            return $next($request);
        }

        if (in_array($userRole, $allowedRoles)) {
            return $next($request);
        }

        abort(403, 'Unauthorized action.');
    }
}
