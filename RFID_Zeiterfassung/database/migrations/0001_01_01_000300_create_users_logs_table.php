<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance records. A row is created on check-in (card_out=0, timeout=0) and
 * completed on check-out (card_out=1, timeout set). Schema mirrors legacy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users_logs')) {
            Schema::create('users_logs', function (Blueprint $table) {
                $table->id();
                $table->string('username', 100);
                $table->double('serialnumber');
                $table->string('card_uid', 30);
                $table->string('device_uid', 20);
                $table->string('device_dep', 20);
                $table->date('checkindate');
                $table->time('timein');
                $table->time('timeout');
                $table->string('calendarEventId', 35)->nullable();
                $table->boolean('card_out')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('users_logs');
    }
};
