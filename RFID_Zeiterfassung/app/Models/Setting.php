<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Key/value app settings (operator info, timezone, Google OAuth config).
 * Replaces the legacy config.php. Values are JSON-encoded.
 */
class Setting extends Model
{
    protected $table = 'settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /**
     * Alle Einstellungen, einmal je Request geladen.
     *
     * Vorher war jedes `get()` eine eigene Abfrage. Bei einer Neuberechnung
     * über Jahre waren das drei je Mitarbeitertag — mehr als die Stempelungen
     * selbst. Die Tabelle hat eine Handvoll Zeilen; sie einmal zu holen ist in
     * jedem Fall billiger.
     *
     * @var array<string, string>|null
     */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = static::query()->pluck('value', 'key')->all();
        }

        return array_key_exists($key, self::$cache)
            ? json_decode(self::$cache[$key], true)
            : $default;
    }

    /** Nach einer Änderung von außen (Tests, Importe, mehrere Prozesse). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    public static function put(string $key, mixed $value): void
    {
        $encoded = json_encode($value);

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $encoded],
        );

        // Den Zwischenspeicher gleich mitziehen, statt ihn zu verwerfen: sonst
        // läse der nächste Zugriff die ganze Tabelle neu.
        if (self::$cache !== null) {
            self::$cache[$key] = $encoded;
        }
    }
}
