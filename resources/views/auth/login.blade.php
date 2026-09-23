<x-layout-simple>
    <x-auth.shell title="Coolify" description="Sign in to manage your applications and infrastructure.">
        <div class="flex flex-col gap-4">
            @if (session('status'))
                <x-auth.alert type="success">{{ session('status') }}</x-auth.alert>
            @endif

            @if (session('error'))
                <x-auth.alert type="error">{{ session('error') }}</x-auth.alert>
            @endif

            @if ($errors->any())
                <x-auth.alert type="error">
                    <div class="flex flex-col gap-1">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                </x-auth.alert>
            @endif

            @if ($enabled_oauth_providers->isNotEmpty())
                <div class="flex flex-col gap-2">
                    @foreach ($enabled_oauth_providers as $provider_setting)
                        <x-forms.button class="w-full justify-center" type="button"
                            onclick="document.location.href='/auth/{{ $provider_setting->provider }}/redirect'">
                            @if ($provider_setting->provider !== 'oidc')
                                <img class="size-5 shrink-0 dark:invert"
                                    src="{{ asset('svgs/'.$provider_setting->provider.'.svg') }}" alt="" aria-hidden="true">
                            @endif
                            {{ $provider_setting->loginLabel() }}
                        </x-forms.button>
                    @endforeach
                </div>
            @else
                <x-auth.alert type="error">No login method is configured. Contact the administrator.</x-auth.alert>
            @endif
        </div>

        <x-slot:footer>
            <span>{{ $is_registration_enabled ? 'New accounts are created automatically on first Clerk sign-in.' : __('auth.registration_disabled') }}</span>
        </x-slot:footer>
    </x-auth.shell>
</x-layout-simple>
