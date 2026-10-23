<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Änderungsprotokoll für Stempelungen: wer hat wann was angelegt, geändert oder
 * gelöscht — im Panel oder über die Admin-API. Stempelungen der Leser selbst
 * landen hier nicht, nur Eingriffe von angemeldeten Personen.
 *
 * `user_log_id` ohne Fremdschlüssel, damit der Eintrag eine gelöschte
 * Stempelung überlebt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_log_changes')) {
            Schema::create('user_log_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_log_id')->index();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('action', 10);
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('channel', 10);
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_log_changes');
    }
};
