<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RFID reader devices. `device_uid` is the 16-hex-char token the firmware sends
 * as `device_token`. `device_mode`: 0 = Learn (register cards), 1 = Time (attendance).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('devices')) {
            Schema::create('devices', function (Blueprint $table) {
                $table->id();
                $table->string('device_name', 50);
                $table->string('device_dep', 20);
                $table->char('device_uid', 16)->nullable()->unique();
                $table->date('device_date');
                $table->tinyInteger('device_mode')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
