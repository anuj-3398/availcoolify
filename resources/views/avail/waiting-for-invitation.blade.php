<x-layout-simple>
    <x-auth.shell title="AvailCoolify" description="Waiting for an invitation">
        <div class="flex flex-col gap-4">
            <div class="auth-guidance">
                <x-reicon name="mail" class="mt-0.5 size-4 shrink-0" />
                <p>You're signed in as <span class="font-medium">{{ $email }}</span>, but you don't have access yet.
                    Ask an admin to invite you, then open the invitation link.</p>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-forms.button class="w-full justify-center" type="submit">Sign out</x-forms.button>
            </form>
        </div>
    </x-auth.shell>
</x-layout-simple>
