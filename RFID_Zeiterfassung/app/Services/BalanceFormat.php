<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Renders signed minute balances (Saldo, Überstunden, Übertrag) in the format
 * the operator picked under Einstellungen → Allgemein.
 *
 * Only balances are configurable. Ist, Soll und Pause bleiben h:mm — das sind
 * Zeitspannen, keine Abweichungen, und "8:00" liest sich als Arbeitstag besser
 * als "8 h".
 */
class BalanceFormat
{
    public const DECIMAL = 'decimal';

    public const HHMM = 'hhmm';

    public const MINUTES = 'minutes';

    public const DEFAULT = self::DECIMAL;

    /** Selectable formats, labelled with a worked example. */
    public const FORMATS = [
        self::DECIMAL => 'Dezimalstunden (1,5 h)',
        self::HHMM => 'Stunden:Minuten (1:30)',
        self::MINUTES => 'Minuten (90 min)',
    ];

    /**
     * Memoized per request: a month view formats ~40 cells and the setting
     * cannot change mid-render. ManageSettings::save() calls forget().
     */
    protected static ?string $format = null;

    /** Format signed minutes in the configured format. */
    public static function make(int $minutes): string
    {
        return match (static::current()) {
            self::HHMM => static::hhmm($minutes),
            self::MINUTES => static::minutes($minutes),
            default => static::decimal($minutes),
        };
    }

    /** The configured format, falling back to the default for unknown values. */
    public static function current(): string
    {
        if (static::$format === null) {
            $format = Setting::get('overtime_format', self::DEFAULT);
            static::$format = isset(self::FORMATS[$format]) ? $format : self::DEFAULT;
        }

        return static::$format;
    }

    /** Drop the memoized format after the setting was written. */
    public static function forget(): void
    {
        static::$format = null;
    }

    /** "1,5 h" / "-0,25 h" / "8 h" — trailing zeros drop so round values stay round. */
    public static function decimal(int $minutes): string
    {
        $value = number_format($minutes / 60, 2, ',', '.');

        return rtrim(rtrim($value, '0'), ',').' h';
    }

    /** "1:30" / "-1:30" / "8:00". */
    public static function hhmm(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);

        return sprintf('%s%d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    /** "90 min" / "-90 min" / "480 min". */
    public static function minutes(int $minutes): string
    {
        return number_format($minutes, 0, ',', '.').' min';
    }
}
