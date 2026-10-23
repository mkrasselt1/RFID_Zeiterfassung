<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An RFID reader device (legacy `devices` table).
 */
class Device extends Model
{
    public const MODE_LEARN = 0; // register new cards
    public const MODE_TIME = 1;  // attendance check-in/out

    /** How a device may present its token. */
    public const AUTH_BOTH = 'both';

    public const AUTH_BEARER = 'bearer';

    public const AUTH_QUERY = 'query';

    public const AUTH_MODES = [
        self::AUTH_BOTH => 'Beides (Umstellung läuft)',
        self::AUTH_BEARER => 'Nur Authorization-Header',
        self::AUTH_QUERY => 'Nur Adresszeile (alte Firmware)',
    ];

    public const AUTH_DEFAULT = self::AUTH_BOTH;

    /** The configured mode, falling back to the default for unknown values. */
    public static function authMode(): string
    {
        $mode = Setting::get('device_auth_mode', self::AUTH_DEFAULT);

        return isset(self::AUTH_MODES[$mode]) ? $mode : self::AUTH_DEFAULT;
    }

    /** The reader behind a bearer token, or null for a missing or malformed one. */
    public static function fromBearer(?string $token): ?self
    {
        if ($token === null || ! preg_match('/\A[[:xdigit:]]{16}\z/', $token)) {
            return null;
        }

        return self::where('device_uid', $token)->first();
    }

    protected $table = 'devices';

    public $timestamps = false;

    /**
     * Note that the reader just talked to us.
     *
     * `last_ip` ist die Adresse, von der die Anfrage kam — hinter einem Proxy
     * nicht zwingend die des Lesers. `local_ip` meldet das Gerät selbst und ist
     * die, unter der man es im Netz erreicht.
     */
    public function markSeen(?string $ip = null, ?string $firmware = null, ?int $pending = null): void
    {
        $this->forceFill(array_filter([
            'last_seen_at' => now(),
            'last_ip' => $ip,
            'firmware_version' => $firmware,
        ], fn ($v) => $v !== null));

        if ($pending !== null) {
            $this->pending_count = max(0, $pending);
        }

        $this->save();
    }

    protected $fillable = [
        'device_name',
        'device_dep',
        'device_uid',
        'device_date',
        'device_mode',
        'local_ip',
    ];

    /** Nothing heard for this long means something is wrong, not quiet. */
    public const SILENT_AFTER_HOURS = 24;

    public function isSilent(): bool
    {
        return $this->last_seen_at === null
            || $this->last_seen_at->lt(now()->subHours(self::SILENT_AFTER_HOURS));
    }

    /** The reader's own configuration page, if we know where it lives. */
    public function configUrl(): ?string
    {
        $ip = trim((string) $this->local_ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Ab Firmware 2.5 liegt unter / eine offene Statusseite und die
        // Konfiguration unter /config; ältere Leser haben sie unter / und
        // kennen /config nicht.
        $path = version_compare((string) $this->firmware_version, '2.5', '>=') ? 'config' : '';

        return 'http://'.(str_contains($ip, ':') ? '['.$ip.']' : $ip).'/'.$path;
    }

    public function events()
    {
        return $this->hasMany(DeviceEvent::class);
    }

    protected $casts = [
        'device_date' => 'date',
        'device_mode' => 'integer',
        'last_seen_at' => 'datetime',
        'status_report' => 'array',
        'status_reported_at' => 'datetime',
    ];
}
