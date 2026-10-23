<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Setting;
use App\Support\PosixTimezone;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Die Geräte-API des Lesers neben dem Stempeln: Konfiguration holen und den
 * eigenen Zustand melden.
 *
 *   GET  /api/v1/device/config
 *   POST /api/v1/device/status
 *   POST /api/v1/device/stampings   (DeviceStampingController)
 *
 * Alle drei mit dem Gerätetoken im Authorization-Header.
 *
 * Konfiguration, die im Panel gepflegt wird, kommt von hier — Zeitzone und
 * Modus muss niemand mehr am Gerät einstellen. Am Gerät bleibt nur, was es
 * braucht, um den Server überhaupt zu erreichen: WLAN, Serveradresse, Token.
 */
class DeviceController extends Controller
{
    /** Wie oft ein Leser sich meldet und seine Konfiguration neu holt. */
    public const STATUS_INTERVAL_SECONDS = 300;

    public const CONFIG_INTERVAL_SECONDS = 3600;

    /** Wie oft ein Leser versucht, Gepuffertes nachzuliefern. */
    public const FLUSH_INTERVAL_SECONDS = 30;

    /**
     *   {
     *     "name": "Eingang", "mode": "time",          time | learn
     *     "timezone": "Europe/Berlin",
     *     "tz": "CET-1CEST,M3.5.0,M10.5.0/3",         POSIX, für setenv("TZ")
     *     "ntp_server": "pool.ntp.org",
     *     "server_time": "2026-10-23T09:00:00+02:00",
     *     "intervals": {"status": 300, "config": 3600, "flush": 30}
     *   }
     */
    public function config(Request $request): JsonResponse
    {
        $device = Device::fromBearer($request->bearerToken());
        if ($device === null) {
            return response()->json(['error' => 'Gerät nicht gefunden'], 401);
        }

        $device->markSeen($request->ip());
        $timezone = Setting::get('timezone', 'Europe/Berlin');

        return response()->json([
            'name' => (string) $device->device_name,
            'mode' => (int) $device->device_mode === Device::MODE_LEARN ? 'learn' : 'time',
            'timezone' => $timezone,
            'tz' => PosixTimezone::fromIana($timezone),
            'ntp_server' => Setting::get('ntp_server', 'pool.ntp.org'),
            'server_time' => Carbon::now()->toIso8601String(),
            'intervals' => [
                'status' => self::STATUS_INTERVAL_SECONDS,
                'config' => self::CONFIG_INTERVAL_SECONDS,
                'flush' => self::FLUSH_INTERVAL_SECONDS,
            ],
        ]);
    }

    /**
     *   {
     *     "firmware": "2.5", "pending": 0, "ip": "192.168.16.82",
     *     "uptime": 86400, "rssi": -61, "ssid": "Büro", "free_heap": 180000,
     *     "reader": "PN532 v1.6", "reset_reason": "poweron",
     *     "stamped": 12, "uploaded": 12
     *   }
     *
     * Unbekannte Felder werden verworfen, bekannte bleiben als Ganzes gespeichert.
     */
    public function status(Request $request): JsonResponse
    {
        $device = Device::fromBearer($request->bearerToken());
        if ($device === null) {
            return response()->json(['error' => 'Gerät nicht gefunden'], 401);
        }

        try {
            $data = $request->validate([
                'firmware' => ['nullable', 'string', 'max:20'],
                'pending' => ['nullable', 'integer', 'min:0'],
                'ip' => ['nullable', 'ip'],
                'uptime' => ['nullable', 'integer', 'min:0'],
                'rssi' => ['nullable', 'integer', 'between:-127,0'],
                'ssid' => ['nullable', 'string', 'max:64'],
                'free_heap' => ['nullable', 'integer', 'min:0'],
                'reader' => ['nullable', 'string', 'max:40'],
                'reset_reason' => ['nullable', 'string', 'max:20'],
                'stamped' => ['nullable', 'integer', 'min:0'],
                'uploaded' => ['nullable', 'integer', 'min:0'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['error' => 'Ungueltige Anfrage', 'details' => $e->errors()], 422);
        }

        // Die Adresse im lokalen Netz meldet das Gerät selbst — die, von der
        // die Anfrage kommt, ist hinter NAT oder Proxy eine andere.
        if (! empty($data['ip'])) {
            $device->local_ip = $data['ip'];
        }
        $device->status_report = array_diff_key(
            array_filter($data, fn ($v) => $v !== null),
            array_flip(['firmware', 'pending', 'ip']),
        );
        $device->status_reported_at = now();
        $device->markSeen($request->ip(), $data['firmware'] ?? null, $data['pending'] ?? null);

        return response()->json([
            'server_time' => Carbon::now()->toIso8601String(),
        ]);
    }
}
