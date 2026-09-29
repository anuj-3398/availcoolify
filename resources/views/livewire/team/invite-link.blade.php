<div>
    @can('manageInvitations', currentTeam())
        <form wire:submit="viaLink">
            <x-application.settings-section title="Invite a member"
                description="Create a reusable invitation link or deliver it by email.">
                <x-slot:actions>
                    @if (is_transactional_emails_enabled())
                        <x-forms.button type="button" wire:click.prevent="viaEmail">
                            <x-reicon name="notifications" class="size-3.5" />
                            Send email
                        </x-forms.button>
                    @endif
                    <x-forms.button type="submit" wire:target="viaLink"
                        defaultClass="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Generate link
                    </x-forms.button>
                </x-slot:actions>

                @if (!is_transactional_emails_enabled() && isInstanceAdmin())
                    <x-callout type="warning" title="Email delivery is not configured">
                        Configure transactional email in instance settings to send invitations directly.
                    </x-callout>
                @endif

                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="email" type="email" label="Email address"
                        placeholder="teammate@example.com" required />
                    <x-forms.listbox id="role" label="Role" :options="array_values(array_filter([
                        ['value' => 'guest', 'label' => 'Guest'],
                        ['value' => 'member', 'label' => 'Member'],
                        ['value' => 'admin', 'label' => 'Admin'],
                        auth()->user()->role() === 'owner' ? ['value' => 'owner', 'label' => 'Owner'] : null,
                    ]))" />
                </div>

                {{-- Avail: guests see only the projects ticked here, read-only, for a limited time. --}}
                <div x-cloak x-show="$wire.role === 'guest'" class="mt-4 flex flex-col gap-4">
                    <div>
                        <div class="mb-1.5 text-[12px] font-medium text-neutral-700 dark:text-fg-dim">Access duration</div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (['30' => '30 days', '60' => '60 days', '90' => '90 days', 'custom' => 'Custom', 'none' => 'No expiry'] as $value => $label)
                                <button type="button" wire:key="invite-duration-{{ $value }}"
                                    @click="$wire.accessDuration = '{{ $value }}'"
                                    :class="$wire.accessDuration === '{{ $value }}' ? 'button button-highlighted' : 'button'"
                                    class="h-7! px-2.5! text-[11px]!">{{ $label }}</button>
                            @endforeach
                        </div>
                        <div x-show="$wire.accessDuration === 'custom'" class="mt-2 max-w-xs">
                            <input type="date" wire:model="accessUntil" aria-label="Access ends on"
                                min="{{ now()->addDay()->toDateString() }}" class="input">
                            @error('accessUntil')
                                <p class="mt-1 text-[11px] text-error">{{ $message }}</p>
                            @enderror
                        </div>
                        <p class="mt-1.5 text-[11px] text-neutral-500 dark:text-fg-faint"
                            x-text="$wire.accessDuration === 'none'
                                ? 'Access never ends. Remove it in Settings → Guest access when needed.'
                                : ($wire.accessDuration === 'custom'
                                    ? 'Access ends at the end of the chosen day.'
                                    : 'Access ends ' + $wire.accessDuration + ' days after they accept.')"></p>
                    </div>

                    <div>
                        <div class="mb-1.5 text-[12px] font-medium text-neutral-700 dark:text-fg-dim">Projects</div>
                        @if ($guestProjects->isEmpty())
                            <p class="text-[12px] text-neutral-500 dark:text-fg-faint">No projects yet.</p>
                        @else
                            <div class="grid gap-1.5 sm:grid-cols-2">
                                @foreach ($guestProjects as $guestProject)
                                    <label wire:key="invite-project-{{ $guestProject->id }}"
                                        class="flex items-center gap-2 text-[12px] text-black dark:text-fg">
                                        <input type="checkbox" wire:model="guestProjectIds" value="{{ $guestProject->id }}"
                                            class="rounded border-neutral-300 dark:border-white/20">
                                        <span class="truncate">{{ $guestProject->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                @if (count(availAutoJoinDomains()) > 0)
                    <p class="mt-4 text-[11px] text-neutral-500 dark:text-fg-faint">
                        {{ collect(availAutoJoinDomains())->map(fn ($domain) => '@' . $domain)->join(', ') }}
                        emails join as members on their own. They don't need an invitation.
                    </p>
                @endif
            </x-application.settings-section>
        </form>
    @endcan
</div>
