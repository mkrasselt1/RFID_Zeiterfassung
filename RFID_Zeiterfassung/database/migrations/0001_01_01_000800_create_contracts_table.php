<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Work contracts per employee, valid over [valid_from, valid_to]. The expected
 * working time is pluggable via `worktime_model`:
 *   weekly_hours  - target_hours per week, spread across workdays
 *   monthly_hours - target_hours per month, spread across that month's workdays
 *   daily_hours   - target_hours on each workday
 *   tracking_only - no expectation, only record delivered time
 * `workdays` is a JSON array of ISO weekday numbers (Mon=1 .. Sun=7).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contracts')) {
            Schema::create('contracts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('title')->nullable();
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->string('worktime_model')->default('tracking_only');
                $table->decimal('target_hours', 6, 2)->nullable();
                $table->json('workdays')->nullable();
                $table->decimal('vacation_days_per_year', 4, 1)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
