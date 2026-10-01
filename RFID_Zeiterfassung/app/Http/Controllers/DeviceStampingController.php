<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\DeviceEvent;
use App\Services\DeviceStamping;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Device endpoint for buffered stampings.
 *
 *   POST /api/v1/stampings
 *   Authorization: Bearer <device token, 16 hex>
 *
 *   {
 *     "firmware": "2.4",            optional, shown in the reader list
 *     "pending": 3,                 optional, how many are still buffered
 *     "events": [
 *       {"uid": "a1b2-17", "card_uid": "DEADBEEF", "at": "2026-10-01T07:03:12+02:00"}
 *     ]
 *   }
 *
 * Antwort: je Ereignis ein Ergebnis, in derselben Reihenfolge.
 *
 *   {
 *     "server_time": "2026-10-01T07:05:00+02:00",
 *     "results": [
 *       {"uid": "a1b2-17", "status": "checkin", "name": "Max Mustermann", "message": "",
 *        "duplicate": false}
 *     ]
 *   }
 *
 * Drei Dinge unterscheiden sie von der alten Schnittstelle:
 *
 * - **Mehrere auf einmal**, damit ein Leser nach Netzausfall alles nachreicht.
 * - **Zeit vom Gerät.** Gebucht wird, wann die Karte gehalten wurde, nicht wann
 *   der Upload gelang. Ohne das wäre ein Puffer wertlos.
 * - **Wiederholbar.** Jedes Ereignis trägt eine Kennung vom Gerät. Geht die
 *   Antwort verloren und das Gerät schickt noch einmal, wird nichts zweites
 *   gebucht — das gespeicherte Ergebnis kommt zurück, mit `duplicate: true`.
 *
 * Das Token gehört in den Header; die Adresszeile ist hier nicht vorgesehen.
 */
class DeviceStampingController extends Controller
{
    /** Keeps one oversized or runaway upload from blocking the request. */
    private const MAX_EVENTS = 200;

    /** Beyond this a device clock is considered broken, not merely offline. */
    private const MAX_FUTURE_MINUTES = 5;

    public function __construct(private readonly DeviceStamping $stamping)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $device = $this->authenticate($request);
        if ($device === null) {
            return response()->json(['error' => 'Gerät nicht gefunden'], 401);
        }

        try {
            $data = $request->validate([
                'firmware' => ['nullable', 'string', 'max:20'],
                'pending' => ['nullable', 'integer', 'min:0'],
                'events' => ['required', 'array', 'min:1', 'max:'.self::MAX_EVENTS],
                'events.*.uid' => ['required', 'string', 'max:64'],
                'events.*.card_uid' => ['required', 'string', 'regex:/\A[[:xdigit:]]{8,32}\z/'],
                // Nicht Pflicht: ein Gerät ohne Echtzeituhr weiß nach einem Neustart
                // nicht, wann gestempelt wurde. Dann zählt die Serverzeit — ungenau,
                // aber die Stempelung geht nicht verloren, und ein ganzer Schwung
                // scheitert nicht an einer fehlenden Uhr.
                'events.*.at' => ['nullable', 'date'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Ungueltige Anfrage', 'details' => $e->errors()], 422);
        }

        $device->markSeen($request->ip(), $data['firmware'] ?? null, $data['pending'] ?? null);

        $results = [];
        foreach ($data['events'] as $event) {
            $results[] = $this->process($device, $event);
        }

        return response()->json([
            'server_time' => Carbon::now()->toIso8601String(),
            'results' => $results,
        ]);
    }

    /**
     * Book one event, or hand back what it booked last time.
     *
     * Beides in einer Transaktion: die Stempelung und der Vermerk, dass dieses
     * Ereignis erledigt ist. Sonst könnte ein Abbruch dazwischen eine gebuchte
     * Stempelung ohne Vermerk hinterlassen — und die nächste Lieferung würde
     * sie ein zweites Mal buchen.
     */
    private function process(Device $device, array $event): array
    {
        $existing = DeviceEvent::where('device_id', $device->id)
            ->where('event_uid', $event['uid'])
            ->first();

        if ($existing !== null) {
            return $this->answer($existing->event_uid, $existing->status, '', $existing->message ?? '', true);
        }

        $at = $this->occurredAt($event['at'] ?? null);

        return DB::transaction(function () use ($device, $event, $at) {
            // Unverändert übernehmen, wie die alte Schnittstelle auch: Karten
            // werden im Anlernmodus über denselben Weg angelegt, also in der
            // Schreibweise, die die Firmware schickt. Hier zu normalisieren
            // würde die neue Schnittstelle von bestehenden Karten abschneiden.
            $result = $this->stamping->record($device, $event['card_uid'], $at);

            DeviceEvent::create([
                'device_id' => $device->id,
                'event_uid' => $event['uid'],
                'card_uid' => $event['card_uid'],
                'occurred_at' => $at,
                'received_at' => Carbon::now(),
                'status' => $result->status,
                'message' => $result->message ?: null,
                'user_log_id' => $result->userLogId,
            ]);

            return $this->answer($event['uid'], $result->status, $result->name, $result->message, false);
        });
    }

    /**
     * A device clock that runs ahead would book work that has not happened.
     * Slight drift is tolerated, anything beyond it falls back to server time.
     */
    private function occurredAt(?string $raw): Carbon
    {
        if ($raw === null || trim($raw) === '') {
            return Carbon::now();
        }

        $at = Carbon::parse($raw);
        $limit = Carbon::now()->addMinutes(self::MAX_FUTURE_MINUTES);

        return $at->isAfter($limit) ? Carbon::now() : $at;
    }

    private function answer(string $uid, string $status, string $name, string $message, bool $duplicate): array
    {
        return [
            'uid' => $uid,
            'status' => $status,
            'name' => $name,
            'message' => $message,
            'duplicate' => $duplicate,
        ];
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
