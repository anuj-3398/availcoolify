<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: remember who created an application, so members can delete their own apps.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('applications', 'avail_created_by_user_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->unsignedBigInteger('avail_created_by_user_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('applications', 'avail_created_by_user_id')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->dropColumn('avail_created_by_user_id');
            });
        }
    }
};
