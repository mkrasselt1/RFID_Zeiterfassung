<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ManagerOnly;
use App\Filament\Resources\CardholderResource;
use App\Models\Cardholder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Browser-based card enrollment via WebNFC (Android Chrome only). The page's
 * JavaScript reads a tag's serial number with NDEFReader and posts it here;
 * enroll() normalises it to the firmware's format (uppercase hex, no
 * separators) so a card enrolled here matches the same card scanned at a
 * physical reader, then opens the cardholder form to fill in the details.
 */
class EnrollCard extends Page
{
    use ManagerOnly;

    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Geräte';

    protected static ?string $navigationLabel = 'Karte anlernen';

    protected static ?string $title = 'Karte anlernen (NFC)';

    protected static ?int $navigationSort = 0;

    protected static string $view = 'filament.pages.enroll-card';

    /**
     * Normalise a UID to the firmware representation: keep hex digits only,
     * uppercase. WebNFC yields e.g. "04:5a:12:34" -> "045A1234".
     */
    public static function normalizeUid(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $raw));
    }

    public function enroll(string $uid)
    {
        $uid = static::normalizeUid($uid);

        if (! preg_match('/\A[[:xdigit:]]{8,32}\z/', $uid)) {
            Notification::make()
                ->title('Ungültige Karten-UID')
                ->body('Gelesen: ' . ($uid ?: '(leer)'))
                ->danger()
                ->send();

            return;
        }

        $card = Cardholder::firstOrNew(['card_uid' => $uid]);
        $existed = $card->exists;

        // Mark as the currently selected card, like a reader in learn mode.
        $card->card_select = 1;
        if (! $existed) {
            $card->user_date = now()->toDateString();
            $card->device_uid = 'Web';
            $card->device_dep = 'Web';
        }
        $card->save();

        Notification::make()
            ->title($existed ? 'Karte bereits bekannt' : 'Karte angelernt')
            ->body('UID: ' . $uid)
            ->success()
            ->send();

        return redirect(CardholderResource::getUrl('edit', ['record' => $card]));
    }
}
