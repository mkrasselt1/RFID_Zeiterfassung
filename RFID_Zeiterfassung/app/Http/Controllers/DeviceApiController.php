<?php

namespace App\Http\Controllers;

use App\Models\Cardholder;
use App\Models\Device;
use App\Models\Setting;
use App\Models\UserLog;
use App\Services\GoogleCalendarApi;
use Carbon\Carbon;
use DateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Device-facing RFID endpoint. Behaviour, request params and plain-text
 * responses are a faithful port of the legacy rfidattendance/getdata.php so
 * existing ESP32 firmware keeps working unchanged:
 *
 *   GET ?device_token=<16 hex>&card_uid=<8-32 hex>
 *
 *   200 "login<username>"   check-in            200 "successful"  new card learned
 *   200 "logout<username>"  check-out           200 "available"   card already known
 *   503 "Error: <message>"  any failure (German messages preserved verbatim)
 *
 * The firmware string-matches the "login"/"logout"/"Error:" prefixes and the
 * exact "successful"/"available" bodies, so none of these may change.
 */
class DeviceApiController extends Controller
{
    public function handle(Request $request): Response
    {
        $cAPI = GoogleCalendarApi::make();
        $timezone = Setting::get('timezone', 'Europe/Berlin');

        $d = Carbon::now()->format('Y-m-d');
        $t = Carbon::now()->format('H:i:s');

        // Validate inputs exactly like the legacy filter_input regexes.
        $device_uid = $this->validateHex($request->query('device_token'), '/\A[[:xdigit:]]{16}\z/');
        $card_uid = $this->validateHex($request->query('card_uid'), '/\A[[:xdigit:]]{8,32}\z/');

        if (! $card_uid || ! $device_uid) {
            return $this->error('Error: Ungueltige Anfrage');
        }

        $device = Device::where('device_uid', $device_uid)->first();
        if (is_null($device)) {
            return $this->error('Error: Gerät nicht gefunden');
        }

        $result = match ((int) $device->device_mode) {
            Device::MODE_TIME => $this->handleTimeMode($device, $card_uid, $d, $t, $cAPI, $timezone),
            Device::MODE_LEARN => $this->handleLearnMode($device, $card_uid, $d),
            default => $this->error('Error: Unbekannter Modus'),
        };

        // Persist a refreshed Google access token (replaces config.php rewrite).
        $cAPI->persist();

        return $result;
    }

    private function handleTimeMode(Device $device, string $card_uid, string $d, string $t, GoogleCalendarApi $cAPI, string $timezone): Response
    {
        $user = Cardholder::where('card_uid', $card_uid)->first();
        if (is_null($user)) {
            return $this->error('Error: Nutzer nicht gefunden!');
        }
        if ((int) $user->add_card != 1) {
            return $this->error('Error: Nicht registriert!');
        }
        if (! ($user->device_dep == $device->device_dep || $user->device_dep == 'All')) {
            return $this->error('Error: Hier nicht erlaubt');
        }

        $log = $this->openLogFor($card_uid, $d);

        if (! is_null($log)) {
            // Check-out.
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
            $log->timeout = $t;
            $log->card_out = 1;
            if ($log->save()) {
                return $this->ok('logout' . $user->username);
            }

            return $this->error('Error: SQL Checkout Fehler');
        }

        // Check-in.
        $eventId = null;
        if (! empty($user->calendarId)) {
            $eventId = $cAPI->CreateCalendarEvent(
                $user->calendarId,
                $user->username . 'Arbeitszeit',
                false,
                false,
                false,
                [
                    'start_time' => (new DateTime())->format(DateTime::RFC3339),
                    'end_time' => (new DateTime())->modify('+5 minutes')->format(DateTime::RFC3339),
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
            return $this->ok('login' . $user->username);
        }

        return $this->error('Error: SQL Checkin Fehler');
    }

    private function handleLearnMode(Device $device, string $card_uid, string $d): Response
    {
        $this->unselectCards();

        $existing = Cardholder::where('card_uid', $card_uid)->first();
        if (! is_null($existing)) {
            $existing->card_select = 1;
            $existing->save();

            return $this->ok('available');
        }

        $card = new Cardholder([
            'card_uid' => $card_uid,
            'card_select' => 1,
            'device_uid' => $device->device_uid,
            'device_dep' => $device->device_dep,
            'user_date' => $d,
        ]);
        $card->save();

        return $this->ok('successful');
    }

    /**
     * Open log for the card: today's, else yesterday's, with card_out=0.
     * Mirrors legacy getLogByCheckinDate().
     */
    private function openLogFor(string $card_uid, string $d): ?UserLog
    {
        $log = UserLog::where('card_uid', $card_uid)
            ->where('checkindate', $d)
            ->where('card_out', 0)
            ->first();
        if (! is_null($log)) {
            return $log;
        }

        $yesterday = Carbon::yesterday()->format('Y-m-d');

        return UserLog::where('card_uid', $card_uid)
            ->where('checkindate', $yesterday)
            ->where('card_out', 0)
            ->first();
    }

    private function unselectCards(): void
    {
        Cardholder::where('card_select', 1)->update(['card_select' => 0]);
    }

    private function validateHex(?string $value, string $pattern): string|false
    {
        if ($value !== null && preg_match($pattern, $value)) {
            return $value;
        }

        return false;
    }

    private function ok(string $body): Response
    {
        return response($body, 200)->header('Content-Type', 'text/plain');
    }

    private function error(string $message): Response
    {
        return response($message, 503)->header('Content-Type', 'text/plain');
    }
}
