<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stempeln ohne Karte: Namen am Drehrad wählen, PIN eingeben.
 *
 * Für alle, die ihren Chip vergessen haben oder noch keinen besitzen. Zwei
 * Dinge sichern das ab, denn sonst könnte jeder für jeden stempeln — genau die
 * Manipulation, gegen die eine Zeiterfassung belegen soll:
 *
 * - `kiosk_enabled` schaltet eine Person überhaupt erst frei; die Liste am
 *   Gerät zeigt nur diese.
 * - `kiosk_pin` ist gehasht abgelegt und wird nur im Panel gesetzt. Am Gerät
 *   eingegeben, auf dem Server geprüft — der Leser bekommt sie nie zu sehen.
 *
 * `users_logs.source` hält fest, wie eine Stempelung entstand. Ohne das wäre
 * später nicht mehr zu unterscheiden, was von einer Karte kam und was jemand
 * über die Namensliste gebucht hat.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'kiosk_enabled' => fn (Blueprint $t) => $t->boolean('kiosk_enabled')->default(false),
            'kiosk_pin' => fn (Blueprint $t) => $t->string('kiosk_pin')->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (! Schema::hasColumn('employees', $name)) {
                Schema::table('employees', fn (Blueprint $table) => $define($table));
            }
        }

        if (! Schema::hasColumn('users_logs', 'source')) {
            Schema::table('users_logs', function (Blueprint $table) {
                // Bestehende Zeilen kamen alle von einer Karte.
                $table->string('source', 10)->default('card');
            });
        }
    }

    public function down(): void
    {
        foreach (['kiosk_enabled', 'kiosk_pin'] as $name) {
            if (Schema::hasColumn('employees', $name)) {
                Schema::table('employees', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }

        if (Schema::hasColumn('users_logs', 'source')) {
            Schema::table('users_logs', fn (Blueprint $table) => $table->dropColumn('source'));
        }
    }
};
