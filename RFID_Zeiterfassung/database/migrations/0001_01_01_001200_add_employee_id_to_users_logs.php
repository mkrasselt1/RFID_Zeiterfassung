<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamps each attendance record (legacy `users_logs`) with the employee it
 * belonged to at the moment of stamping. This makes worktime attribution
 * independent of the card's *current* holder, so reassigning a chip no longer
 * moves a previous employee's history onto the new one.
 *
 * Nullable + indexed only, mirroring `users.employee_id`: a real cross-charset
 * FK (latin1 `users_logs` → utf8mb4 `employees`) is intentionally avoided.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users_logs') && ! Schema::hasColumn('users_logs', 'employee_id')) {
            Schema::table('users_logs', function (Blueprint $table) {
                $table->foreignId('employee_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users_logs') && Schema::hasColumn('users_logs', 'employee_id')) {
            Schema::table('users_logs', function (Blueprint $table) {
                $table->dropColumn('employee_id');
            });
        }
    }
};
