<?php

namespace App\Livewire\Settings;

use App\Models\Environment;
use App\Models\Project;
use Livewire\Component;

/**
 * Avail: turn Clerk login (Preview Guard) on or off for whole environments,
 * e.g. protect staging but leave production public.
 */
class AccessProtection extends Component
{
    public function mount()
    {
        if (! isInstanceAdmin()) {
            return redirect()->route('dashboard');
        }
    }

    public function toggle(int $environmentId)
    {
        if (! isInstanceAdmin()) {
            $this->dispatch('error', 'Only instance admins can change access protection.');

            return;
        }

        try {
            $environment = Environment::findOrFail($environmentId);
            $environment->preview_guard_enabled = ! $environment->preview_guard_enabled;
            $environment->save();

            $applications = refreshPreviewGuardLabelsForEnvironment($environment->fresh());
            $state = $environment->preview_guard_enabled ? 'on' : 'off';
            $count = $applications->count();

            $this->dispatch('success', $count === 0
                ? "Clerk login turned {$state} for {$environment->name}."
                : "Clerk login turned {$state} for {$environment->name}. Redeploy its {$count} ".str('app')->plural($count).' to apply.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        $projects = Project::query()
            ->with([
                'team',
                'environments' => fn ($query) => $query->orderBy('id')->withCount('applications'),
            ])
            ->orderBy('name')
            ->get();

        return view('livewire.settings.access-protection', [
            'projects' => $projects,
        ]);
    }
}
