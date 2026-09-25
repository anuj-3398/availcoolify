<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: Vercel-style GitHub connect. One platform GitHub App (flagged here), one GithubApp row per
 * installation (same app credentials, different installation id), and one GitHub connection per user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('github_apps', 'is_avail_platform')) {
            Schema::table('github_apps', function (Blueprint $table) {
                $table->boolean('is_avail_platform')->default(false);
            });
        }

        if (! Schema::hasColumn('github_apps', 'avail_installation_status')) {
            Schema::table('github_apps', function (Blueprint $table) {
                // null = active; 'suspended' or 'removed' after GitHub installation events.
                $table->string('avail_installation_status')->nullable();
            });
        }

        if (! Schema::hasTable('github_connections')) {
            Schema::create('github_connections', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('github_user_id');
                $table->string('github_login');
                $table->text('access_token');
                $table->text('refresh_token')->nullable();
                $table->timestamp('access_token_expires_at')->nullable();
                $table->timestamp('refresh_token_expires_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('github_connections');

        if (Schema::hasColumn('github_apps', 'avail_installation_status')) {
            Schema::table('github_apps', function (Blueprint $table) {
                $table->dropColumn('avail_installation_status');
            });
        }

        if (Schema::hasColumn('github_apps', 'is_avail_platform')) {
            Schema::table('github_apps', function (Blueprint $table) {
                $table->dropColumn('is_avail_platform');
            });
        }
    }
};
