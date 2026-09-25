<?php

namespace App\Livewire\Project\New;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Services\GithubConnect\GithubConnect;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;
use Livewire\Component;

class GithubPrivateRepository extends Component
{
    use AuthorizesRequests;

    public $current_step = 'github_apps';

    public $github_apps;

    // Avail: Vercel-style GitHub connect (one platform app, per-user GitHub connection).
    public bool $githubConnectEnabled = false;

    public ?string $githubLogin = null;

    public array $githubAccounts = [];

    public ?string $githubConnectError = null;

    public bool $canAddGithubAccounts = false;

    public string $returnPath = '/';

    public GithubApp $github_app;

    public $parameters;

    public $currentRoute;

    public $query;

    public $type;

    public int $selected_repository_id;

    #[Locked]
    public int $selected_github_app_id;

    public string $selected_repository_owner;

    public string $selected_repository_repo;

    public string $selected_branch_name = 'main';

    public $repositories;

    public int $total_repositories_count = 0;

    public $branches;

    public int $total_branches_count = 0;

    public int $port = 3000;

    public bool $is_static = false;

    public ?string $publish_directory = null;

    // In case of docker compose
    public ?string $base_directory = '/';

    public ?string $docker_compose_location = '/docker-compose.yaml';
    // End of docker compose

    protected int $page = 1;

    public $build_pack = 'railpack';

    public bool $show_is_static = true;

    public function mount()
    {
        $this->currentRoute = Route::currentRouteName();
        $this->parameters = get_route_parameters();
        $this->query = request()->query();
        $this->repositories = $this->branches = collect();
        $this->github_apps = GithubApp::ownedByCurrentTeam()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->get();

        $this->returnPath = request()->getRequestUri();
        $this->githubConnectEnabled = GithubConnect::isEnabled();
        if ($this->githubConnectEnabled) {
            $platformAppId = (string) GithubConnect::platformApp()->app_id;
            // Installations of the platform app are offered per user below, not as separate apps.
            $this->github_apps = $this->github_apps
                ->reject(fn (GithubApp $app) => (string) $app->app_id === $platformAppId)
                ->values();
            $this->canAddGithubAccounts = (bool) auth()->user()?->isAdmin();
            $this->loadGithubAccounts();
        }
    }

    public function loadGithubAccounts(): void
    {
        $this->githubAccounts = [];
        $this->githubConnectError = null;
        $connection = GithubConnect::connection(auth()->user());
        $this->githubLogin = $connection?->github_login;
        if (! $connection) {
            return;
        }

        try {
            $this->githubAccounts = GithubConnect::installations(auth()->user())->all();
        } catch (\Throwable $e) {
            $this->githubConnectError = $e->getMessage();
        }
    }

    /**
     * Open one of the user's GitHub accounts/organisations: its team-scoped source is created on
     * first use, then its repositories are listed (only those the user can push to).
     */
    public function loadAccount(int $installationId)
    {
        try {
            $this->authorize('create', Application::class);
            $installation = GithubConnect::installations(auth()->user())->firstWhere('id', $installationId);
            if (! $installation) {
                throw new \RuntimeException('This GitHub account is not available to you.');
            }
            $source = GithubConnect::sourceForInstallation(currentTeam(), $installation);
            $this->loadRepositories($source->id);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function updatedSelectedRepositoryId(): void
    {
        $this->loadBranches();
    }

    public function updatedBuildPack()
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            $this->show_is_static = true;
            if (! $this->is_static) {
                $this->port = 3000;
            }
        } elseif ($this->build_pack === 'static') {
            $this->show_is_static = false;
            $this->is_static = false;
            $this->port = 80;
        } else {
            $this->show_is_static = false;
            $this->is_static = false;
        }
    }

    public function loadRepositories(int $github_app_id): void
    {
        $this->repositories = collect();
        $this->branches = collect();
        $this->total_branches_count = 0;
        $this->page = 1;
        $this->selected_github_app_id = $github_app_id;
        $this->github_app = GithubApp::ownedByCurrentTeam()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->findOrFail($github_app_id);
        $token = generateGithubInstallationToken($this->github_app);
        $repositories = loadRepositoryByPage($this->github_app, $token, $this->page);
        $this->total_repositories_count = $repositories['total_count'];
        $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
        if ($this->repositories->count() < $this->total_repositories_count) {
            while ($this->repositories->count() < $this->total_repositories_count) {
                $this->page++;
                $repositories = loadRepositoryByPage($this->github_app, $token, $this->page);
                $this->total_repositories_count = $repositories['total_count'];
                $this->repositories = $this->repositories->concat(collect($repositories['repositories']));
            }
        }
        if (GithubConnect::isPlatformSource($this->github_app)) {
            // Only repositories the signed-in user can push to on GitHub.
            $pushable = GithubConnect::pushableRepositoryIds(auth()->user(), (int) $this->github_app->installation_id);
            $this->repositories = $this->repositories->whereIn('id', $pushable->all());
        }
        $this->repositories = $this->repositories->sortBy('name')->values();
        if ($this->repositories->count() > 0) {
            $this->selected_repository_id = data_get($this->repositories->first(), 'id');
        }
        $this->current_step = 'repository';
    }

    public function loadBranches()
    {
        $repository = $this->repositories->firstWhere('id', $this->selected_repository_id);
        $this->selected_repository_owner = data_get($repository, 'owner.login');
        $this->selected_repository_repo = data_get($repository, 'name');
        $this->branches = collect();
        $this->page = 1;
        $this->loadBranchByPage();
        if ($this->total_branches_count === 100) {
            while ($this->total_branches_count === 100) {
                $this->page++;
                $this->loadBranchByPage();
            }
        }
        $this->branches = sortBranchesByPriority($this->branches);
        $defaultBranch = data_get($repository, 'default_branch', 'main');
        $this->selected_branch_name = $this->branches->contains('name', $defaultBranch)
            ? $defaultBranch
            : data_get($this->branches, '0.name', 'main');
    }

    protected function loadBranchByPage()
    {
        $token = generateGithubInstallationToken($this->github_app);

        $response = Http::GitHub($this->github_app->api_url, $token)
            ->timeout(20)
            ->retry(3, 200, throw: false)
            ->get("/repos/{$this->selected_repository_owner}/{$this->selected_repository_repo}/branches", [
                'per_page' => 100,
                'page' => $this->page,
            ]);
        $json = $response->json();
        if ($response->status() !== 200) {
            return $this->dispatch('error', $json['message']);
        }

        $this->total_branches_count = count($json);
        $this->branches = $this->branches->concat(collect($json));
    }

    public function submit()
    {
        try {
            $this->authorize('create', Application::class);

            // Validate git repository parts and branch
            $validator = validator([
                'selected_repository_owner' => $this->selected_repository_owner,
                'selected_repository_repo' => $this->selected_repository_repo,
                'selected_branch_name' => $this->selected_branch_name,
                'docker_compose_location' => $this->docker_compose_location,
            ], [
                'selected_repository_owner' => 'required|string|regex:/^[a-zA-Z0-9\-_]+$/',
                'selected_repository_repo' => 'required|string|regex:/^[a-zA-Z0-9\-_\.]+$/',
                'selected_branch_name' => ['required', 'string', new ValidGitBranch],
                'docker_compose_location' => ValidationPatterns::filePathRules(),
            ]);

            if ($validator->fails()) {
                throw new \RuntimeException('Invalid repository data: '.$validator->errors()->first());
            }

            // Avail: re-check write access server-side; the picker list alone is not trusted.
            if (GithubConnect::isPlatformSource($this->github_app)
                && ! GithubConnect::pushableRepositoryIds(auth()->user(), (int) $this->github_app->installation_id)
                    ->contains($this->selected_repository_id)) {
                throw new \RuntimeException('You need write access to this repository on GitHub to deploy it.');
            }

            $destination_uuid = $this->query['destination'] ?? null;
            $destination = find_resource_destination_for_current_team($destination_uuid);
            if (! $destination) {
                throw new \Exception('Destination not found.');
            }
            $destination_class = $destination->getMorphClass();

            $project = Project::ownedByCurrentTeam()->where('uuid', $this->parameters['project_uuid'])->firstOrFail();
            $environment = $project->environments()->where('uuid', $this->parameters['environment_uuid'])->firstOrFail();

            $application = new Application([
                'name' => generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name),
                'repository_project_id' => $this->selected_repository_id,
                'git_repository' => str($this->selected_repository_owner)->trim()->toString().'/'.str($this->selected_repository_repo)->trim()->toString(),
                'git_branch' => str($this->selected_branch_name)->trim()->toString(),
                'build_pack' => $this->build_pack,
                'ports_exposes' => $this->port,
                'publish_directory' => $this->publish_directory,
                'base_directory' => $this->base_directory,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination_class,
                'source_id' => $this->github_app->id,
                'source_type' => $this->github_app->getMorphClass(),
            ]);
            $application->save();
            $application->settings->is_static = $this->is_static;
            $application->settings->save();

            if ($this->build_pack === 'dockerfile' || $this->build_pack === 'dockerimage') {
                $application->health_check_enabled = false;
            }
            if ($this->build_pack === 'dockercompose') {
                $application['docker_compose_location'] = $this->docker_compose_location;
            }
            $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
            $application->fqdn = $fqdn;

            $application->name = generate_application_name($this->selected_repository_owner.'/'.$this->selected_repository_repo, $this->selected_branch_name, $application->uuid);
            $application->save();

            return availRedirectAfterApplicationCreated($application, [
                'application_uuid' => $application->uuid,
                'environment_uuid' => $environment->uuid,
                'project_uuid' => $project->uuid,
            ]);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function instantSave()
    {
        if ($this->is_static) {
            $this->port = 80;
            $this->publish_directory = '/dist';
        } else {
            $this->port = 3000;
            $this->publish_directory = null;
        }
        $this->dispatch('success', 'Application settings updated!');
    }
}
