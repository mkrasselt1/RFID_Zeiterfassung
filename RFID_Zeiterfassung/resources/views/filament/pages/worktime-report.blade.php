@php use App\Services\WorktimeReport as R; @endphp
<x-filament-panels::page>
    {{ $this->form }}

    @php $r = $this->getReport(); @endphp

    <x-filament::section>
        <div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6 text-center">
            <div>
                <div class="text-sm text-gray-500">Soll</div>
                <div class="text-xl font-bold">{{ R::hhmm($r['month_sum']['soll']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Ist</div>
                <div class="text-xl font-bold">{{ R::hhmm($r['month_sum']['ist']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Saldo Monat</div>
                <div @class([
                    'text-xl font-bold',
                    'text-danger-600' => $r['month_sum']['saldo'] < 0,
                    'text-success-600' => $r['month_sum']['saldo'] > 0,
                ])>{{ R::hhmm($r['month_sum']['saldo']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Saldo gesamt</div>
                <div @class([
                    'text-xl font-bold',
                    'text-danger-600' => $r['total_balance'] < 0,
                    'text-success-600' => $r['total_balance'] > 0,
                ])>{{ R::hhmm($r['total_balance']) }}</div>
            </div>
            <div>
                <div class="text-sm text-gray-500">Resturlaub</div>
                <div class="text-xl font-bold">{{ number_format($r['vacation_left'], 1, ',', '.') }} T</div>
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
