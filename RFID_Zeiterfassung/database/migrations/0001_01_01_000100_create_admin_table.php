<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `admin` table holds the panel login accounts. Columns mirror the legacy
 * schema; `remember_token` is added (nullable) so Laravel's "remember me" works.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Skip if the legacy `admin` table already exists; the remember_token
        // column it lacks is added by a separate guarded migration.
        if (! Schema::hasTable('admin')) {
            Schema::create('admin', function (Blueprint $table) {
                $table->id();
                $table->string('admin_name', 30);
                $table->string('admin_email', 80);
                $table->longText('admin_pwd');
                $table->char('admin_passwd_reset_token', 64)->default('');
                $table->dateTime('admin_passwd_reset_timeout')->nullable();
                $table->rememberToken();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin');
    }
};
