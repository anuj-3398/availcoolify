<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: routing rules read from availcoolify.json during a deploy. The application and its
 * previews keep the rules in use; each deployment keeps what it used, so a redeploy of an
 * existing image (rollback, unchanged commit) applies the same rules again.
 */
return new class extends Migration
{
    private array $tables = ['applications', 'application_previews', 'application_deployment_queues'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'avail_routing_rules')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->json('avail_routing_rules')->nullable();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'avail_routing_rules')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('avail_routing_rules');
                });
            }
        }
    }
};
