<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Eine Zeitzone als POSIX-Regel, z. B. "CET-1CEST,M3.5.0,M10.5.0/3".
 *
 * So versteht sie der ESP32 (`setenv("TZ", …)`): mit der Regel stellt er selbst
 * auf Sommerzeit um, ohne eine Zeitzonendatenbank an Bord. Bisher war im Leser
 * ein fester Versatz von +2 Stunden eingebaut — im Winter eine Stunde falsch.
 *
 * Abgeleitet aus den Umstellungen des laufenden Jahres. Für Zonen, deren Regeln
 * sich nicht jedes Jahr gleich wiederholen, ist das eine Näherung; das Gerät
 * holt seine Konfiguration regelmäßig neu und liegt damit höchstens bis zum
 * nächsten Abruf daneben.
 */
class PosixTimezone
{
    public static function fromIana(string $name, ?DateTimeImmutable $now = null): string
    {
        $zone = new DateTimeZone($name);
        $now ??= new DateTimeImmutable('now', $zone);
        $year = (int) $now->setTimezone($zone)->format('Y');

        $start = (new DateTimeImmutable("{$year}-01-01 00:00:00", $zone))->getTimestamp();
        $end = (new DateTimeImmutable(($year + 1).'-01-01 00:00:00', $zone))->getTimestamp();
        // Der erste Eintrag beschreibt nur den Stand zu Beginn, keine Umstellung.
        $transitions = array_slice($zone->getTransitions($start, $end), 1);

        $toDst = null;
        $toStd = null;
        foreach ($transitions as $t) {
            if ($t['isdst']) {
                $toDst ??= $t;
            } else {
                $toStd ??= $t;
            }
        }

        if ($toDst === null || $toStd === null) {
            // Keine Sommerzeit (mehr): ein fester Versatz genügt.
            $offset = $zone->getOffset($now);

            return self::abbr(self::currentAbbr($zone, $now), $offset).self::offset($offset);
        }

        // Versatz vor der jeweiligen Umstellung: der Normalzeit vor dem Wechsel
        // auf Sommerzeit und umgekehrt.
        $stdOffset = $toStd['offset'];
        $dstOffset = $toDst['offset'];

        return self::abbr($toStd['abbr'], $stdOffset).self::offset($stdOffset)
            .self::abbr($toDst['abbr'], $dstOffset)
            .($dstOffset - $stdOffset === 3600 ? '' : self::offset($dstOffset))
            .','.self::rule($toDst['ts'], $stdOffset)
            .','.self::rule($toStd['ts'], $dstOffset);
    }

    /** POSIX zählt umgekehrt: "CET-1" heißt eine Stunde vor UTC. */
    private static function offset(int $seconds): string
    {
        $sign = $seconds > 0 ? '-' : '';
        $seconds = abs($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $sign.$h.($m ? sprintf(':%02d', $m) : '');
    }

    /** Kürzel wie "+03" sind in POSIX nur in spitzen Klammern erlaubt. */
    private static function abbr(string $abbr, int $offset): string
    {
        if (preg_match('/\A[A-Za-z]{3,}\z/', $abbr)) {
            return $abbr;
        }

        $sign = $offset < 0 ? '-' : '+';
        $h = intdiv(abs($offset), 3600);
        $m = intdiv(abs($offset) % 3600, 60);

        return '<'.$sign.sprintf('%02d', $h).($m ? sprintf('%02d', $m) : '').'>';
    }

    private static function currentAbbr(DateTimeZone $zone, DateTimeImmutable $now): string
    {
        return $now->setTimezone($zone)->format('T');
    }

    /**
     * "Mm.w.d/zeit": Monat, Woche (5 = letzte), Wochentag (0 = Sonntag), dazu
     * die Uhrzeit in der Ortszeit, die vor der Umstellung galt.
     */
    private static function rule(int $timestamp, int $offsetBefore): string
    {
        $local = (new DateTimeImmutable('@'.($timestamp + $offsetBefore)));
        $day = (int) $local->format('j');
        $week = (int) ceil($day / 7);
        if ($day + 7 > (int) $local->format('t')) {
            $week = 5;
        }

        $seconds = $timestamp + $offsetBefore - $local->setTime(0, 0)->getTimestamp();
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $time = $h.($m ? sprintf(':%02d', $m) : '');

        return 'M'.$local->format('n').'.'.$week.'.'.$local->format('w').($time === '2' ? '' : '/'.$time);
    }
}
