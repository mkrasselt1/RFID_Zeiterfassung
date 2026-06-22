<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Setting;
use Spatie\Holidays\Holidays;

/**
 * Imports German public holidays (per Bundesland) from spatie/holidays into the
 * `holidays` table. Auto entries for the year are refreshed on each run while
 * manually maintained entries are preserved.
 */
class HolidayService
{
    /** ISO region codes supported by spatie/holidays for Germany. */
    public const REGIONS = [
        'DE-BW' => 'Baden-Württemberg',
        'DE-BY' => 'Bayern',
        'DE-BE' => 'Berlin',
        'DE-BB' => 'Brandenburg',
        'DE-HB' => 'Bremen',
        'DE-HH' => 'Hamburg',
        'DE-HE' => 'Hessen',
        'DE-MV' => 'Mecklenburg-Vorpommern',
        'DE-NI' => 'Niedersachsen',
        'DE-NW' => 'Nordrhein-Westfalen',
        'DE-RP' => 'Rheinland-Pfalz',
        'DE-SL' => 'Saarland',
        'DE-SN' => 'Sachsen',
        'DE-ST' => 'Sachsen-Anhalt',
        'DE-SH' => 'Schleswig-Holstein',
        'DE-TH' => 'Thüringen',
    ];

    public static function region(): string
    {
        return Setting::get('holiday_region', 'DE-SN');
    }

    /**
     * Import holidays for a year. Refreshes auto entries, keeps manual ones.
     * Returns the number of auto holidays written.
     */
    public function sync(int $year, ?string $region = null): int
    {
        $region ??= static::region();

        $holidays = Holidays::for(country: 'de', year: $year, region: $region)->get();

        // Dates the user maintains manually must not be overwritten.
        $manualDates = Holiday::where('source', Holiday::SOURCE_MANUAL)
            ->whereYear('date', $year)->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))->all();

        Holiday::where('source', Holiday::SOURCE_AUTO)->whereYear('date', $year)->delete();

        $count = 0;
        foreach ($holidays as $holiday) {
            $date = $holiday->date->format('Y-m-d');
            if (in_array($date, $manualDates, true)) {
                continue;
            }
            Holiday::updateOrCreate(
                ['date' => $date],
                ['name' => $holiday->name, 'source' => Holiday::SOURCE_AUTO],
            );
            $count++;
        }

        Holiday::flushCache();

        return $count;
    }
}
