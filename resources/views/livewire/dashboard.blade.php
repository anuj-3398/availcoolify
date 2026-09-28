<div class="application-settings-form w-full">
    <x-slot:title>
        Dashboard
    </x-slot>

    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))" />
    @endif

    @php
        $dashboardItemLimit = 8;
        $dashboardProjects = $projects->sortBy('name', SORT_NATURAL)->take($dashboardItemLimit);
        $hasTrafficAnalytics = $servers->contains(fn ($server) => $server->isTrafficAnalyticsEnabled());
    @endphp

    <div class="flex min-w-0 flex-col gap-8">
        @if ($pendingInvitations->isNotEmpty())
            <div class="flex min-w-0 flex-col gap-2">
                @foreach ($pendingInvitations as $invitation)
                    <x-callout type="info" title="Pending team invitation"
                        wire:key="dashboard-invitation-{{ $invitation->uuid }}">
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0">Team <span class="font-semibold">{{ $invitation->team->name }}</span>
                                invited you as {{ ucfirst($invitation->role) }}.</span>
                            <a href="{{ route('team.invitation.show', $invitation->uuid) }}" class="button shrink-0">
                                Review invitation
                            </a>
                        </div>
                    </x-callout>
                @endforeach
            </div>
        @endif

        <section class="mb-0! min-w-0">
            <x-section-heading title="Projects" subtitle="Your deployment workspaces"
                :href="route('project.index')" />

            @if ($dashboardProjects->isEmpty())
                <x-empty title="No projects yet"
                    description="Create your first deployment workspace from Projects."
                    icon-name="projects" size="sm" />
            @else
                <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($dashboardProjects as $project)
                        @php
                            $firstEnvironment = $project->environments->first();
                            $resourceCount = collect([
                                $project->applications_count,
                                $project->services_count,
                                $project->postgresqls_count,
                                $project->redis_count,
                                $project->keydbs_count,
                                $project->dragonflies_count,
                                $project->clickhouses_count,
                                $project->mongodbs_count,
                                $project->mysqls_count,
                                $project->mariadbs_count,
                                $project->sqlites_count,
                            ])->sum();
                        @endphp

                        <article
                            class="group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]">
                            <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                class="absolute inset-0 rounded-xl"
                                aria-label="Open {{ $project->name }}"></a>

                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    @if ($project->icon_path)
                                        <img src="{{ project_icon_url($project) }}"
                                            alt="{{ $project->name }} icon"
                                            class="h-full w-full rounded-lg object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $project->name }}
                                    </h3>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $project->description }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 dark:border-white/[0.06]">
                                <div class="relative z-10 flex min-w-0 items-center gap-3 text-[11px] font-medium text-neutral-500 dark:text-fg-dim">
                                    <span class="inline-flex items-center gap-1" data-tooltip="Environments"
                                        aria-label="Environments">
                                        <x-reicon name="layers" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                                        {{ $project->environments->count() }}
                                    </span>
                                    <span class="inline-flex items-center gap-1" data-tooltip="Resources"
                                        aria-label="Resources">
                                        <x-reicon name="grid" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                                        {{ $resourceCount }}
                                    </span>
                                </div>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5">
                                    @if ($firstEnvironment)
                                        @can('createAnyResource')
                                            <a href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $firstEnvironment->uuid,
                                            ]) }}"
                                                {{ wireNavigate() }}
                                                class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                title="Add resource"
                                                aria-label="Add resource to {{ $project->name }}">
                                                <x-reicon name="plus" class="size-3" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                            {{ wireNavigate() }}
                                            class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                            title="Project settings"
                                            aria-label="Open settings for {{ $project->name }}">
                                            <x-reicon name="settings" class="size-3" />
                                        </a>
                                    @endcan
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        {{-- Avail: deployments below projects; the Servers section is not shown on the dashboard. --}}
        <livewire:dashboard.active-deployments />

        @if ($hasTrafficAnalytics)
            <livewire:dashboard.traffic-analytics />
        @endif
    </div>
</div>
