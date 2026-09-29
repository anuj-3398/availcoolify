<div class="application-settings-form">
    <x-application.settings-section title="Guests"
        helper="Guests you add here see this project read-only, until their access ends. Manage everyone at once in Settings → Guest access.">
        @if ($otherGuests->isNotEmpty())
            <form wire:submit="add" class="mb-3 flex gap-2">
                <select wire:model="selectedGuestId" aria-label="Guest to add" class="select min-w-0 flex-1">
                    <option value="">Add a guest to this project…</option>
                    @foreach ($otherGuests as $otherGuest)
                        <option value="{{ $otherGuest->id }}">{{ $otherGuest->name }} ({{ $otherGuest->email }})</option>
                    @endforeach
                </select>
                <x-forms.button type="submit">Add</x-forms.button>
            </form>
        @endif

        @forelse ($guestsWithAccess as $guestWithAccess)
            <div wire:key="project-guest-{{ $guestWithAccess->id }}"
                class="flex items-center justify-between gap-4 border-b border-neutral-200 py-2.5 last:border-b-0 dark:border-white/[0.07]">
                <div class="min-w-0">
                    <div class="truncate text-[13px] font-medium text-black dark:text-white">{{ $guestWithAccess->name }}</div>
                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                        {{ $guestWithAccess->email }}
                        @if ($grantedBy->has($guestWithAccess->pivot->granted_by_user_id))
                            &middot; added by {{ $grantedBy->get($guestWithAccess->pivot->granted_by_user_id) }}
                        @endif
                    </div>
                </div>
                <button type="button" class="button h-7! px-2.5! text-[11px]!"
                    wire:click="remove({{ $guestWithAccess->id }})">Remove</button>
            </div>
        @empty
            <p class="text-[12px] text-neutral-500 dark:text-fg-faint">
                {{ $otherGuests->isEmpty() ? 'No guests yet. Invite someone as a Guest from Team → Members.' : 'No guests can see this project yet.' }}
            </p>
        @endforelse
    </x-application.settings-section>
</div>
