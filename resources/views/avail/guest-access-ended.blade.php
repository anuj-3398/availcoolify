<x-layout-simple>
    <x-auth.shell title="AvailCoolify" description="Your guest access has ended">
        <div class="flex flex-col gap-4">
            <div class="auth-guidance">
                <x-reicon name="time-back" class="mt-0.5 size-4 shrink-0" />
                <p>Guest access for <span class="font-medium">{{ $email }}</span> ended on
                    {{ $expiredAt->format('M j, Y') }}. Ask an admin to extend it.</p>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <x-forms.button class="w-full justify-center" type="submit">Sign out</x-forms.button>
            </form>
        </div>
    </x-auth.shell>
</x-layout-simple>
