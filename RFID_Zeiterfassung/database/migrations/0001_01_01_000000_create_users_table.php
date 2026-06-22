<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `users` table holds RFID cardholders (NOT login accounts — those live in
 * the `admin` table). Schema mirrors the legacy MySQL app so this Laravel app
 * can point at the existing production database with zero data migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded so this is safe to run against the pre-existing production
        // database: an already-present `users` table (with its data) is left
        // untouched and only its absence triggers a create.
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('username', 30)->default('None');
                $table->double('serialnumber')->default(0);
                $table->string('gender', 10)->default('None');
                $table->string('email', 50)->default('None');
                $table->string('card_uid', 30)->unique();
                $table->boolean('card_select')->default(0);
                $table->string('calendarId', 70)->nullable();
                $table->date('user_date');
                $table->string('device_uid', 20)->default('0');
                $table->string('device_dep', 20)->default('0');
                $table->boolean('add_card')->default(0);
            });
        }

        // Required by SESSION_DRIVER=database (not present in the legacy DB).
        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};
