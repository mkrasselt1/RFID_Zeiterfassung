@php use App\Services\WorktimeReport as R; @endphp
<x-filament-panels::page>
    {{ $this->form }}

    @php $r = $this->getReport(); @endphp

    {{-- Kennzahlen als Kachelreihe. Bewusst mit Inline-`style` statt Tailwind-
         Utilities: Filaments kompiliertes CSS enthält `grid-cols-*` & Co. nicht,
         solange kein eigenes Theme gebaut ist — die Klassen wären wirkungslos und
         alles fiele untereinander. Farben sind hell/dunkel-invariant gewählt
         (neutrale Rahmen über rgba, Text erbt die Panel-Farbe). --}}
    <x-filament::section>
        @php
            // Vorzeichen trägt die Richtung, die Farbe verstärkt sie nur —
            // ein Saldo ist nie allein über Farbe lesbar.
            $saldo = fn (int $v) => [
                'value' => R::hhmm($v),
                'color' => $v < 0 ? '#d03b3b' : ($v > 0 ? '#0ca30c' : ''),
            ];
            $absences = collect($r['absence_days'])
                ->map(fn ($days, $type) => (\App\Models\Absence::TYPES[$type] ?? $type).': '.$days)
                ->implode(' · ');

            $tiles = [
                ['label' => 'Soll (Monat)', 'value' => R::hhmm($r['month_sum']['soll'])],
                ['label' => 'Ist (Monat)', 'value' => R::hhmm($r['month_sum']['ist'])],
                ['label' => 'Pause (Monat)', 'value' => R::hhmm($r['month_sum']['pause'])],
                ['label' => 'Saldo Monat'] + $saldo($r['month_sum']['saldo']),
                ['label' => 'Übertrag (Vorjahre)'] + $saldo($r['carryover']),
                ['label' => 'Saldo '.$r['period']->year] + $saldo($r['year_balance']),
                ['label' => 'Saldo gesamt'] + $saldo($r['total_balance']),
                ['label' => 'Resturlaub', 'value' => number_format($r['vacation_left'], 1, ',', '.').' T'],
                ['label' => 'Sonderurlaub '.$r['period']->year,
                    'value' => number_format($r['special_taken'], 1, ',', '.').' T'],
                ['label' => 'Abwesenheit', 'value' => $absences ?: '–', 'small' => true],
            ];
        @endphp
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;">
            @foreach($tiles as $tile)
                <div style="border:1px solid rgba(127,127,127,.28);border-radius:8px;
                            padding:10px 12px;background:rgba(127,127,127,.06);">
                    <div style="font-size:11px;opacity:.65;line-height:1.3;">{{ $tile['label'] }}</div>
                    <div style="font-weight:600;margin-top:3px;line-height:1.25;font-size:{{ ($tile['small'] ?? false) ? '13px' : '20px' }};{{ ($tile['color'] ?? '') ? 'color:'.$tile['color'].';' : '' }}">
                        {{ $tile['value'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    {{-- Monatskalender: grün = Plus/Soll erfüllt, rot = Minus, blau = Abwesenheit, lila = Feiertag --}}
    @php $absenceLabels = array_values(\App\Models\Absence::TYPES); @endphp
    <x-filament::section>
        <x-slot name="heading">Kalender {{ $r['period']->translatedFormat('F Y') }}</x-slot>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:4px;">
            @foreach(['Mo','Di','Mi','Do','Fr','Sa','So'] as $wd)
                <div style="text-align:center;font-size:11px;color:#888;font-weight:600;">{{ $wd }}</div>
            @endforeach
            @foreach($r['weeks'] as $week)
                @foreach($week['rows'] as $row)
                    @php
                        $bg = '#f3f4f6'; $fg = '#111'; $note = ''; $opacity = '1';
                        if (! $row['in_month']) {
                            $bg = '#fafafa'; $fg = '#bbb'; $opacity = '0.5';
                        } elseif (in_array($row['hint'], $absenceLabels, true)) {
                            $bg = '#dbeafe'; $note = $row['hint'];                 // Abwesenheit (blau)
                        } elseif ($row['hint'] && $row['hint'] !== 'Wochenende') {
                            $bg = '#ede9fe'; $note = 'Feiertag';                    // Feiertag (lila)
                        } elseif ($row['weekend']) {
                            $bg = '#f3f4f6';                                        // Wochenende (grau)
                        } elseif ($row['soll'] > 0 || $row['ist'] > 0) {
                            // Der Kalender zeigt die echte Abweichung; tolerierte Tage
                            // bleiben neutral eingefärbt, weil sie nicht mitzählen.
                            $bg = $row['toleriert']
                                ? '#f3f4f6'
                                : ($row['saldo'] >= 0 ? '#dcfce7' : '#fee2e2');     // grün / rot
                            $note = \App\Services\WorktimeReport::hhmm($row['saldo_roh']);
                        }
                    @endphp
                    <div style="background:{{ $bg }};color:{{ $fg }};opacity:{{ $opacity }};border-radius:6px;padding:6px 4px;min-height:46px;font-size:11px;">
                        <div style="font-weight:700;">{{ (int) $row['day'] }}</div>
                        <div style="opacity:.8;">{{ $note }}</div>
                    </div>
                @endforeach
            @endforeach
        </div>
        <div style="margin-top:10px;font-size:11px;color:#888;">
            <span style="background:#dcfce7;padding:1px 6px;border-radius:4px;">Plus</span>
            <span style="background:#fee2e2;padding:1px 6px;border-radius:4px;">Minus</span>
            <span style="background:#dbeafe;padding:1px 6px;border-radius:4px;">Abwesenheit</span>
            <span style="background:#ede9fe;padding:1px 6px;border-radius:4px;">Feiertag</span>
            <span style="background:#f3f4f6;padding:1px 6px;border-radius:4px;">Wochenende/frei</span>
        </div>
    </x-filament::section>

    @foreach($r['weeks'] as $week)
        <x-filament::section>
            <x-slot name="heading">KW {{ $week['kw'] }}</x-slot>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="py-1 px-2">Tag</th>
                            <th class="py-1 px-2">Rein</th>
                            <th class="py-1 px-2">Raus</th>
                            <th class="py-1 px-2 text-right">Pause</th>
                            <th class="py-1 px-2 text-right">Ist</th>
                            <th class="py-1 px-2 text-right">Soll</th>
                            <th class="py-1 px-2 text-right">Saldo</th>
                            <th class="py-1 px-2">Hinweis</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($week['rows'] as $row)
                            <tr @class([
                                'border-b border-gray-100 dark:border-gray-800',
                                'opacity-40' => ! $row['in_month'],
                                'bg-gray-50 dark:bg-white/5' => $row['weekend'],
                            ])>
                                <td class="py-1 px-2 font-medium whitespace-nowrap">{{ $row['wd'] }} {{ $row['day'] }}</td>
                                <td class="py-1 px-2">{{ $row['in'] }}</td>
                                <td class="py-1 px-2">{{ $row['out'] }}{{ $row['multiple'] ? ' *' : '' }}</td>
                                <td class="py-1 px-2 text-right text-gray-500">{{ $row['pause'] ? R::hhmm($row['pause']) : '' }}</td>
                                <td class="py-1 px-2 text-right">{{ $row['ist'] ? R::hhmm($row['ist']) : '' }}</td>
                                <td class="py-1 px-2 text-right">{{ $row['soll'] ? R::hhmm($row['soll']) : '' }}</td>
                                <td class="py-1 px-2 text-right"
                                    style="{{ $row['saldo'] < 0 ? 'color:#d03b3b;' : ($row['saldo'] > 0 ? 'color:#0ca30c;' : '') }}">
                                    {{ ($row['ist'] || $row['soll']) ? R::hhmm($row['saldo']) : '' }}@if($row['toleriert'])<span
                                        style="opacity:.55;font-size:11px;" title="Abweichung {{ R::hhmm($row['saldo_roh']) }} liegt unter der Toleranz">°</span>@endif
                                </td>
                                <td class="py-1 px-2 text-gray-500">{{ $row['hint'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold border-t-2">
                            <td class="py-1 px-2" colspan="3">Summe KW {{ $week['kw'] }}</td>
                            <td class="py-1 px-2 text-right">{{ R::hhmm($week['sum']['pause']) }}</td>
                            <td class="py-1 px-2 text-right">{{ R::hhmm($week['sum']['ist']) }}</td>
                            <td class="py-1 px-2 text-right">{{ R::hhmm($week['sum']['soll']) }}</td>
                            <td class="py-1 px-2 text-right">{{ R::hhmm($week['sum']['saldo']) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach

    <p class="text-xs text-gray-500">* mehrere Stempelungen an diesem Tag · ausgegraute Tage gehören zum Nachbarmonat ·
        „Pause" ist der automatische Abzug zusätzlich zu bereits ausgestempelten Zeiten
        (Monat: {{ R::hhmm($r['month_sum']['pause']) }}) ·
        ° Tagesabweichung unter der Toleranz, zählt als 0 (der Kalender zeigt den echten Wert).</p>
</x-filament-panels::page>
