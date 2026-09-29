<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\OauthSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                // First user (root) will be redirected to /settings instead of / on registration.
                if ($request->user()->currentTeam()?->id === 0) {
                    return redirect()->route('settings.index');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::registerView(function () {
            $isFirstUser = User::count() === 0;

            $settings = instanceSettings();
            if (! $settings->isPasswordRegistrationAllowed()) {
                return redirect()->route('login');
            }

            return view('auth.register', [
                'isFirstUser' => $isFirstUser,
            ]);
        });

        Fortify::loginView(function () {
            $settings = instanceSettings();
            $enabled_oauth_providers = OauthSetting::where('enabled', true)->get();
            $users = User::count();
            if ($users == 0 && $settings->isPasswordRegistrationAllowed()) {
                // If there are no users and password registration is allowed, redirect to registration.
                return redirect()->route('register');
            }

            return view('auth.login', [
                'is_registration_enabled' => $settings->isPasswordRegistrationAllowed(),
                'can_register_with_oauth' => $enabled_oauth_providers->contains(
                    fn (OauthSetting $oauthSetting) => $oauthSetting->couldBeEnabled() && $oauthSetting->allowsUserCreation()
                ),
                'enabled_oauth_providers' => $enabled_oauth_providers,
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
    return null; // Password login disabled -- Clerk is the sole login method
            $email = strtolower($request->email);
            $user = User::where('email', $email)->with('teams')->first();
            if (
                $user &&
                Hash::check($request->password, $user->password)
            ) {
                $user->updated_at = now();
                $user->save();

                // Pending team invitations are not accepted here; the user accepts
                // them explicitly on the invitation page (team.invitation.show).
                // Restore the last active team; only fall back when unambiguous.
                $team = $user->resolveStoredTeam();
                // Avail: no personal-team fallback (see availJoinRootTeam()).
                if (! $team && $user->teams->isEmpty() && availJoinRootTeam($user)) {
                    $team = \App\Models\Team::find(0);
                }
                if ($team) {
                    session(['currentTeam' => $team]);
                }
                // Otherwise (multiple teams, no stored choice) leave the session
                // team unset so the user is sent to the team-selection screen.

                return $user;
            }
        });
        Fortify::requestPasswordResetLinkView(function () {
            return view('auth.forgot-password');
        });
        Fortify::resetPasswordView(function ($request) {
            return view('auth.reset-password', ['request' => $request]);
        });
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);

        Fortify::confirmPasswordView(function () {
            return view('auth.confirm-password');
        });

        Fortify::twoFactorChallengeView(function () {
            return view('auth.two-factor-challenge');
        });
    }
}
