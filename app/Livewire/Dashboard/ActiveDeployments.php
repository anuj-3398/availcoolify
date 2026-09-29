<?php

namespace App\Livewire\Dashboard;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Support\Collection;
use Livewire\Component;

class ActiveDeployments extends Component
{
    public Collection $activeDeployments;

    public Collection $recentDeployments;

    public function mount(): void
    {
        $this->refreshDeployments();
    }

    public function refreshDeployments(): void
    {
        $serverIds = Server::ownedByCurrentTeamCached()->pluck('id');

        $columns = [
            'id',
            'application_id',
            'application_name',
            'deployment_url',
            'deployment_uuid',
            'pull_request_id',
            'server_name',
            'server_id',
            'status',
            'created_at',
            'finished_at',
        ];

        $baseQuery = ApplicationDeploymentQueue::query()
            ->with(['application.environment.project'])
            ->whereIn('server_id', $serverIds)
            ->when(availIsGuest(), fn ($query) => $query->whereIn('application_id', \App\Models\Application::ownedByCurrentTeam()->pluck('applications.id')));

        $this->activeDeployments = (clone $baseQuery)
            ->whereIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])
            ->orderBy('status')
            ->orderBy('id')
            ->limit(5)
            ->get($columns);

        // Avail: only the latest completed deployment of each application.
        $latestPerApplication = ApplicationDeploymentQueue::query()
            ->whereIn('server_id', $serverIds)
            ->whereNotIn('status', [
                ApplicationDeploymentStatus::IN_PROGRESS->value,
                ApplicationDeploymentStatus::QUEUED->value,
            ])
            ->selectRaw('max(id)')
            ->groupBy('application_id');

        $this->recentDeployments = (clone $baseQuery)
            ->whereIn('id', $latestPerApplication)
            ->orderByDesc('id')
            ->limit(5)
            ->get($columns);
    }

    public function render()
    {
        return view('livewire.dashboard.active-deployments');
    }
}
