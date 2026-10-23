<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Cardholder;
use App\Models\Employee;
use App\Models\UserLog;
use App\Services\WorktimeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Stempelungen und Abwesenheiten nachtragen, ohne durchs Panel zu klicken —
 * gedacht für das Übernehmen der jahrelang von Hand korrigierten Stundenzettel.
 *
 *   GET    /api/v1/admin/employees
 *   GET    /api/v1/admin/stampings?from=Y-m-d&to=Y-m-d[&employee_id=]
 *   POST   /api/v1/admin/stampings          {employee_id, date, timein, timeout?, card_uid?}
 *   PATCH  /api/v1/admin/stampings/{id}     {date?, timein?, timeout?, employee_id?}
 *   DELETE /api/v1/admin/stampings/{id}
 *   GET    /api/v1/admin/absences?from=&to=[&employee_id=]
 *   POST   /api/v1/admin/absences           {employee_id, type, start_date, end_date, half_day?, reason?}
 *   POST   /api/v1/admin/absences/{id}/approve
 *
 * Zeiten sind lokale Uhrzeit, wie sie auch die Leser schreiben. Jede Änderung
 * rechnet den betroffenen Tag im Arbeitszeitkonto neu, wie im Panel auch.
 */
class AdminApiController extends Controller
{
    public function __construct(private readonly WorktimeService $worktime)
    {
    }

    public function employees(): JsonResponse
    {
        $employees = Employee::query()->with('cards')->orderBy('name')->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'personnel_number' => $e->personnel_number,
                'is_active' => $e->is_active,
                'cards' => $e->cards->map(fn (Cardholder $c) => [
                    'card_uid' => $c->card_uid,
                    'username' => $c->username,
                ])->values(),
            ]);

        return response()->json(['employees' => $employees]);
    }

    public function stampings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $logs = UserLog::query()
            ->whereBetween('checkindate', [$data['from'], $data['to']])
            ->when($data['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->orderBy('checkindate')->orderBy('timein')
            ->get()
            ->map(fn (UserLog $log) => $this->present($log));

        return response()->json(['stampings' => $logs]);
    }

    public function storeStamping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'timein' => ['required', 'date_format:H:i:s'],
            'timeout' => ['nullable', 'date_format:H:i:s', 'after:timein'],
            'card_uid' => ['nullable', 'string', 'max:30'],
        ]);

        $card = Cardholder::query()
            ->when($data['card_uid'] ?? null,
                fn ($query, $uid) => $query->where('card_uid', $uid),
                fn ($query) => $query->where('employee_id', $data['employee_id']))
            ->first();

        $log = UserLog::create([
            'employee_id' => $data['employee_id'],
            'card_uid' => $card?->card_uid ?? ($data['card_uid'] ?? ''),
            'device_uid' => $card?->device_uid ?? 'Korrektur',
            'device_dep' => $card?->device_dep ?? 'Korrektur',
            'checkindate' => $data['date'],
            'timein' => $data['timein'],
            'timeout' => $data['timeout'] ?? '00:00:00',
            'card_out' => isset($data['timeout']),
            'source' => UserLog::SOURCE_CORRECTION,
        ]);

        $this->worktime->recalculateForLog($log);

        return response()->json(['stamping' => $this->present($log)], 201);
    }

    public function updateStamping(Request $request, UserLog $log): JsonResponse
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'timein' => ['sometimes', 'date_format:H:i:s'],
            'timeout' => ['sometimes', 'nullable', 'date_format:H:i:s'],
            // Umhängen, wenn jemand mit fremder Karte gestempelt hat.
            'employee_id' => ['sometimes', 'integer', 'exists:employees,id'],
        ]);

        $before = clone $log;

        if (array_key_exists('employee_id', $data)) {
            $log->employee_id = $data['employee_id'];
        }
        if (array_key_exists('date', $data)) {
            $log->checkindate = $data['date'];
        }
        if (array_key_exists('timein', $data)) {
            $log->timein = $data['timein'];
        }
        if (array_key_exists('timeout', $data)) {
            $log->timeout = $data['timeout'] ?? '00:00:00';
            $log->card_out = $data['timeout'] !== null;
        }

        $timesChanged = array_intersect_key($data, array_flip(['timein', 'timeout'])) !== [];
        if ($timesChanged && $log->card_out && $log->timeout <= $log->timein) {
            return response()->json(['error' => 'Gehen muss nach Kommen liegen'], 422);
        }

        $log->save();

        $this->worktime->recalculateForLog($log);
        if ($before->employee_id !== $log->employee_id
            || substr((string) $before->checkindate, 0, 10) !== substr((string) $log->checkindate, 0, 10)) {
            $this->worktime->recalculateForLog($before);
        }

        return response()->json(['stamping' => $this->present($log)]);
    }

    public function destroyStamping(UserLog $log): JsonResponse
    {
        $log->delete();
        $this->worktime->recalculateForLog($log);

        return response()->json(['deleted' => $log->id]);
    }

    public function absences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'],
        ]);

        $absences = Absence::query()
            ->whereDate('start_date', '<=', $data['to'])
            ->whereDate('end_date', '>=', $data['from'])
            ->when($data['employee_id'] ?? null, fn ($query, $id) => $query->where('employee_id', $id))
            ->orderBy('start_date')
            ->get()
            ->map(fn (Absence $a) => $this->presentAbsence($a));

        return response()->json(['absences' => $absences]);
    }

    /**
     * Legt eine Abwesenheit gleich genehmigt an — sie ist ja längst passiert.
     * Überschneidet sie sich mit einer offenen oder genehmigten, gibt es 409
     * statt einer zweiten Buchung für denselben Tag.
     */
    public function storeAbsence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'type' => ['required', Rule::in(array_keys(Absence::TYPES))],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $absence = DB::transaction(function () use ($data) {
            $overlap = Absence::query()
                ->where('employee_id', $data['employee_id'])
                ->whereIn('status', [Absence::STATUS_APPROVED, Absence::STATUS_PENDING])
                ->whereDate('start_date', '<=', $data['end_date'])
                ->whereDate('end_date', '>=', $data['start_date'])
                ->first();
            if ($overlap) {
                return $overlap;
            }

            return Absence::create([
                ...$data,
                'half_day' => $data['half_day'] ?? false,
                'status' => Absence::STATUS_APPROVED,
                'approver_id' => Auth::id(),
                'decided_at' => Carbon::now(),
                'decision_note' => 'Nachgetragen über Admin-API',
            ]);
        });

        if (! $absence->wasRecentlyCreated) {
            return response()->json([
                'error' => 'Überschneidet sich mit einer bestehenden Abwesenheit',
                'absence' => $this->presentAbsence($absence),
            ], 409);
        }

        $this->worktime->recalculateForAbsence($absence);

        return response()->json(['absence' => $this->presentAbsence($absence)], 201);
    }

    /** Genehmigen wie im Panel: Status, Genehmiger, Zeitpunkt, Konto neu rechnen. */
    public function approveAbsence(Absence $absence): JsonResponse
    {
        if ($absence->status !== Absence::STATUS_APPROVED) {
            $absence->update([
                'status' => Absence::STATUS_APPROVED,
                'approver_id' => Auth::id(),
                'decided_at' => Carbon::now(),
            ]);
            $this->worktime->recalculateForAbsence($absence);
        }

        return response()->json(['absence' => $this->presentAbsence($absence)]);
    }

    private function present(UserLog $log): array
    {
        return [
            'id' => $log->id,
            'employee_id' => $log->employee_id,
            'card_uid' => $log->card_uid,
            'date' => substr((string) $log->checkindate, 0, 10),
            'timein' => substr((string) $log->timein, 0, 8),
            'timeout' => $log->card_out ? substr((string) $log->timeout, 0, 8) : null,
            'source' => $log->source,
        ];
    }

    private function presentAbsence(Absence $absence): array
    {
        return [
            'id' => $absence->id,
            'employee_id' => $absence->employee_id,
            'type' => $absence->type,
            'start_date' => $absence->start_date->toDateString(),
            'end_date' => $absence->end_date->toDateString(),
            'half_day' => $absence->half_day,
            'status' => $absence->status,
            'reason' => $absence->reason,
        ];
    }
}
