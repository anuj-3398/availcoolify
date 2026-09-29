<x-layout-simple>
    <x-auth.shell title="AvailCoolify" description="Review your invitation to join a team.">
        <div class="flex flex-col gap-4">
            <div class="auth-guidance">
                <x-reicon name="teams" class="mt-0.5 size-4 shrink-0" />
                <p>You have been invited to collaborate on Coolify.</p>
            </div>

            <dl class="divide-y divide-neutral-200 rounded-lg border border-neutral-200 text-sm dark:divide-white/10 dark:border-white/10">
                <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                    <dt class="text-neutral-500 dark:text-fg-dim">Team</dt>
                    <dd class="min-w-0 truncate font-medium text-neutral-900 dark:text-white">{{ $team->name }}</dd>
                </div>
                <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                    <dt class="text-neutral-500 dark:text-fg-dim">Role</dt>
                    <dd class="font-medium text-neutral-900 dark:text-white">{{ ucfirst($invitation->role) }}</dd>
                </div>
                @if ($invitation->role === 'guest')
                    @php
                        $guestProjects = \App\Models\Project::where('team_id', $team->id)
                            ->whereIn('id', $invitation->avail_project_ids ?? [])
                            ->orderBy('name')
                            ->pluck('name');
                    @endphp
                    <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                        <dt class="text-neutral-500 dark:text-fg-dim">Projects</dt>
                        <dd class="min-w-0 truncate text-right font-medium text-neutral-900 dark:text-white">
                            {{ $guestProjects->isEmpty() ? 'None yet' : $guestProjects->join(', ') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4 px-3 py-2.5">
                        <dt class="text-neutral-500 dark:text-fg-dim">Access</dt>
                        <dd class="font-medium text-neutral-900 dark:text-white">
                            {{ availInvitationAccessLabel($invitation) }}</dd>
                    </div>
                @endif
            </dl>

            @if ($alreadyMember)
                <x-auth.alert type="warning">You are already a member of this team. Dismiss the invitation to continue.</x-auth.alert>
            @endif

            <form method="POST" action="{{ $formAction }}">
                @csrf
                @isset($token)
                    <input type="hidden" name="token" value="{{ $token }}">
                @endisset
                <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                    {{ $alreadyMember ? 'Dismiss invitation' : 'Accept invitation' }}
                </x-forms.button>
            </form>
        </div>
    </x-auth.shell>
</x-layout-simple>
