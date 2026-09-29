<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * A public holiday. Stored/compared as a plain 'Y-m-d' string (no date cast) so
 * lookups match, consistent with WorkDay::$work_date and UserLog::$checkindate.
 */
class Holiday extends Model
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTO = 'auto';

    protected $fillable = ['date', 'name', 'half_day', 'source'];

    protected $casts = ['half_day' => 'boolean'];

    /** @var array<string,array{name:string,half_day:bool}>|null cached per request */
    private static ?array $cache = null;

    /** All holidays as a 'Y-m-d' => [name, half_day] map (cached per request). */
    public static function entries(): array
    {
        if (self::$cache === null) {
            self::$cache = static::query()
                ->get(['date', 'name', 'half_day'])
                ->mapWithKeys(fn (Holiday $h) => [substr((string) $h->date, 0, 10) => [
                    'name' => $h->name,
                    'half_day' => (bool) $h->half_day,
                ]])
                ->all();
        }

        return self::$cache;
    }

    /** All holiday dates as a 'Y-m-d' => name map. */
    public static function map(): array
    {
        return array_map(fn (array $entry) => $entry['name'], static::entries());
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        return array_key_exists($date->format('Y-m-d'), static::entries());
    }

    /**
     * How much of the day is worked: 1.0 normally, 0.5 on a half holiday
     * (Heiligabend, Silvester), 0.0 on a full one.
     *
     * Ein Faktor statt eines Ja/Nein, weil derselbe Wert dreierlei steuert:
     * das Soll des Tages, den Urlaubsverbrauch und die Abwesenheitsübersicht.
     */
    public static function workFactor(CarbonInterface $date): float
    {
        $entry = static::entries()[$date->format('Y-m-d')] ?? null;

        if ($entry === null) {
            return 1.0;
        }

        return $entry['half_day'] ? 0.5 : 0.0;
    }

    /** Drop the in-memory cache (after imports/edits within the same request). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
