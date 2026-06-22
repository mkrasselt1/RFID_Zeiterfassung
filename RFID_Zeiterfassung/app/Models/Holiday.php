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

    protected $fillable = ['date', 'name', 'source'];

    /** @var array<string,string>|null cached date => name map */
    private static ?array $cache = null;

    /** All holiday dates as a 'Y-m-d' => name map (cached per request). */
    public static function map(): array
    {
        if (self::$cache === null) {
            self::$cache = static::query()
                ->get(['date', 'name'])
                ->mapWithKeys(fn (Holiday $h) => [substr((string) $h->date, 0, 10) => $h->name])
                ->all();
        }

        return self::$cache;
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        return array_key_exists($date->format('Y-m-d'), static::map());
    }

    /** Drop the in-memory cache (after imports/edits within the same request). */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
