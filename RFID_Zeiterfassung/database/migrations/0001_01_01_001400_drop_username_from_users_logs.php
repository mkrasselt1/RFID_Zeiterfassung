<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the denormalized `username` snapshot from `users_logs`. Attribution and
 * display now go through `employee_id` → `employees.name`. Runs after the
 * backfill so no information is lost. The down() restores the column and
 * repopulates it from the linked employee for reversibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users_logs') && Schema::hasColumn('users_logs', 'username')) {
            Schema::table('users_logs', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users_logs') || Schema::hasColumn('users_logs', 'username')) {
            return;
        }

        Schema::table('users_logs', function (Blueprint $table) {
            $table->string('username', 100)->default('')->after('id');
        });

        // Repopulate the snapshot from the now-authoritative employee link.
        DB::table('users_logs')
            ->join('employees', 'users_logs.employee_id', '=', 'employees.id')
            ->update(['users_logs.username' => DB::raw('employees.name')]);
    }
};
