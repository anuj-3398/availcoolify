<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avail: new applications stop after 10 crash restarts by default. Upstream made the limit opt-in
 * (0 = unlimited); existing rows keep whatever they have.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->integer('max_restart_count')->default(10)->change();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->integer('max_restart_count')->default(0)->change();
        });
    }
};
