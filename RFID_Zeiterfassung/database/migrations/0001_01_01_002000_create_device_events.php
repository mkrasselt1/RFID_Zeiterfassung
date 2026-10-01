<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buffered stampings delivered by a reader, and the state of the readers.
 *
 * Ein Leser ohne Netz muss Stempelungen lokal halten und später nachliefern.
 * Dabei kann dieselbe Stempelung mehrfach ankommen — die Antwort geht verloren,
 * das Gerät versucht es erneut. Deshalb trägt jedes Ereignis eine vom Gerät
 * vergebene Kennung: ist sie schon da, wird das gespeicherte Ergebnis noch
 * einmal geantwortet und nichts zweites gebucht.
 *
 * Nebenbei ist die Tabelle das Protokoll der Geräteseite: wann eine Stempelung
 * entstand, wann sie ankam und was daraus wurde.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Zwei Schritte in einer Migration, und MySQL rollt DDL nicht zurück:
        // bricht der zweite ab, steht die Tabelle schon, der Eintrag in
        // `migrations` fehlt, und jeder weitere Lauf scheitert an "table
        // already exists". Jeder Schritt prüft deshalb selbst, ob er dran ist.
        if (! Schema::hasTable('device_events')) {
            $this->createEvents();
        }

        $this->addDeviceState();
    }

    private function createEvents(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // Vom Gerät vergeben und nur dort eindeutig — deshalb zusammen mit
            // der Geräte-ID eindeutig, nicht allein.
            $table->string('event_uid', 64);
            $table->string('card_uid', 32);
            // Wann die Karte gehalten wurde (Gerätezeit) …
            $table->dateTime('occurred_at');
            // … und wann der Server davon erfuhr. Die Lücke dazwischen zeigt,
            // wie lange ein Leser offline war.
            $table->dateTime('received_at');
            $table->string('status', 20);
            $table->string('message')->nullable();
            $table->foreignId('user_log_id')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'event_uid']);
            $table->index('occurred_at');
        });

    }

    /**
     * Zustand, damit ein stummer Leser auffällt, bevor jemand seine Zeiten
     * vermisst — und `local_ip` für die Konfiguration im Betrieb: unter welcher
     * Adresse der Leser im Netz erreichbar ist. Vom Gerät gemeldet, von Hand
     * überschreibbar.
     *
     * Spalte für Spalte geprüft: ein Abbruch kann mitten in der Reihe passiert
     * sein.
     */
    private function addDeviceState(): void
    {
        $columns = [
            'last_seen_at' => fn (Blueprint $t) => $t->dateTime('last_seen_at')->nullable(),
            'last_ip' => fn (Blueprint $t) => $t->string('last_ip', 45)->nullable(),
            'firmware_version' => fn (Blueprint $t) => $t->string('firmware_version', 20)->nullable(),
            'pending_count' => fn (Blueprint $t) => $t->unsignedInteger('pending_count')->default(0),
            'local_ip' => fn (Blueprint $t) => $t->string('local_ip', 45)->nullable(),
        ];

        foreach ($columns as $name => $define) {
            if (Schema::hasColumn('devices', $name)) {
                continue;
            }
            Schema::table('devices', fn (Blueprint $table) => $define($table));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');

        foreach (['last_seen_at', 'last_ip', 'firmware_version', 'pending_count', 'local_ip'] as $name) {
            if (Schema::hasColumn('devices', $name)) {
                Schema::table('devices', fn (Blueprint $table) => $table->dropColumn($name));
            }
        }
    }
};
