<?php

namespace App\Http\Middleware;

use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avail: keeps guests on the read-only pages of the projects ticked for them, and shows
 * "your guest access has ended" once their access date has passed.
 */
class AvailGuestAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = $request->route();
        $teamId = currentTeam()?->id;
        if (! $user || ! $route || $teamId === null || ! availIsGuest($user, $teamId)) {
            return $next($request);
        }

        $requiresLogin = collect($route->gatherMiddleware())
            ->contains(fn ($middleware) => is_string($middleware) && ($middleware === 'auth' || str_starts_with($middleware, 'auth:')));
        if (! $requiresLogin || $route->getName() === 'logout') {
            return $next($request);
        }

        $expiresAt = availGuestExpiresAt($user, $teamId);
        if ($expiresAt?->isPast()) {
            return response()->view('avail.guest-access-ended', [
                'email' => $user->email,
                'expiredAt' => $expiresAt,
            ], 403);
        }

        if (! in_array($route->getName(), availGuestAllowedRoutes(), true)) {
            return redirect()->route('dashboard');
        }

        $projectUuid = $route->parameter('project_uuid');
        if (filled($projectUuid)) {
            $projectId = Project::where('team_id', $teamId)->where('uuid', $projectUuid)->value('id');
            if ($projectId === null || ! availGuestCanSeeProject($user, (int) $projectId, $teamId)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
