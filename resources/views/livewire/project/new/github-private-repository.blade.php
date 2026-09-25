<div class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
    @if ($githubConnectEnabled && $current_step === 'github_apps')
        {{-- Avail: Vercel-style GitHub connect --}}
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Import from GitHub</h2>
                    @if ($githubLogin)
                        <p>Connected as <strong>&#64;{{ $githubLogin }}</strong>. Pick an account or organisation; you'll see the repositories you can push to.</p>
                    @else
                        <p>Connect your GitHub account to see the repositories you can deploy.</p>
                    @endif
                </div>
                @if ($githubLogin)
                    <form method="POST" action="{{ route('github-connect.disconnect') }}">
                        @csrf
                        <input type="hidden" name="return" value="{{ $returnPath }}">
                        <x-forms.button type="submit">Disconnect GitHub</x-forms.button>
                    </form>
                @endif
            </div>
            <div class="application-settings-section-body p-0!">
                @if (! $githubLogin)
                    <div class="p-4">
                        <a class="button" href="{{ route('github-connect.start', ['return' => $returnPath]) }}">
                            <x-reicon name="sources" class="size-4" />
                            Continue with GitHub
                        </a>
                    </div>
                @elseif ($githubConnectError)
                    <div class="flex flex-col gap-3 p-4">
                        <p class="text-sm text-error">{{ $githubConnectError }}</p>
                        <a class="button w-fit" href="{{ route('github-connect.start', ['return' => $returnPath]) }}">Reconnect GitHub</a>
                    </div>
                @else
                    @forelse ($githubAccounts as $account)
                        <div wire:key="github-account-{{ $account['id'] }}"
                            class="flex items-center gap-3 border-b border-neutral-200 px-4 py-3 last:border-b-0 dark:border-white/[0.06]">
                            <button type="button" class="group flex min-w-0 flex-1 items-center gap-3 text-left"
                                wire:click.prevent="loadAccount({{ $account['id'] }})" wire:loading.class="coolbox-loading"
                                wire:loading.attr="disabled" wire:target="loadAccount({{ $account['id'] }})">
                                @if ($account['avatar'])
                                    <img src="{{ $account['avatar'] }}" alt="" class="size-9 shrink-0 rounded-lg" />
                                @else
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                                        <x-reicon name="sources" class="size-4" />
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-black group-hover:underline dark:text-fg">{{ $account['account'] }}</div>
                                    <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                        {{ $account['type'] === 'Organization' ? 'Organisation' : 'Personal account' }}
                                        &middot; {{ $account['selection'] === 'all' ? 'all repositories' : 'selected repositories' }}
                                    </p>
                                </div>
                            </button>
                            @if ($account['settings_url'])
                                <a target="_blank" rel="noopener noreferrer" class="text-xs text-neutral-500 underline underline-offset-2 dark:text-fg-dim"
                                    href="{{ $account['settings_url'] }}">Adjust repository access</a>
                            @endif
                        </div>
                    @empty
                        <p class="p-4 text-sm text-neutral-500 dark:text-fg-dim">
                            The AvailCoolify GitHub app isn't installed on any account or organisation you can access yet.
                        </p>
                    @endforelse
                    @if ($canAddGithubAccounts)
                        <div class="border-t border-neutral-200 px-4 py-3 dark:border-white/[0.06]">
                            <a class="text-sm font-medium underline underline-offset-2"
                                href="{{ route('github-connect.install', ['return' => $returnPath]) }}">+ Add GitHub account or organisation</a>
                        </div>
                    @endif
                @endif
            </div>
        </section>
    @elseif (! $githubConnectEnabled && $github_apps->isEmpty())
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>GitHub App</h2>
                    <p>Connect a GitHub App before selecting a private repository.</p>
                </div>
            </div>
            <x-empty title="No GitHub Apps"
                description="Create an app to grant Coolify access to selected repositories."
                icon-name="sources">
                <x-slot:contents>
                    <x-modal-input buttonTitle="+ Add GitHub App" title="New GitHub App" closeOutside="false">
                        <livewire:source.github.create />
                    </x-modal-input>
                </x-slot:contents>
            </x-empty>
        </section>
    @elseif ($current_step === 'github_apps')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose GitHub App</h2>
                    <p>Select the installation that can access the repository you want to deploy.</p>
                </div>
                <x-modal-input buttonTitle="+ Add GitHub App" title="New GitHub App" closeOutside="false">
                    <livewire:source.github.create />
                </x-modal-input>
            </div>
            <div class="application-settings-section-body p-0!">
                @foreach ($github_apps as $ghapp)
                    <button type="button"
                        class="group relative flex w-full items-center gap-3 border-b border-neutral-200 px-4 py-3 text-left transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.06] dark:hover:bg-white/[0.025]"
                        wire:click.prevent="loadRepositories({{ $ghapp->id }})"
                        wire:loading.class="coolbox-loading"
                        wire:loading.attr="disabled" wire:target="loadRepositories({{ $ghapp->id }})"
                        wire:key="{{ $ghapp->id }}">
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                            <x-reicon name="sources" class="size-4" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">
                                {{ data_get($ghapp, 'name') }}
                            </div>
                            <p class="mt-0.5 truncate text-xs text-neutral-500 dark:text-fg-dim">
                                {{ data_get($ghapp, 'html_url') }}
                            </p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    @elseif ($current_step === 'repository')
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>Choose repository</h2>
                    <p>Search repositories available through {{ $github_app->name }}.</p>
                </div>
                <div class="flex items-center gap-2">
                    <x-forms.button wire:click.prevent="loadRepositories({{ $github_app->id }})">
                        Refresh
                    </x-forms.button>
                    <a target="_blank" class="button" href="{{ getInstallationPath($github_app) }}">
                        GitHub access
                        <x-reicon name="arrow-right" class="size-3.5 -rotate-45" />
                    </a>
                </div>
            </div>
            <div class="application-settings-section-body">
                @if ($repositories->isNotEmpty())
                    <div class="flex items-end gap-2">
                        <x-forms.searchable-listbox id="selected_repository_id" label="Repository" required live
                            searchPlaceholder="Search repositories…"
                            :options="$repositories->map(fn ($repository) => [
                                'value' => data_get($repository, 'id'),
                                'label' => data_get($repository, 'full_name', data_get($repository, 'name')),
                            ])->values()->all()" />
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches"
                            wire:loading.attr="disabled"
                            wire:target="loadBranches,selected_repository_id">
                            <x-loading-on-button wire:loading.delay
                                wire:target="loadBranches,selected_repository_id" />
                            Load repository
                        </x-forms.button>
                    </div>
                @else
                    <x-empty size="sm" title="No repositories available"
                        :description="$githubConnectEnabled
                            ? 'You have no repositories with write access in this account, or the app can\'t see them. Adjust repository access on GitHub.'
                            : 'Review this GitHub App installation and grant access to a repository.'" />
                @endif
            </div>
        </section>

        @if ($branches->isNotEmpty())
            <form wire:submit="submit">
                <section class="application-settings-section">
                    <div class="application-settings-section-header">
                        <div>
                            <h2>Build configuration</h2>
                            <p>Choose the branch and build strategy for this application.</p>
                        </div>
                        <x-forms.button type="submit" wire:target="submit" isHighlighted>Continue</x-forms.button>
                    </div>
                    <div class="application-settings-section-body space-y-5">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.searchable-listbox id="selected_branch_name" label="Branch" required
                                searchPlaceholder="Search branches…"
                                :options="$branches->map(fn ($branch) => [
                                    'value' => data_get($branch, 'name'),
                                    'label' => data_get($branch, 'name'),
                                ])->values()->all()" />
                            <x-forms.listbox id="build_pack" label="Build pack" required live :options="[
                                ['value' => 'railpack', 'label' => 'Railpack'],
                                ['value' => 'nixpacks', 'label' => 'Nixpacks'],
                                ['value' => 'static', 'label' => 'Static'],
                                ['value' => 'dockerfile', 'label' => 'Dockerfile'],
                                ['value' => 'dockercompose', 'label' => 'Docker Compose'],
                            ]" />
                            @if ($show_is_static)
                                <x-forms.listbox id="is_static" label="Output type" onChange="instantSave"
                                    :options="[
                                        ['value' => false, 'label' => 'Web application'],
                                        ['value' => true, 'label' => 'Static site'],
                                    ]" />
                                <x-forms.input type="number" id="port" label="Port"
                                    :readonly="$is_static || $build_pack === 'static'"
                                    helper="Port the application listens on." />
                            @endif
                            @if ($is_static)
                                <x-forms.input id="publish_directory" label="Publish directory"
                                    helper="Directory containing the generated static assets." />
                            @endif
                        </div>

                        @if ($build_pack === 'dockercompose')
                            <div x-data="{
                                baseDir: @js($base_directory),
                                composeLocation: @js($docker_compose_location),
                                normalize(path) {
                                    if (!path || path.trim() === '') return '/';
                                    const normalized = path.trim().replace(/\/+$/, '');
                                    return normalized.startsWith('/') ? normalized : '/' + normalized;
                                },
                            }" class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                    label="Base directory" helper="Repository directory used as the build root."
                                    x-model="baseDir" @blur="baseDir = normalize(baseDir)" />
                                <x-forms.input placeholder="/docker-compose.yaml"
                                    wire:model.defer="docker_compose_location" label="Compose file"
                                    helper="Path relative to the base directory." x-model="composeLocation"
                                    @blur="composeLocation = normalize(composeLocation)" />
                                <p class="sm:col-span-2 text-xs text-neutral-500 dark:text-fg-dim">
                                    Resolved file:
                                    <code class="font-mono text-coollabs dark:text-warning"
                                        x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></code>
                                </p>
                            </div>
                        @else
                            <x-forms.input wire:model="base_directory" label="Base directory"
                                helper="Repository directory used as the build root." />
                        @endif
                    </div>
                </section>
            </form>
        @endif
    @endif
</div>
