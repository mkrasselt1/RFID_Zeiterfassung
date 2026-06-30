<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the denormalized `serialnumber` (personnel number) snapshot from
 * `users_logs`. Like `username`, it merely copied card/employee data onto each
 * record; the authoritative value is `employees.personnel_number`, reached via
 * `employee_id`. No backfill needed (the employee link already carries it).
 * The down() restores the column and repopulates it from the linked employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users_logs') && Schema::hasColumn('users_logs', 'serialnumber')) {
            Schema::table('users_logs', function (Blueprint $table) {
                $table->dropColumn('serialnumber');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users_logs') || Schema::hasColumn('users_logs', 'serialnumber')) {
            return;
        }

        Schema::table('users_logs', function (Blueprint $table) {
            $table->double('serialnumber')->default(0)->after('card_uid');
        });

        // Restore from the employee link where the personnel number is numeric.
        DB::table('users_logs')
            ->join('employees', 'users_logs.employee_id', '=', 'employees.id')
            ->whereRaw("employees.personnel_number REGEXP '^[0-9]+(\\\\.[0-9]+)?$'")
            ->update(['users_logs.serialnumber' => DB::raw('employees.personnel_number')]);
    }
};
