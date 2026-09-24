<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\OauthSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class PreviewGuardController extends Controller
{
    private const TOKEN_TTL = 120;

    private const COOKIE_TTL = 12 * 60 * 60;

    private const FORBIDDEN_MESSAGE = "You don't have enough privileges to access this page.";

    /**
     * Traefik ForwardAuth target. Runs without the web middleware group (no session, no
     * cookie encryption): the only state is the guard cookie scoped to the app's own domain.
     * 2xx lets the request through; any other response is returned to the visitor as-is.
     */
    public function check(Request $request)
    {
        $pullRequestId = (int) $request->query('pr', 0);
        $application = Application::where('uuid', (string) $request->query('app'))->first();
        if (! $application) {
            return $this->forbidden();
        }
        // Guard switched off but the container still carries the old labels until redeploy.
        if (! previewGuardEnabledFor($application, $pullRequestId)) {
            return response('', 200);
        }

        $host = strtolower((string) $request->header('X-Forwarded-Host'));
        $proto = $request->header('X-Forwarded-Proto') === 'https' ? 'https' : 'http';
        $uri = (string) ($request->header('X-Forwarded-Uri') ?: '/');
        if ($host === '' || ! previewGuardHosts($application, $pullRequestId)->contains($host)) {
            return $this->forbidden();
        }

        [$path, $query] = $this->splitUri($uri);

        // Returning from the dashboard with a one-time token: swap it for a domain cookie.
        if (array_key_exists(PREVIEW_GUARD_TOKEN_PARAM, $query)) {
            $token = $this->decode((string) $query[PREVIEW_GUARD_TOKEN_PARAM]);
            unset($query[PREVIEW_GUARD_TOKEN_PARAM]);
            $cleanUri = $path.($query ? '?'.http_build_query($query) : '');

            if ($this->validClaims($token, 'token', $application, $pullRequestId, $host)
                && Cache::add('preview-guard:nonce:'.$token['nonce'], true, self::TOKEN_TTL * 2)) {
                if (! $this->isMember($application, $token['uid'])) {
                    return $this->forbidden();
                }

                return $this->grantAccess($token['uid'], $application, $pullRequestId, $host, $proto, "{$proto}://{$host}{$cleanUri}");
            }

            return $this->redirectToLogin($application, $pullRequestId, "{$proto}://{$host}{$cleanUri}");
        }

        $session = $this->decode((string) $request->cookie(PREVIEW_GUARD_COOKIE));
        if ($this->validClaims($session, 'session', $application, $pullRequestId, $host)) {
            return $this->isMember($application, $session['uid'])
                ? response('', 200)
                : $this->forbidden();
        }

        return $this->redirectToLogin($application, $pullRequestId, "{$proto}://{$host}{$uri}");
    }

    /**
     * Dashboard-side step: requires a Clerk login, checks team membership, then sends the
     * visitor back to the app URL carrying a short-lived signed token.
     */
    public function start(Request $request)
    {
        $pullRequestId = (int) $request->query('pr', 0);
        $application = Application::where('uuid', (string) $request->query('app'))->first();
        $returnTo = (string) $request->query('return_to');
        $host = strtolower((string) parse_url($returnTo, PHP_URL_HOST));
        $scheme = parse_url($returnTo, PHP_URL_SCHEME);

        if (! $application
            || ! previewGuardEnabledFor($application, $pullRequestId)
            || ! in_array($scheme, ['http', 'https'], true)
            || ! previewGuardHosts($application, $pullRequestId)->contains($host)) {
            abort(404);
        }

        if (! auth()->check()) {
            session()->put('url.intended', $request->fullUrl());
            $clerkEnabled = OauthSetting::where('provider', 'clerk')->where('enabled', true)->exists();

            return redirect($clerkEnabled ? route('auth.redirect', 'clerk') : route('login'));
        }

        if (! $this->isMember($application, auth()->id())) {
            return $this->forbidden();
        }

        $token = Crypt::encryptString(json_encode([
            'type' => 'token',
            'uid' => auth()->id(),
            'app' => $application->uuid,
            'pr' => $pullRequestId,
            'host' => $host,
            'exp' => now()->addSeconds(self::TOKEN_TTL)->timestamp,
            'nonce' => Str::random(40),
        ]));

        [$path, $query] = $this->splitUri(parse_url($returnTo, PHP_URL_PATH).(($q = parse_url($returnTo, PHP_URL_QUERY)) ? "?{$q}" : ''));
        $query[PREVIEW_GUARD_TOKEN_PARAM] = $token;
        $port = parse_url($returnTo, PHP_URL_PORT);

        return redirect()->away("{$scheme}://{$host}".($port ? ":{$port}" : '').$path.'?'.http_build_query($query));
    }

    private function grantAccess(int $userId, Application $application, int $pullRequestId, string $host, string $proto, string $url)
    {
        $value = Crypt::encryptString(json_encode([
            'type' => 'session',
            'uid' => $userId,
            'app' => $application->uuid,
            'pr' => $pullRequestId,
            'host' => $host,
            'exp' => now()->addSeconds(self::COOKIE_TTL)->timestamp,
        ]));
        $cookie = Cookie::create(PREVIEW_GUARD_COOKIE, $value)
            ->withExpires(now()->addSeconds(self::COOKIE_TTL)->timestamp)
            ->withPath('/')
            ->withSecure($proto === 'https')
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);

        return response('', 302)
            ->header('Location', $url)
            ->header('Cache-Control', 'no-store')
            ->withCookie($cookie);
    }

    private function redirectToLogin(Application $application, int $pullRequestId, string $returnTo)
    {
        $base = rtrim(instanceSettings()->fqdn ?: config('app.url'), '/');
        $url = $base.'/preview-guard/authorize?'.http_build_query([
            'app' => $application->uuid,
            'pr' => $pullRequestId,
            'return_to' => $returnTo,
        ]);

        return response('', 302)->header('Location', $url)->header('Cache-Control', 'no-store');
    }

    private function forbidden()
    {
        return response()->view('errors.preview-guard-403', ['message' => self::FORBIDDEN_MESSAGE], 403)
            ->header('Cache-Control', 'no-store');
    }

    private function isMember(Application $application, int $userId): bool
    {
        $teamId = data_get($application, 'environment.project.team_id');
        if ($teamId === null) {
            return false;
        }

        // Short cache so removing someone from the team revokes access within seconds.
        return Cache::remember("preview-guard:member:{$teamId}:{$userId}", 30, function () use ($teamId, $userId) {
            return DB::table('team_user')->where('team_id', $teamId)->where('user_id', $userId)->exists();
        });
    }

    private function decode(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        try {
            $claims = json_decode(Crypt::decryptString($value), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($claims) ? $claims : null;
    }

    private function validClaims(?array $claims, string $type, Application $application, int $pullRequestId, string $host): bool
    {
        return $claims !== null
            && ($claims['type'] ?? null) === $type
            && ($claims['app'] ?? null) === $application->uuid
            && (int) ($claims['pr'] ?? -1) === $pullRequestId
            && ($claims['host'] ?? null) === $host
            && (int) ($claims['exp'] ?? 0) > now()->timestamp
            && is_int($claims['uid'] ?? null)
            && ($type !== 'token' || is_string($claims['nonce'] ?? null));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function splitUri(string $uri): array
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return [$path, $query];
    }
}
