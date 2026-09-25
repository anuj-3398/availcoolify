<?php

namespace App\Console\Commands;

use App\Models\GithubApp;
use Illuminate\Console\Command;

/**
 * Avail: choose the platform GitHub App used by "Continue with GitHub".
 */
class AvailGithubPlatform extends Command
{
    protected $signature = 'avail:github-platform
                            {github_app_id? : Id of the GitHub App source to use as the platform app}
                            {--off : Turn GitHub connect off}';

    protected $description = 'Show or set the platform GitHub App for Vercel-style GitHub connect';

    public function handle(): int
    {
        if ($this->option('off')) {
            GithubApp::where('is_avail_platform', true)->update(['is_avail_platform' => false]);
            $this->info('GitHub connect is off.');

            return self::SUCCESS;
        }

        $id = $this->argument('github_app_id');
        if ($id === null) {
            $platform = GithubApp::where('is_avail_platform', true)->first();
            $this->line($platform
                ? "Platform GitHub App: #{$platform->id} {$platform->name} (app id {$platform->app_id})"
                : 'No platform GitHub App set. GitHub connect is off.');

            return self::SUCCESS;
        }

        $app = GithubApp::find($id);
        if (! $app) {
            $this->error("GitHub App #{$id} not found.");

            return self::FAILURE;
        }
        foreach (['app_id', 'client_id', 'client_secret', 'webhook_secret', 'private_key_id'] as $field) {
            if (blank($app->{$field})) {
                $this->error("GitHub App #{$id} is missing {$field}; finish its setup first.");

                return self::FAILURE;
            }
        }

        GithubApp::where('is_avail_platform', true)->where('id', '!=', $app->id)->update(['is_avail_platform' => false]);
        $app->forceFill(['is_avail_platform' => true])->save();
        $this->info("Platform GitHub App set to #{$app->id} {$app->name} (app id {$app->app_id}).");

        return self::SUCCESS;
    }
}
