<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees are the central identity: they log into the panel and own cards,
 * contracts, work-day ledger entries and absence requests. Guarded so it is
 * safe to run against the pre-existing production database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            Schema::create('employees', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->string('personnel_number')->nullable();
                $table->string('role')->default('employee'); // employee|supervisor|hr|admin
                $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->string('gender', 10)->nullable();
                $table->string('calendar_id', 70)->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
