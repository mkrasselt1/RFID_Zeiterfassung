@php use App\Services\WorktimeReport as R; @endphp
<x-filament-panels::page>
    {{ $this->form }}

    @php $r = $this->getReport(); @endphp

    <x-filament::section>
        @php
            $balanceClass = fn (int $v) => $v < 0 ? 'text-danger-600' : ($v > 0 ? 'text-success-600' : '');
        @endphp
        <div class="grid grid-cols-2 gap-4 md:grid-cols-4 text-center">
            <div>
                <div class="text-sm text-gray-500">Soll (Monat)</div>
                <div class="text-xl font-bold">{{ R::hhmm($r['month_sum']['soll']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Ist (Monat)</div>
                <div class="text-xl font-bold">{{ R::hhmm($r['month_sum']['ist']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Saldo Monat</div>
                <div @class(['text-xl font-bold', $balanceClass($r['month_sum']['saldo'])])>
                    {{ R::hhmm($r['month_sum']['saldo']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Resturlaub</div>
                <div class="text-xl font-bold">{{ number_format($r['vacation_left'], 1, ',', '.') }} T</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Übertrag (Vorjahre)</div>
                <div @class(['text-xl font-bold', $balanceClass($r['carryover'])])>
                    {{ R::hhmm($r['carryover']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Saldo {{ $r['period']->year }}</div>
                <div @class(['text-xl font-bold', $balanceClass($r['year_balance'])])>
                    {{ R::hhmm($r['year_balance']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Saldo gesamt</div>
                <div @class(['text-xl font-bold', $balanceClass($r['total_balance'])])>
                    {{ R::hhmm($r['total_balance']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Abwesenheit</div>
                <div class="text-sm font-medium">
                    @forelse($r['absence_days'] as $type => $days)
                        {{ \App\Models\Absence::TYPES[$type] ?? $type }}: {{ $days }}<br>
                    @empty
                        –
                    @endforelse
                </div>
            </div>
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
                            $bg = $row['saldo'] >= 0 ? '#dcfce7' : '#fee2e2';       // grün / rot
                            $note = \App\Services\WorktimeReport::hhmm($row['saldo']);
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
                                <td class="py-1 px-2 text-right">{{ $row['ist'] ? R::hhmm($row['ist']) : '' }}</td>
                                <td class="py-1 px-2 text-right">{{ $row['soll'] ? R::hhmm($row['soll']) : '' }}</td>
                                <td @class([
                                    'py-1 px-2 text-right',
                                    'text-danger-600' => $row['saldo'] < 0,
                                    'text-success-600' => $row['saldo'] > 0,
                                ])>{{ ($row['ist'] || $row['soll']) ? R::hhmm($row['saldo']) : '' }}</td>
                                <td class="py-1 px-2 text-gray-500">{{ $row['hint'] }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold border-t-2">
                            <td class="py-1 px-2" colspan="3">Summe KW {{ $week['kw'] }}</td>
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

    <p class="text-xs text-gray-500">* mehrere Stempelungen an diesem Tag · ausgegraute Tage gehören zum Nachbarmonat.</p>
</x-filament-panels::page>
