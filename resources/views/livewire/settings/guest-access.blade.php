<div>
    <x-slot:title>Guest Access</x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-application.settings-section title="Guest access"
                helper="Guests see only the projects ticked here, read-only, until their access ends.">
                <x-slot:actions>
                    <a href="{{ route('team.member.index') }}" {{ wireNavigate() }} class="button">
                        <x-reicon name="plus" class="size-3.5" />
                        Invite guest
                    </a>
                </x-slot:actions>
                <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    Changes apply right away. Members and admins aren't listed: they see every project.
                    Guests get an email {{ $warningDays }} days before their access ends, and when it ends, once
                    transactional email is configured.
                </p>
            </x-application.settings-section>

            <x-application.settings-section title="Guests" flush>
                @if ($guests->isEmpty() && $invitations->isEmpty())
                    <x-empty size="sm" title="No guests yet"
                        description="Invite someone as a Guest from Team → Members." />
                @elseif ($projects->isEmpty())
                    <x-empty size="sm" title="No projects yet" description="Create a project to share it with guests." />
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[640px] text-[12px]">
                            <thead>
                                <tr class="border-b border-neutral-200 text-left text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:text-fg-faint">
                                    <th class="px-3 py-2 font-medium">Guest</th>
                                    @foreach ($projects as $project)
                                        <th wire:key="guest-access-head-{{ $project->id }}"
                                            class="max-w-32 truncate px-2 py-2 text-center font-medium" title="{{ $project->name }}">
                                            {{ $project->name }}</th>
                                    @endforeach
                                    <th class="px-3 py-2 font-medium">Access until</th>
                                    <th class="px-3 py-2"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($guests as $guest)
                                    @php
                                        $expiresAt = data_get($guest, 'pivot.guest_expires_at') ? \Illuminate\Support\Carbon::parse($guest->pivot->guest_expires_at) : null;
                                        $guestProjectIds = $grants->get($guest->id, []);
                                        $daysLeft = $expiresAt ? (int) ceil(now()->diffInDays($expiresAt, false)) : null;
                                    @endphp
                                    <tr wire:key="guest-access-row-{{ $guest->id }}"
                                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                        <td class="px-3 py-2.5">
                                            <div class="truncate font-medium text-black dark:text-fg">{{ $guest->name }}</div>
                                            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $guest->email }}</div>
                                        </td>
                                        @foreach ($projects as $project)
                                            <td wire:key="guest-access-{{ $guest->id }}-{{ $project->id }}" class="px-2 py-2.5 text-center">
                                                <input type="checkbox" @checked(in_array($project->id, $guestProjectIds, true))
                                                    wire:click="toggle({{ $guest->id }}, {{ $project->id }})"
                                                    aria-label="{{ $guest->email }} can see {{ $project->name }}"
                                                    class="rounded border-neutral-300 dark:border-white/20">
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2.5 whitespace-nowrap">
                                            @if (! $expiresAt)
                                                <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">No expiry</span>
                                            @elseif ($expiresAt->isPast())
                                                <span class="rounded-full bg-red-100 px-2 py-0.5 text-[11px] text-red-700 dark:bg-red-500/15 dark:text-red-300">Expired {{ $expiresAt->format('M j') }}</span>
                                            @elseif ($daysLeft <= $warningDays)
                                                <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] text-amber-800 dark:bg-amber-500/15 dark:text-amber-300">{{ $expiresAt->format('M j') }} &middot; {{ $daysLeft }} {{ str('day')->plural($daysLeft) }} left</span>
                                            @else
                                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-[11px] text-green-800 dark:bg-green-500/15 dark:text-green-300">{{ $expiresAt->format('M j, Y') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right">
                                            <div class="relative inline-block text-left" x-data="{ open: false, date: '' }"
                                                @keydown.escape.window="open = false" @click.outside="open = false">
                                                <button type="button" class="button h-7! px-2.5! text-[11px]!" @click="open = !open"
                                                    aria-haspopup="menu" :aria-expanded="open">Manage</button>
                                                <div x-show="open" x-cloak role="menu"
                                                    class="listbox-panel top-full! right-0! left-auto! mt-1! w-52! min-w-0!">
                                                    <button type="button" class="listbox-option justify-start!" wire:click="extend({{ $guest->id }}, 30)" @click="open = false">Extend 30 days</button>
                                                    <button type="button" class="listbox-option justify-start!" wire:click="extend({{ $guest->id }}, 90)" @click="open = false">Extend 90 days</button>
                                                    <div class="flex items-center gap-1.5 px-2 py-1.5">
                                                        <input type="date" x-model="date" min="{{ now()->addDay()->toDateString() }}"
                                                            aria-label="Access ends on" class="input h-7! py-0! text-[11px]!">
                                                        <button type="button" class="button h-7! px-2! text-[11px]!"
                                                            @click="$wire.setExpiry({{ $guest->id }}, date); open = false">Set</button>
                                                    </div>
                                                    @if ($expiresAt)
                                                        <button type="button" class="listbox-option justify-start!" wire:click="removeExpiry({{ $guest->id }})" @click="open = false">Remove expiry</button>
                                                    @endif
                                                    <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]"></div>
                                                    <button type="button" class="listbox-option justify-start! text-error! hover:text-error!"
                                                        wire:click="removeGuest({{ $guest->id }})"
                                                        @click="open = false">Remove guest</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach ($invitations as $invitation)
                                    @php($invitedProjectIds = collect($invitation->avail_project_ids ?? [])->map(fn ($id) => (int) $id)->all())
                                    <tr wire:key="guest-access-invite-{{ $invitation->id }}"
                                        class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                                        <td class="px-3 py-2.5">
                                            <div class="truncate font-medium text-black dark:text-fg">{{ $invitation->email }}</div>
                                            <div class="text-[11px] text-amber-700 dark:text-amber-300">Invitation pending</div>
                                        </td>
                                        @foreach ($projects as $project)
                                            <td wire:key="guest-access-invite-{{ $invitation->id }}-{{ $project->id }}" class="px-2 py-2.5 text-center">
                                                <input type="checkbox" @checked(in_array($project->id, $invitedProjectIds, true))
                                                    wire:click="toggleInvitation({{ $invitation->id }}, {{ $project->id }})"
                                                    aria-label="{{ $invitation->email }} will see {{ $project->name }}"
                                                    class="rounded border-neutral-300 dark:border-white/20">
                                            </td>
                                        @endforeach
                                        <td class="px-3 py-2.5 whitespace-nowrap text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ availInvitationAccessLabel($invitation) }}</td>
                                        <td class="px-3 py-2.5"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
