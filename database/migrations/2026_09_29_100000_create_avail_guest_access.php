<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: guests. A guest is a team member with role "guest" who sees only the projects ticked
 * for them (project_guest_access), read-only, until team_user.guest_expires_at (null = no expiry).
 * Invitations carry the guest's projects and access duration until they are accepted.
 * users.avail_removed_from_root_at stops auto-join from re-adding someone an admin removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_guest_access')) {
            Schema::create('project_guest_access', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('granted_by_user_id')->nullable();
                $table->timestamps();
                $table->unique(['project_id', 'user_id']);
            });
        }

        Schema::table('team_user', function (Blueprint $table) {
            if (! Schema::hasColumn('team_user', 'guest_expires_at')) {
                $table->timestamp('guest_expires_at')->nullable();
            }
            if (! Schema::hasColumn('team_user', 'guest_expiry_warning_sent_at')) {
                $table->timestamp('guest_expiry_warning_sent_at')->nullable();
            }
            if (! Schema::hasColumn('team_user', 'guest_expired_notice_sent_at')) {
                $table->timestamp('guest_expired_notice_sent_at')->nullable();
            }
        });

        Schema::table('team_invitations', function (Blueprint $table) {
            if (! Schema::hasColumn('team_invitations', 'avail_project_ids')) {
                $table->json('avail_project_ids')->nullable();
            }
            if (! Schema::hasColumn('team_invitations', 'avail_access_days')) {
                $table->unsignedInteger('avail_access_days')->nullable();
            }
            if (! Schema::hasColumn('team_invitations', 'avail_access_until')) {
                $table->date('avail_access_until')->nullable();
            }
        });

        if (! Schema::hasColumn('users', 'avail_removed_from_root_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('avail_removed_from_root_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_guest_access');

        foreach (['guest_expires_at', 'guest_expiry_warning_sent_at', 'guest_expired_notice_sent_at'] as $column) {
            if (Schema::hasColumn('team_user', $column)) {
                Schema::table('team_user', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        foreach (['avail_project_ids', 'avail_access_days', 'avail_access_until'] as $column) {
            if (Schema::hasColumn('team_invitations', $column)) {
                Schema::table('team_invitations', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
        if (Schema::hasColumn('users', 'avail_removed_from_root_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avail_removed_from_root_at'));
        }
    }
};
