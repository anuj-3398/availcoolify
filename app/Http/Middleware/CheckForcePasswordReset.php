<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckForcePasswordReset
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->user()) {
            if ($request->path() === 'auth/link') {
                auth()->logout();
                request()->session()->invalidate();
                request()->session()->regenerateToken();

                return $next($request);
            }
            // Avail: Clerk is the only login, so a Coolify password is never required.
            $force_password_reset = false;
            if ($force_password_reset) {
                if ($request->routeIs('auth.force-password-reset') || $request->path() === 'force-password-reset' || $request->path() === 'two-factor-challenge' || $request->path() === 'livewire/update' || $request->path() === 'logout') {
                    return $next($request);
                }

                return redirect()->route('auth.force-password-reset');
            }
        }

        return $next($request);
    }
}
