<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\DeviceStamping;
use App\Services\StampingResult;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Legacy device endpoint. Behaviour, request params and plain-text responses
 * are a faithful port of the legacy rfidattendance/getdata.php so existing
 * ESP32 firmware keeps working unchanged:
 *
 *   GET ?device_token=<16 hex>&card_uid=<8-32 hex>
 *
 *   200 "login<username>"   check-in            200 "successful"  new card learned
 *   200 "logout<username>"  check-out           200 "available"   card already known
 *   503 "Error: <message>"  any failure (German messages preserved verbatim)
 *
 * The firmware string-matches the "login"/"logout"/"Error:" prefixes and the
 * exact "successful"/"available" bodies, so none of these may change.
 *
 * Das Token darf stattdessen als `Authorization: Bearer <16 hex>` kommen; was
 * erlaubt ist, entscheidet die Einstellung "Anmeldung der Leser".
 *
 * Diese Schnittstelle kennt nur eine Stempelung zur Serverzeit und hat keinen
 * Schutz gegen doppelt gelieferte Ereignisse — für gepufferte Stempelungen gibt
 * es `DeviceStampingController`.
 */
class DeviceApiController extends Controller
{
    public function __construct(private readonly DeviceStamping $stamping)
    {
    }

    public function handle(Request $request): Response
    {
        $device_uid = $this->deviceToken($request);
        $card_uid = $this->validateHex($request->query('card_uid'), '/\A[[:xdigit:]]{8,32}\z/');

        if (! $card_uid || ! $device_uid) {
            return $this->error('Error: Ungueltige Anfrage');
        }

        $device = Device::where('device_uid', $device_uid)->first();
        if (is_null($device)) {
            return $this->error('Error: Gerät nicht gefunden');
        }

        $device->markSeen($request->ip());

        return $this->render($this->stamping->record($device, $card_uid, Carbon::now()));
    }

    /** Map the decision onto the plain-text answers the old firmware expects. */
    private function render(StampingResult $result): Response
    {
        return match ($result->status) {
            DeviceStamping::CHECKIN => $this->ok('login'.$result->name),
            DeviceStamping::CHECKOUT => $this->ok('logout'.$result->name),
            DeviceStamping::LEARNED => $this->ok('successful'),
            DeviceStamping::KNOWN => $this->ok('available'),
            default => $this->error('Error: '.$result->message),
        };
    }

    /**
     * The device token, from `Authorization: Bearer <token>` or the legacy
     * `?device_token=` query parameter, whichever the operator allows.
     *
     * Der Header ist der bessere Weg: ein Token in der Adresszeile steht
     * anschließend in Server- und Proxy-Protokollen. Die Adresszeile bleibt
     * trotzdem wählbar, weil ausgelieferte Firmware sie nutzt und nicht jeder
     * Betrieb seine Leser an einem Tag umflashen kann.
     */
    private function deviceToken(Request $request): string|false
    {
        $mode = Device::authMode();
        $pattern = '/\A[[:xdigit:]]{16}\z/';

        if ($mode !== Device::AUTH_QUERY && ($bearer = $request->bearerToken()) !== null) {
            return $this->validateHex($bearer, $pattern);
        }

        if ($mode !== Device::AUTH_BEARER) {
            return $this->validateHex($request->query('device_token'), $pattern);
        }

        return false;
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
