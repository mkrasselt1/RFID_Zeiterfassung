<x-filament-panels::page>
    @php
        $cardholder = $this->getCardholder();
        $openLog = $this->getOpenLog();
    @endphp

    @if (! $cardholder)
        <x-filament::section>
            <p>
                Für dein Konto ({{ auth()->user()->email }}) ist keine RFID-Karte verknüpft.
                Hinterlege deine E-Mail-Adresse bei einem Benutzer, um die Web-Zeiterfassung zu nutzen.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="space-y-4">
                <p class="text-lg">
                    Benutzer: <strong>{{ $cardholder->username }}</strong>
                </p>

                @if ($openLog)
                    <p class="text-success-600 font-medium">
                        Eingecheckt seit {{ $openLog->checkindate }} {{ $openLog->timein }} (UTC)
                    </p>
                @else
                    <p class="text-gray-500">Aktuell nicht eingecheckt.</p>
                @endif

                <div class="flex gap-3">
                    <x-filament::button color="success" wire:click="checkin" :disabled="(bool) $openLog">
                        Einchecken
                    </x-filament::button>
                    <x-filament::button color="danger" wire:click="checkout" :disabled="! $openLog">
                        Auschecken
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
