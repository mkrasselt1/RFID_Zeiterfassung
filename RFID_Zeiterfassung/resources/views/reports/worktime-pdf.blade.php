@php use App\Services\WorktimeReport as R; use App\Models\Absence; @endphp
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10px; color: #111; margin: 0; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        .sub { color: #555; font-size: 10px; margin-bottom: 8px; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .summary td { border: 1px solid #ccc; padding: 4px 6px; }
        .summary .label { color: #555; font-size: 8px; text-transform: uppercase; }
        .summary .val { font-size: 13px; font-weight: bold; }
        table.week { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.week th { background: #f0f0f0; text-align: left; padding: 3px 5px; border: 1px solid #ccc; font-size: 9px; }
        table.week td { padding: 3px 5px; border: 1px solid #ddd; }
        .r { text-align: right; }
        .kw { background: #e8e8e8; font-weight: bold; padding: 3px 5px; border: 1px solid #ccc; }
        .muted { color: #aaa; }
        .weekend td { background: #fafafa; }
        .sumrow td { font-weight: bold; border-top: 2px solid #999; }
        .neg { color: #b00; }
        .pos { color: #070; }
        .sign { margin-top: 24px; font-size: 9px; }
        .sign td { padding-top: 28px; border-top: 1px solid #333; width: 45%; }
        .spacer td { border: none; width: 10%; }
    </style>
</head>
<body>
    @php $e = $r['employee']; $c = $r['contract']; @endphp
    <h1>Arbeitszeitnachweis — {{ $r['period']->translatedFormat('F Y') }}</h1>
    <div class="sub">
        {{ $e->name }} · Pers.-Nr. {{ $e->personnel_number ?: '–' }}
        @if($c) · {{ $c->target_hours ? rtrim(rtrim(number_format($c->target_hours,2,',','.'),'0'),',').' h' : '' }}
            {{ \App\Models\Contract::MODELS[$c->worktime_model] ?? '' }}@endif
    </div>

    <table class="summary">
        <tr>
            <td><div class="label">Soll (Monat)</div><div class="val">{{ R::hhmm($r['month_sum']['soll']) }}</div></td>
            <td><div class="label">Ist (Monat)</div><div class="val">{{ R::hhmm($r['month_sum']['ist']) }}</div></td>
            <td><div class="label">Saldo Monat</div><div class="val {{ $r['month_sum']['saldo'] < 0 ? 'neg' : ($r['month_sum']['saldo'] > 0 ? 'pos' : '') }}">{{ R::hhmm($r['month_sum']['saldo']) }}</div></td>
            <td><div class="label">Resturlaub</div><div class="val">{{ number_format($r['vacation_left'], 1, ',', '.') }} T</div></td>
        </tr>
        <tr>
            <td><div class="label">Übertrag (Vorjahre)</div><div class="val {{ $r['carryover'] < 0 ? 'neg' : ($r['carryover'] > 0 ? 'pos' : '') }}">{{ R::hhmm($r['carryover']) }}</div></td>
            <td><div class="label">Saldo {{ $r['period']->year }}</div><div class="val {{ $r['year_balance'] < 0 ? 'neg' : ($r['year_balance'] > 0 ? 'pos' : '') }}">{{ R::hhmm($r['year_balance']) }}</div></td>
            <td><div class="label">Saldo gesamt</div><div class="val {{ $r['total_balance'] < 0 ? 'neg' : ($r['total_balance'] > 0 ? 'pos' : '') }}">{{ R::hhmm($r['total_balance']) }}</div></td>
            <td><div class="label">Sonderurlaub {{ $r['period']->year }}</div><div class="val">{{ number_format($r['special_taken'], 1, ',', '.') }} T</div></td>
        </tr>
    </table>

    @foreach($r['weeks'] as $week)
        <div class="kw">KW {{ $week['kw'] }}</div>
        <table class="week">
            <thead>
                <tr>
                    <th>Tag</th><th>Rein</th><th>Raus</th>
                    <th class="r">Ist</th><th class="r">Soll</th><th class="r">Saldo</th><th>Hinweis</th>
                </tr>
            </thead>
            <tbody>
                @foreach($week['rows'] as $row)
                    <tr class="{{ $row['weekend'] ? 'weekend' : '' }}">
                        <td class="{{ $row['in_month'] ? '' : 'muted' }}">{{ $row['wd'] }} {{ $row['day'] }}</td>
                        <td>{{ $row['in'] }}</td>
                        <td>{{ $row['out'] }}{{ $row['multiple'] ? ' *' : '' }}</td>
                        <td class="r">{{ $row['ist'] ? R::hhmm($row['ist']) : '' }}</td>
                        <td class="r">{{ $row['soll'] ? R::hhmm($row['soll']) : '' }}</td>
                        <td class="r {{ $row['saldo'] < 0 ? 'neg' : ($row['saldo'] > 0 ? 'pos' : '') }}">{{ ($row['ist'] || $row['soll']) ? R::hhmm($row['saldo']) : '' }}</td>
                        <td>{{ $row['hint'] }}</td>
                    </tr>
                @endforeach
                <tr class="sumrow">
                    <td colspan="3">Summe KW {{ $week['kw'] }}</td>
                    <td class="r">{{ R::hhmm($week['sum']['ist']) }}</td>
                    <td class="r">{{ R::hhmm($week['sum']['soll']) }}</td>
                    <td class="r">{{ R::hhmm($week['sum']['saldo']) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    @endforeach

    <table class="sign">
        <tr>
            <td>Datum, Unterschrift Mitarbeiter/in</td>
            <td class="spacer"></td>
            <td>Datum, Unterschrift Vorgesetzte/r</td>
        </tr>
    </table>
</body>
</html>
