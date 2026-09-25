<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('environments', 'preview_guard_enabled')) {
            Schema::table('environments', function (Blueprint $table) {
                // Avail: Clerk login in front of every app (production + previews) in this environment.
                $table->boolean('preview_guard_enabled')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('environments', 'preview_guard_enabled')) {
            Schema::table('environments', function (Blueprint $table) {
                $table->dropColumn('preview_guard_enabled');
            });
        }
    }
};
