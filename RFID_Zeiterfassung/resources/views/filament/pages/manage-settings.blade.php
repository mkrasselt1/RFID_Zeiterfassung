<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="flex items-center gap-3 mt-6">
            <x-filament::button type="submit">
                Speichern
            </x-filament::button>

            <x-filament::button type="button" color="gray" wire:click="connectGoogle">
                @if ($this->getGoogleConnected())
                    Google neu verbinden
                @else
                    Mit Google verbinden
                @endif
            </x-filament::button>

            @if ($this->getGoogleConnected())
                <span class="text-sm text-success-600 font-medium">Google verbunden ✓</span>
            @endif
        </div>
    </form>
</x-filament-panels::page>
