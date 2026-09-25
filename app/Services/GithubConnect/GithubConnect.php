<?php

namespace App\Services\GithubConnect;

use App\Models\GithubApp;
use App\Models\GithubConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Visus\Cuid2\Cuid2;

/**
 * Avail: Vercel-style GitHub connect.
 *
 * - One platform GitHub App (github_apps.is_avail_platform).
 * - One GithubApp row per installation: same app credentials, its own installation_id, owned by a
 *   team. Everything downstream (cloning, webhooks, previews, PR comments) keeps working unchanged
 *   because it already derives installation tokens from the row's installation_id.
 * - One GitHub connection per user, used only to decide which repositories that user may import:
 *   repos the app can see (installation) AND the user can push to.
 */
class GithubConnect
{
    public static function platformApp(): ?GithubApp
    {
        return GithubApp::where('is_avail_platform', true)
            ->whereNotNull('app_id')
            ->whereNotNull('client_id')
            ->first();
    }

    public static function isEnabled(): bool
    {
        return self::platformApp() !== null;
    }

    public static function isPlatformSource(?GithubApp $source): bool
    {
        $platform = self::platformApp();

        return $platform && $source && (string) $source->app_id === (string) $platform->app_id;
    }

    public static function connection(User $user): ?GithubConnection
    {
        return GithubConnection::where('user_id', $user->id)->first();
    }

    public static function authorizeUrl(string $state): string
    {
        $platform = self::requirePlatform();

        return rtrim($platform->html_url, '/').'/login/oauth/authorize?'.http_build_query([
            'client_id' => $platform->client_id,
            'redirect_uri' => route('github-connect.callback'),
            'state' => $state,
        ]);
    }

    /**
     * Link that installs the platform app on another GitHub account or organisation.
     */
    public static function installUrl(): string
    {
        $platform = self::requirePlatform();

        return rtrim($platform->html_url, '/').'/apps/'.self::appSlug($platform).'/installations/new';
    }

    public static function connectWithCode(User $user, string $code): GithubConnection
    {
        $platform = self::requirePlatform();
        $tokens = self::requestToken($platform, ['code' => $code, 'redirect_uri' => route('github-connect.callback')]);

        $githubUser = Http::withToken($tokens['access_token'])
            ->acceptJson()
            ->get(rtrim($platform->api_url, '/').'/user');
        if (! $githubUser->successful()) {
            throw new RuntimeException('Could not read your GitHub account.');
        }

        return GithubConnection::updateOrCreate(['user_id' => $user->id], [
            'github_user_id' => $githubUser->json('id'),
            'github_login' => $githubUser->json('login'),
            ...self::tokenAttributes($tokens),
        ]);
    }

    public static function disconnect(User $user): void
    {
        GithubConnection::where('user_id', $user->id)->delete();
    }

    /**
     * A valid user access token, refreshed when it has expired. Null when the user must reconnect.
     */
    public static function userToken(User $user): ?string
    {
        $connection = self::connection($user);
        if (! $connection) {
            return null;
        }

        if ($connection->access_token_expires_at && $connection->access_token_expires_at->isBefore(now()->addMinute())) {
            if (blank($connection->refresh_token)
                || ($connection->refresh_token_expires_at && $connection->refresh_token_expires_at->isPast())) {
                return null;
            }
            try {
                $tokens = self::requestToken(self::requirePlatform(), [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $connection->refresh_token,
                ]);
            } catch (RuntimeException) {
                return null;
            }
            $connection->update(self::tokenAttributes($tokens));
        }

        return $connection->access_token;
    }

    /**
     * Installations of the platform app that this user can access on GitHub.
     *
     * @return Collection<int, array{id: int, account: string, type: string, avatar: ?string, selection: ?string, settings_url: ?string}>
     */
    public static function installations(User $user): Collection
    {
        $platform = self::requirePlatform();
        $token = self::userToken($user) ?? throw new RuntimeException('Connect your GitHub account again.');

        $response = Http::withToken($token)->acceptJson()
            ->get(rtrim($platform->api_url, '/').'/user/installations', ['per_page' => 100]);
        if (! $response->successful()) {
            throw new RuntimeException('Could not list your GitHub installations.');
        }

        return collect($response->json('installations', []))
            ->filter(fn ($installation) => (string) data_get($installation, 'app_id') === (string) $platform->app_id)
            ->map(fn ($installation) => [
                'id' => (int) data_get($installation, 'id'),
                'account' => (string) data_get($installation, 'account.login'),
                'type' => (string) data_get($installation, 'account.type'),
                'avatar' => data_get($installation, 'account.avatar_url'),
                'selection' => data_get($installation, 'repository_selection'),
                'settings_url' => data_get($installation, 'html_url'),
            ])
            ->sortBy(fn ($installation) => strtolower($installation['account']))
            ->values();
    }

    /**
     * Ids of repositories in an installation that this user can push to (write access or higher).
     *
     * @return Collection<int, int>
     */
    public static function pushableRepositoryIds(User $user, int $installationId): Collection
    {
        $platform = self::requirePlatform();
        $token = self::userToken($user) ?? throw new RuntimeException('Connect your GitHub account again.');

        $ids = collect();
        for ($page = 1; $page <= 50; $page++) {
            $response = Http::withToken($token)->acceptJson()
                ->get(rtrim($platform->api_url, '/')."/user/installations/{$installationId}/repositories", [
                    'per_page' => 100,
                    'page' => $page,
                ]);
            if (! $response->successful()) {
                throw new RuntimeException('Could not list repositories for this GitHub account.');
            }
            $repositories = collect($response->json('repositories', []));
            $ids = $ids->concat($repositories
                ->filter(fn ($repository) => data_get($repository, 'permissions.push') || data_get($repository, 'permissions.admin'))
                ->pluck('id'));
            if ($repositories->count() < 100) {
                break;
            }
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values();
    }

    /**
     * The GithubApp row (source) for an installation within a team, created on first use from the
     * platform app's credentials.
     */
    public static function sourceForInstallation(Team $team, array $installation): GithubApp
    {
        $platform = self::requirePlatform();

        $existing = GithubApp::where('app_id', $platform->app_id)
            ->where('installation_id', $installation['id'])
            ->where(fn ($query) => $query->where('team_id', $team->id)->orWhere('is_system_wide', true))
            ->first();
        if ($existing) {
            return $existing;
        }

        $source = $platform->replicate(['uuid', 'is_avail_platform', 'installation_id', 'team_id', 'is_system_wide', 'name', 'organization']);
        $source->uuid = (string) new Cuid2;
        $source->name = $installation['account'].' (GitHub)';
        $source->organization = $installation['type'] === 'Organization' ? $installation['account'] : null;
        $source->installation_id = $installation['id'];
        $source->team_id = $team->id;
        $source->is_system_wide = false;
        $source->is_avail_platform = false;
        $source->save();

        return $source;
    }

    private static function requirePlatform(): GithubApp
    {
        return self::platformApp() ?? throw new RuntimeException('GitHub connect is not set up. Ask an admin to choose the platform GitHub App.');
    }

    private static function appSlug(GithubApp $platform): string
    {
        return Cache::remember("github-connect:slug:{$platform->app_id}", now()->addDay(), function () use ($platform) {
            $response = Http::withToken(generateGithubJwt($platform))->acceptJson()
                ->get(rtrim($platform->api_url, '/').'/app');

            return (string) ($response->json('slug') ?: $platform->name);
        });
    }

    private static function requestToken(GithubApp $platform, array $parameters): array
    {
        $response = Http::acceptJson()->asForm()->post(rtrim($platform->html_url, '/').'/login/oauth/access_token', [
            'client_id' => $platform->client_id,
            'client_secret' => $platform->client_secret,
            ...$parameters,
        ]);
        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException('GitHub did not accept the authorization: '.($response->json('error_description') ?? $response->json('error') ?? 'unknown error'));
        }

        return $response->json();
    }

    private static function tokenAttributes(array $tokens): array
    {
        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'access_token_expires_at' => isset($tokens['expires_in']) ? now()->addSeconds((int) $tokens['expires_in']) : null,
            'refresh_token_expires_at' => isset($tokens['refresh_token_expires_in']) ? now()->addSeconds((int) $tokens['refresh_token_expires_in']) : null,
        ];
    }
}
