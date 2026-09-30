<?php

namespace App\Livewire\Project\New\Concerns;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Gate;

/**
 * Avail: the "Build and output" and "Environment variables" part of creating an app from Git,
 * set before the first deploy (Vercel-style), plus "create without deploying".
 */
trait AvailAppSetup
{
    public ?string $installCommand = null;

    public ?string $buildCommand = null;

    public ?string $startCommand = null;

    /** Variables in .env format (KEY=value per line), saved before the first deploy. */
    public ?string $setupEnvironment = null;

    public bool $deployAfterCreate = true;

    /**
     * @return array<string, mixed>
     */
    protected function availSetupRules(): array
    {
        return [
            'installCommand' => ValidationPatterns::shellSafeCommandRules(),
            'buildCommand' => ValidationPatterns::shellSafeCommandRules(),
            'startCommand' => ValidationPatterns::shellSafeCommandRules(),
            'setupEnvironment' => 'nullable|string|max:65535',
            'deployAfterCreate' => 'boolean',
        ];
    }

    /**
     * Docker Compose can mount server folders and run privileged containers: admins only.
     */
    protected function availEnsureBuildPackAllowed(?string $buildPack): void
    {
        if ($buildPack === 'dockercompose' && ! Gate::allows('createAnyResource')) {
            throw new \RuntimeException('Only admins can deploy with Docker Compose. Choose Railpack, Nixpacks, Static or Dockerfile.');
        }
    }

    /**
     * The setup variables as KEY => value, checked before anything is created.
     *
     * @return array<string, string>
     */
    protected function availParsedSetupEnvironment(): array
    {
        if (blank($this->setupEnvironment)) {
            return [];
        }

        $variables = [];
        foreach (parseEnvFormatToArray(str_replace("\r\n", "\n", $this->setupEnvironment)) as $key => $entry) {
            $key = ValidationPatterns::validatedEnvironmentVariableKey((string) $key, 'variable name');
            $value = is_array($entry) ? (string) ($entry['value'] ?? '') : (string) $entry;
            if (availReferencesSharedVariables($value) && ! Gate::allows('createAnyResource')) {
                throw new \RuntimeException(availSharedReferenceError());
            }
            $variables[$key] = $value;
        }

        return $variables;
    }

    /**
     * Custom install, build and start commands (Railpack and Nixpacks use them; blank = detected).
     */
    protected function availApplySetupCommands(Application $application): void
    {
        if (! in_array($application->build_pack, ['railpack', 'nixpacks'], true)) {
            return;
        }
        $application->install_command = filled($this->installCommand) ? trim($this->installCommand) : null;
        $application->build_command = filled($this->buildCommand) ? trim($this->buildCommand) : null;
        $application->start_command = filled($this->startCommand) ? trim($this->startCommand) : null;
    }

    /**
     * @param  array<string, string>  $variables
     */
    protected function availCreateSetupEnvironment(Application $application, array $variables): void
    {
        $order = 0;
        foreach ($variables as $key => $value) {
            EnvironmentVariable::create([
                'key' => $key,
                'value' => $value,
                'is_preview' => false,
                'is_runtime' => true,
                'is_buildtime' => true,
                'is_multiline' => str_contains($value, "\n"),
                'is_literal' => false,
                'is_shown_once' => false,
                'order' => ++$order,
                'resourceable_type' => $application->getMorphClass(),
                'resourceable_id' => $application->id,
            ]);
        }
    }

    /**
     * @param  array<string, string>  $parameters
     */
    protected function availFinishCreate(Application $application, array $parameters)
    {
        if (! $this->deployAfterCreate) {
            return redirect()->route('project.application.configuration', $parameters);
        }

        return availRedirectAfterApplicationCreated($application, $parameters);
    }
}
