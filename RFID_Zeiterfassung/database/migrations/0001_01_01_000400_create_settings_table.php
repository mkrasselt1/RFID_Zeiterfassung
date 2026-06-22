<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key/value application settings. Replaces the legacy config.php that was
 * rewritten on disk — holds operator info, timezone and the Google OAuth
 * client/token data. Values are JSON-encoded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->text('value')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
