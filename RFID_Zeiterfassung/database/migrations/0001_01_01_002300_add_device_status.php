<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Was ein Leser über sich selbst meldet: WLAN-Empfang, freier Speicher,
 * Laufzeit, welcher Kartenleser verbaut ist, warum er zuletzt neu gestartet ist.
 *
 * Als JSON und nicht Spalte für Spalte: was ein Gerät melden kann, wächst mit
 * der Firmware, und jede neue Kennzahl soll ohne Schema-Änderung ankommen.
 * Ausgewertet wird davon nur, was das Panel anzeigt.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Wiederaufsetzbar wie die übrigen: MySQL rollt DDL nicht zurück.
        if (! Schema::hasColumn('devices', 'status_report')) {
            Schema::table('devices', fn (Blueprint $t) => $t->json('status_report')->nullable());
        }
        if (! Schema::hasColumn('devices', 'status_reported_at')) {
            Schema::table('devices', fn (Blueprint $t) => $t->dateTime('status_reported_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['status_report', 'status_reported_at'] as $name) {
            if (Schema::hasColumn('devices', $name)) {
                Schema::table('devices', fn (Blueprint $t) => $t->dropColumn($name));
            }
        }
    }
};
