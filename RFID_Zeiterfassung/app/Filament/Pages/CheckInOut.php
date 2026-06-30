<?php

namespace App\Filament\Pages;

use App\Models\Cardholder;
use App\Models\Setting;
use App\Models\UserLog;
use App\Services\GoogleCalendarApi;
use Carbon\Carbon;
use DateTime;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Web check-in/out for the logged-in admin's own RFID cardholder (matched by
 * e-mail). Mirrors the legacy checkin.php, including the Google Calendar event
 * create/update and the device_uid/device_dep = "Web" marker.
 */
class CheckInOut extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-finger-print';

    protected static ?string $navigationGroup = 'Zeiterfassung';

    protected static ?string $navigationLabel = 'Ein-/Auschecken';

    protected static ?string $title = 'Ein-/Auschecken';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.check-in-out';

    public function getCardholder(): ?Cardholder
    {
        $employee = auth()->user();

        // Prefer a card linked to the logged-in employee; fall back to a card
        // matched by e-mail (legacy data that has not been linked yet).
        return $employee->cards()->orderByDesc('id')->first()
            ?? Cardholder::where('email', $employee->email)->orderByDesc('id')->first();
    }

    public function getOpenLog(): ?UserLog
    {
        $user = $this->getCardholder();
        if (! $user) {
            return null;
        }
        $today = Carbon::now()->format('Y-m-d');
        $yesterday = Carbon::yesterday()->format('Y-m-d');

        return UserLog::where('card_uid', $user->card_uid)
            ->whereIn('checkindate', [$today, $yesterday])
            ->where('card_out', 0)
            ->orderByDesc('id')
            ->first();
    }

    public function checkin(): void
    {
        $user = $this->getCardholder();
        if (! $user || $this->getOpenLog()) {
            return;
        }
        $cAPI = GoogleCalendarApi::make();
        $timezone = Setting::get('timezone', 'Europe/Berlin');

        $eventId = null;
        if (! empty($user->calendarId)) {
            $eventId = $cAPI->CreateCalendarEvent(
                $user->calendarId,
                $user->username . ' Arbeitszeit',
                false, false, false,
                [
                    'start_time' => (new DateTime())->format(DateTime::RFC3339),
                    'end_time' => (new DateTime())->modify('+5 minutes')->format(DateTime::RFC3339),
                ],
                $timezone,
            );
        }

        UserLog::create([
            'employee_id' => $user->employee_id,
            'card_uid' => $user->card_uid,
            'device_uid' => 'Web',
            'device_dep' => 'Web',
            'checkindate' => Carbon::now()->format('Y-m-d'),
            'timein' => Carbon::now()->format('H:i:s'),
            'timeout' => 0,
            'calendarEventId' => $eventId,
        ]);
        $cAPI->persist();

        Notification::make()->title('Eingecheckt')->success()->send();
    }

    public function checkout(): void
    {
        $user = $this->getCardholder();
        $log = $this->getOpenLog();
        if (! $user || ! $log) {
            return;
        }
        $cAPI = GoogleCalendarApi::make();
        $timezone = Setting::get('timezone', 'Europe/Berlin');

        if (! empty($log->calendarEventId)) {
            $cAPI->UpdateCalendarEvent(
                $log->calendarEventId,
                $user->calendarId,
                $user->username . ' Arbeitszeit',
                false,
                [
                    'start_time' => (new DateTime($log->checkindate . ' ' . $log->timein))->format(DateTime::RFC3339),
                    'end_time' => (new DateTime())->format(DateTime::RFC3339),
                ],
                $timezone,
            );
        }

        $log->timeout = Carbon::now()->format('H:i:s');
        $log->card_out = 1;
        $log->save();
        $cAPI->persist();

        Notification::make()->title('Ausgecheckt')->success()->send();
    }
}
