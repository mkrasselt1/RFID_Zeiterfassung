<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the nullable `remember_token` column to a pre-existing legacy `admin`
 * table (Laravel "remember me"). Additive and guarded, so it is safe to run
 * against the production database without touching existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admin') && ! Schema::hasColumn('admin', 'remember_token')) {
            Schema::table('admin', function (Blueprint $table) {
                $table->rememberToken();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('admin') && Schema::hasColumn('admin', 'remember_token')) {
            Schema::table('admin', function (Blueprint $table) {
                $table->dropColumn('remember_token');
            });
        }
    }
};
