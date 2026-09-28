<?php

namespace App\Livewire\Team;

use App\Models\Team;
use App\Support\ValidationPatterns;
use Livewire\Component;

class Create extends Component
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    public string $name = '';

    public ?string $description = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ];
    }

    protected function messages(): array
    {
        return ValidationPatterns::combinedMessages();
    }

    public function submit()
    {
        try {
            $this->authorize('create', Team::class);
            $this->validate();
            $team = Team::create([
                'name' => $this->name,
                'description' => $this->description,
                'personal_team' => false,
                'is_mcp_server_enabled' => true,
            ]);
            auth()->user()->teams()->attach($team, ['role' => 'owner']);
            refreshSession($team);

            return redirectRoute($this, 'team.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }
}
