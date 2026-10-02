<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Employee;
use App\Services\DeviceStamping;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Stempeln ohne Karte: Namen am Drehrad wählen, PIN eingeben.
 *
 *   GET  /api/v1/kiosk/employees   → wer am Gerät zur Auswahl steht
 *   POST /api/v1/kiosk/stampings   → ein- oder auschecken
 *
 * Beides mit dem Gerätetoken als `Authorization: Bearer`. Bewusst getrennt von
 * `getdata.php` und von den gepufferten Stempelungen: andere Semantik, andere
 * Prüfungen.
 *
 * Zwei Dinge sichern das ab, denn sonst könnte hier jeder für jeden stempeln:
 *
 * - Die Liste zeigt nur, wer im Panel ausdrücklich freigeschaltet *und* mit
 *   einer PIN versehen wurde.
 * - Die PIN wird auf dem Server geprüft, nicht am Gerät. Der Leser bekommt
 *   weder PIN noch Hash zu sehen — eine vierstellige Zahl wäre aus einem Hash
 *   in Sekunden zurückgerechnet.
 *
 * Darum geht das nur online. Das ist der Ausnahmefall; wer seinen Chip dabei
 * hat, stempelt weiter über den gepufferten Weg, der auch ohne Netz trägt.
 */
class KioskController extends Controller
{
    /** Fehlversuche je Mitarbeiter und Gerät, bevor dichtgemacht wird. */
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function __construct(private readonly DeviceStamping $stamping)
    {
    }

    /** Wer am Gerät zur Auswahl steht — Namen, sonst nichts. */
    public function employees(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if ($device === null) {
            return response()->json(['error' => 'Gerät nicht gefunden'], 401);
        }

        $device->markSeen($request->ip());

        $employees = Employee::query()
            ->where('kiosk_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('kiosk_pin')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name])
            ->all();

        return response()->json(['employees' => $employees]);
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if ($device === null) {
            return response()->json(['error' => 'Gerät nicht gefunden'], 401);
        }

        try {
            $data = $request->validate([
                'employee_id' => ['required', 'integer'],
                'pin' => ['required', 'string', 'max:32'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Ungueltige Anfrage'], 422);
        }

        $device->markSeen($request->ip());

        $key = 'kiosk:'.$device->id.':'.$data['employee_id'];
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'error' => 'Zu viele Fehlversuche',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $employee = Employee::find($data['employee_id']);

        // Eine Antwort für „gibt es nicht", „nicht freigeschaltet" und „PIN
        // falsch": sonst verrät das Gerät, welche Namen eine PIN haben.
        if ($employee === null
            || ! $employee->canStampWithoutCard()
            || ! Hash::check($data['pin'], $employee->kiosk_pin)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            return response()->json(['error' => 'PIN falsch'], 403);
        }

        RateLimiter::clear($key);

        $result = $this->stamping->recordForEmployee($device, $employee, Carbon::now());

        if ($result->failed()) {
            return response()->json(['error' => $result->message], 503);
        }

        return response()->json([
            'status' => $result->status,
            'name' => $result->name,
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }

    private function authenticate(Request $request): ?Device
    {
        $token = $request->bearerToken();
        if ($token === null || ! preg_match('/\A[[:xdigit:]]{16}\z/', $token)) {
            return null;
        }

        return Device::where('device_uid', $token)->first();
    }
}
