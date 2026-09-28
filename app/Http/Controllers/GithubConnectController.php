<?php

namespace App\Http\Controllers;

use App\Services\GithubConnect\GithubConnect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Avail: connect / disconnect the signed-in user's GitHub account (GitHub App user authorization).
 */
class GithubConnectController extends Controller
{
    private const SESSION_STATE = 'github_connect.state';

    private const SESSION_RETURN = 'github_connect.return';

    public function start(Request $request): RedirectResponse
    {
        if (! GithubConnect::isEnabled()) {
            return redirect()->route('dashboard')->with('error', 'GitHub connect is not set up yet.');
        }

        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_RETURN, $this->safeReturnPath($request->query('return')));

        return redirect()->away(GithubConnect::authorizeUrl($state));
    }

    /**
     * Install the platform app on a GitHub account or organisation. Anyone who can create
     * applications may start it; GitHub itself decides which accounts they may install on.
     * GitHub passes our state back to the callback, so the return trip is verified like a connect.
     */
    public function install(Request $request): RedirectResponse
    {
        if (! GithubConnect::isEnabled()) {
            return redirect()->route('dashboard')->with('error', 'GitHub connect is not set up yet.');
        }
        abort_unless(Gate::allows('createApplication'), 403, 'You do not have permission to add GitHub accounts.');

        return $this->redirectToInstall($request, $this->safeReturnPath($request->query('return')));
    }

    private function redirectToInstall(Request $request, string $return): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::SESSION_STATE, $state);
        $request->session()->put(self::SESSION_RETURN, $return);

        return redirect()->away(GithubConnect::installUrl().'?'.http_build_query(['state' => $state]));
    }

    /**
     * GitHub redirects here after the user authorizes the app, and also after installing it on an
     * account (with "Request user authorization during installation" enabled).
     */
    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(self::SESSION_STATE);
        $return = $request->session()->pull(self::SESSION_RETURN) ?? route('dashboard', absolute: false);

        if ($request->filled('error')) {
            return redirect($return)->with('error', 'GitHub authorization was cancelled.');
        }

        // Every connect and install starts in AvailCoolify, so the state must always match
        // (prevents someone planting their GitHub account on another user's session).
        $state = (string) $request->query('state', '');
        if (! is_string($expected) || ! hash_equals($expected, $state)) {
            return redirect($return)->with('error', 'GitHub authorization expired or was not started here. Please try again.');
        }

        $code = (string) $request->query('code', '');
        if ($code === '') {
            // Install without user authorization: nothing to exchange, just go back.
            return redirect($return)->with('success', 'GitHub app installation updated.');
        }

        try {
            $connection = GithubConnect::connectWithCode($request->user(), $code);
        } catch (\Throwable $e) {
            return redirect($return)->with('error', $e->getMessage());
        }

        // First connect and the app isn't on any account this user can reach yet: go straight on to
        // installing it (their own account, or an org they own). Not after an install, to avoid loops.
        if (! $request->filled('installation_id') && Gate::allows('createApplication')) {
            try {
                $hasInstallations = GithubConnect::installations($request->user())->isNotEmpty();
            } catch (\Throwable) {
                $hasInstallations = true;
            }
            if (! $hasInstallations) {
                return $this->redirectToInstall($request, $return);
            }
        }

        return redirect($return)->with('success', "GitHub connected as @{$connection->github_login}.");
    }

    public function disconnect(Request $request): RedirectResponse
    {
        GithubConnect::disconnect($request->user());

        return redirect($this->safeReturnPath($request->input('return')))->with('success', 'GitHub disconnected.');
    }

    /**
     * Only same-site relative paths, so the return value can't send users elsewhere.
     */
    private function safeReturnPath(mixed $path): string
    {
        $path = is_string($path) ? $path : '';
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return route('dashboard', absolute: false);
        }

        return $path;
    }
}
