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

    protected $table = 'devices';

    public $timestamps = false;

    protected $fillable = [
        'device_name',
        'device_dep',
        'device_uid',
        'device_date',
        'device_mode',
    ];

    protected $casts = [
        'device_date' => 'date',
        'device_mode' => 'integer',
    ];
}
