<?php

namespace App\Services;

use App\Models\Cardholder;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\UserLog;
use Carbon\Carbon;
use DateTime;

/**
 * What one card tap at one reader did.
 *
 * `name` is only set where the legacy plain-text answers carry it, because the
 * firmware shows it on the display.
 */
readonly class StampingResult
{
    public function __construct(
        public string $status,
        public string $name = '',
        public string $message = '',
        public ?int $userLogId = null,
    ) {
    }

    public function failed(): bool
    {
        return $this->status === DeviceStamping::FAILED;
    }
}

/**
 * The decision behind a card tap: check in, check out, learn a card, or refuse.
 *
 * Liegt bewusst neben den Controllern: die alte Schnittstelle antwortet in
 * Klartext ("login<Name>"), die neue in JSON, und beide müssen dieselbe
 * Entscheidung treffen. Der Zeitpunkt kommt von außen, weil eine nachgelieferte
 * Stempelung mit der Zeit zählt, zu der sie entstand — nicht mit der des Uploads.
 */
class DeviceStamping
{
    public const CHECKIN = 'checkin';

    public const CHECKOUT = 'checkout';

    public const LEARNED = 'learned';

    public const KNOWN = 'known';

    public const FAILED = 'failed';

    public function record(Device $device, string $cardUid, Carbon $at): StampingResult
    {
        $cAPI = GoogleCalendarApi::make();
        $timezone = Setting::get('timezone', 'Europe/Berlin');

        $result = match ((int) $device->device_mode) {
            Device::MODE_TIME => $this->attendance($device, $cardUid, $at, $cAPI, $timezone),
            Device::MODE_LEARN => $this->learn($device, $cardUid, $at),
            default => new StampingResult(self::FAILED, message: 'Unbekannter Modus'),
        };

        // Persist a refreshed Google access token (replaces config.php rewrite).
        $cAPI->persist();

        return $result;
    }

    private function attendance(
        Device $device, string $cardUid, Carbon $at, GoogleCalendarApi $cAPI, string $timezone
    ): StampingResult {
        $d = $at->format('Y-m-d');
        $t = $at->format('H:i:s');

        $user = Cardholder::where('card_uid', $cardUid)->first();
        if (is_null($user)) {
            return new StampingResult(self::FAILED, message: 'Nutzer nicht gefunden!');
        }
        if ((int) $user->add_card != 1) {
            return new StampingResult(self::FAILED, message: 'Nicht registriert!');
        }
        if (! ($user->device_dep == $device->device_dep || $user->device_dep == 'All')) {
            return new StampingResult(self::FAILED, message: 'Hier nicht erlaubt');
        }

        $log = $this->openLogFor($cardUid, $d);

        if (! is_null($log)) {
            // Check-out.
            if (! empty($log->calendarEventId)) {
                $cAPI->UpdateCalendarEvent(
                    $log->calendarEventId,
                    $user->calendarId,
                    $user->username.' Arbeitszeit',
                    false,
                    [
                        'start_time' => (new DateTime($log->checkindate.' '.$log->timein))->format(DateTime::RFC3339),
                        'end_time' => $at->toDateTimeImmutable()->format(DateTime::RFC3339),
                    ],
                    $timezone,
                );
            }
            $log->timeout = $t;
            $log->card_out = 1;
            if ($log->save()) {
                return new StampingResult(self::CHECKOUT, $user->username, userLogId: $log->id);
            }

            return new StampingResult(self::FAILED, message: 'SQL Checkout Fehler');
        }

        // Check-in.
        $eventId = null;
        if (! empty($user->calendarId)) {
            $eventId = $cAPI->CreateCalendarEvent(
                $user->calendarId,
                $user->username.'Arbeitszeit',
                false,
                false,
                false,
                [
                    'start_time' => $at->toDateTimeImmutable()->format(DateTime::RFC3339),
                    'end_time' => $at->copy()->addMinutes(5)->toDateTimeImmutable()->format(DateTime::RFC3339),
                ],
                $timezone,
            );
        }

        $log = new UserLog([
            'employee_id' => $user->employee_id,
            'card_uid' => $user->card_uid,
            'device_uid' => $device->device_uid,
            'device_dep' => $device->device_dep,
            'checkindate' => $d,
            'timein' => $t,
            'timeout' => 0,
            'calendarEventId' => $eventId,
        ]);

        if ($log->save()) {
            return new StampingResult(self::CHECKIN, $user->username, userLogId: $log->id);
        }

        return new StampingResult(self::FAILED, message: 'SQL Checkin Fehler');
    }

    /**
     * Ein- oder Auschecken ohne Karte: Name am Drehrad gewählt, PIN geprüft.
     *
     * Dieselben Regeln wie bei der Karte, nur ohne Kartenprüfung — der offene
     * Eintrag wird hier über den Mitarbeiter gesucht, nicht über eine Karte.
     * Damit passt ein Auschecken über die Namensliste auch zu einem
     * Einchecken, das morgens mit dem Chip entstand.
     */
    public function recordForEmployee(Device $device, Employee $employee, Carbon $at): StampingResult
    {
        $d = $at->format('Y-m-d');
        $t = $at->format('H:i:s');

        $log = $this->openLogForEmployee($employee, $d);

        if (! is_null($log)) {
            $log->timeout = $t;
            $log->card_out = 1;
            if ($log->save()) {
                return new StampingResult(self::CHECKOUT, $employee->name, userLogId: $log->id);
            }

            return new StampingResult(self::FAILED, message: 'SQL Checkout Fehler');
        }

        $log = new UserLog([
            'employee_id' => $employee->id,
            // Ohne Karte gibt es keine UID; die Herkunft steht in `source`.
            'card_uid' => '',
            'device_uid' => $device->device_uid,
            'device_dep' => $device->device_dep,
            'checkindate' => $d,
            'timein' => $t,
            'timeout' => 0,
            'source' => UserLog::SOURCE_KIOSK,
        ]);

        if ($log->save()) {
            return new StampingResult(self::CHECKIN, $employee->name, userLogId: $log->id);
        }

        return new StampingResult(self::FAILED, message: 'SQL Checkin Fehler');
    }

    /** Offener Eintrag des Mitarbeiters: heute, sonst gestern. */
    private function openLogForEmployee(Employee $employee, string $d): ?UserLog
    {
        $log = UserLog::where('employee_id', $employee->id)
            ->where('checkindate', $d)
            ->where('card_out', 0)
            ->first();
        if (! is_null($log)) {
            return $log;
        }

        return UserLog::where('employee_id', $employee->id)
            ->where('checkindate', Carbon::parse($d)->subDay()->toDateString())
            ->where('card_out', 0)
            ->first();
    }

    private function learn(Device $device, string $cardUid, Carbon $at): StampingResult
    {
        $this->unselectCards();

        $existing = Cardholder::where('card_uid', $cardUid)->first();
        if (! is_null($existing)) {
            $existing->card_select = 1;
            $existing->save();

            return new StampingResult(self::KNOWN);
        }

        Cardholder::create([
            'card_uid' => $cardUid,
            'card_select' => 1,
            'device_uid' => $device->device_uid,
            'device_dep' => $device->device_dep,
            'user_date' => $at->format('Y-m-d'),
        ]);

        return new StampingResult(self::LEARNED);
    }

    /**
     * Open log for the card: today's, else yesterday's, with card_out=0.
     * Mirrors legacy getLogByCheckinDate().
     */
    private function openLogFor(string $cardUid, string $d): ?UserLog
    {
        $log = UserLog::where('card_uid', $cardUid)
            ->where('checkindate', $d)
            ->where('card_out', 0)
            ->first();
        if (! is_null($log)) {
            return $log;
        }

        return UserLog::where('card_uid', $cardUid)
            ->where('checkindate', Carbon::parse($d)->subDay()->toDateString())
            ->where('card_out', 0)
            ->first();
    }

    private function unselectCards(): void
    {
        Cardholder::where('card_select', 1)->update(['card_select' => 0]);
    }
}
