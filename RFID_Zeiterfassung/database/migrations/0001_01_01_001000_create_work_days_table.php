<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivered-worktime ledger: one row per employee per day, recomputed from the
 * attendance logs (`users_logs`) and approved absences against the active
 * contract. `balance_minutes` = worked - expected (neutralized on absence days).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_days')) {
            Schema::create('work_days', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->date('work_date');
                $table->integer('worked_minutes')->default(0);
                $table->integer('expected_minutes')->default(0);
                $table->integer('balance_minutes')->default(0);
                $table->foreignId('absence_id')->nullable()->constrained('absences')->nullOnDelete();
                $table->timestamps();
                $table->unique(['employee_id', 'work_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_days');
    }
};
