<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Absence requests (Urlaub/Krank/Unbezahlt/Überstundenabbau) filed for a date
 * range, with single-step approval (HR/Admin sets status + approver + decision).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('absences')) {
            Schema::create('absences', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('type'); // vacation|sick|unpaid|overtime_reduction
                $table->date('start_date');
                $table->date('end_date');
                $table->boolean('half_day')->default(false);
                $table->string('status')->default('pending'); // pending|approved|rejected|cancelled
                $table->text('reason')->nullable();
                $table->foreignId('approver_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->dateTime('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('absences');
    }
};
