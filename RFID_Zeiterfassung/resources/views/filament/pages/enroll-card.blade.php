<x-filament-panels::page>
    <div
        x-data="{
            supported: ('NDEFReader' in window),
            scanning: false,
            status: '',
            error: '',
            async scan() {
                this.error = '';
                if (!this.supported) {
                    this.error = 'WebNFC wird von diesem Browser nicht unterstützt. Bitte Chrome auf Android verwenden (über HTTPS).';
                    return;
                }
                try {
                    const reader = new NDEFReader();
                    await reader.scan();
                    this.scanning = true;
                    this.status = 'Bereit – Karte an die Rückseite des Telefons halten …';
                    reader.onreadingerror = () => { this.error = 'Karte konnte nicht gelesen werden. Erneut versuchen.'; };
                    reader.onreading = (event) => {
                        const uid = (event.serialNumber || '').replace(/[^0-9a-fA-F]/g, '').toUpperCase();
                        if (!uid) { this.error = 'Karte ohne lesbare Seriennummer.'; return; }
                        this.status = 'Gelesen: ' + uid + ' – wird angelernt …';
                        this.scanning = false;
                        $wire.enroll(uid);
                    };
                } catch (e) {
                    this.error = 'NFC-Zugriff fehlgeschlagen: ' + e.message;
                    this.scanning = false;
                }
            }
        }"
    >
        <x-filament::section>
            <x-slot name="heading">Karte per NFC anlernen</x-slot>
            <x-slot name="description">
                Funktioniert mit Google Chrome auf Android (sicherer Kontext / HTTPS erforderlich).
            </x-slot>

            <div class="space-y-4">
                <template x-if="!supported">
                    <p class="text-warning-600 font-medium">
                        Dieser Browser unterstützt WebNFC nicht. Öffne diese Seite in Chrome auf einem
                        Android-Gerät (über HTTPS), oder lerne Karten über ein Lesegerät im Modus
                        „Registrierung“ an.
                    </p>
                </template>

                <template x-if="supported">
                    <div class="space-y-4">
                        <x-filament::button x-on:click="scan" x-bind:disabled="scanning" icon="heroicon-o-signal">
                            <span x-show="!scanning">Karte scannen</span>
                            <span x-show="scanning">Warte auf Karte …</span>
                        </x-filament::button>

                        <p x-show="status" x-text="status" class="text-sm text-gray-600 dark:text-gray-300"></p>
                        <p x-show="error" x-text="error" class="text-sm text-danger-600 font-medium"></p>
                    </div>
                </template>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
