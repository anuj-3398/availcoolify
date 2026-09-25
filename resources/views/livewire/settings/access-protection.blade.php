<div>
    <x-slot:title>Access Protection</x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex min-w-0 flex-col gap-6">
            <x-application.settings-section title="Access protection"
                helper="Turn Clerk login on for a whole environment. Every app in it, production URLs and PR previews, then only lets members of the app's team in.">
                <p class="text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">
                    Applies to apps built from Git, a Dockerfile or an image (not Docker Compose apps or services).
                    Changes reach an app on its next deploy. Apps can also be protected one by one under
                    <strong>Security &rarr; Authentication</strong>.
                </p>
            </x-application.settings-section>

            @forelse ($projects as $project)
                <x-application.settings-section :title="$project->name"
                    wire:key="access-protection-project-{{ $project->id }}">
                    @if ($project->team && $project->team->id !== currentTeam()?->id)
                        <x-slot:actions>
                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">{{ $project->team->name }}</span>
                        </x-slot:actions>
                    @endif
                    <div class="flex flex-col divide-y divide-neutral-200 dark:divide-white/[0.07]">
                        @forelse ($project->environments as $environment)
                            <div wire:key="access-protection-environment-{{ $environment->id }}"
                                class="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                                <div class="min-w-0">
                                    <div class="truncate text-[13px] font-medium text-black dark:text-white">
                                        {{ $environment->name }}</div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $environment->applications_count }}
                                        {{ str('app')->plural($environment->applications_count) }}
                                        &middot;
                                        {{ $environment->preview_guard_enabled ? 'Clerk login required' : 'Public' }}
                                    </div>
                                </div>
                                <button type="button" role="switch"
                                    aria-checked="{{ $environment->preview_guard_enabled ? 'true' : 'false' }}"
                                    aria-label="Require Clerk login for {{ $project->name }} / {{ $environment->name }}"
                                    wire:click="toggle({{ $environment->id }})" wire:loading.attr="disabled"
                                    @class([
                                        'relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:cursor-wait disabled:opacity-60',
                                        'bg-coollabs' => $environment->preview_guard_enabled,
                                        'bg-neutral-300 dark:bg-white/[0.15]' => !$environment->preview_guard_enabled,
                                    ])>
                                    <span @class([
                                        'inline-block size-4 rounded-full bg-white shadow transition-transform',
                                        'translate-x-[18px]' => $environment->preview_guard_enabled,
                                        'translate-x-0.5' => !$environment->preview_guard_enabled,
                                    ])></span>
                                </button>
                            </div>
                        @empty
                            <p class="text-[12px] text-neutral-500 dark:text-fg-faint">No environments.</p>
                        @endforelse
                    </div>
                </x-application.settings-section>
            @empty
                <x-application.settings-section title="No projects">
                    <p class="text-[12px] text-neutral-500 dark:text-fg-faint">Create a project to protect its environments.</p>
                </x-application.settings-section>
            @endforelse
        </div>
    </x-settings.layout>
</div>
