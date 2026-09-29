<div>
    @if ($removedUsers->isNotEmpty())
        <x-application.settings-section title="Removed"
            description="Removed by an admin. They won't rejoin automatically when they sign in." flush>
            <div class="data-table flex flex-col">
                @foreach ($removedUsers as $removedUser)
                    <div wire:key="team-removed-{{ $removedUser->id }}"
                        class="data-table-row flex items-center justify-between gap-4 border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]">
                        <div class="min-w-0">
                            <div class="truncate text-[12px] font-medium text-black dark:text-fg">{{ $removedUser->email }}</div>
                            <div class="text-[11px] text-neutral-500 dark:text-fg-faint">Removed
                                {{ $removedUser->avail_removed_from_root_at?->diffForHumans() }}</div>
                        </div>
                        <button type="button" class="button h-7! px-2.5! text-[11px]!"
                            wire:click="allowBack({{ $removedUser->id }})">
                            Re-invite
                        </button>
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</div>
