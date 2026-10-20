<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Official holidays (§58) — managed by HR; the tier's extra Eid days and the bridge day follow automatically. */
class HolidayController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->integer('year') ?: (int) now()->year;
        $f = Access::for()->focus();

        return Holiday::where(fn ($q) => $q->whereYear('from', $year)->orWhereYear('to', $year))
            ->when($f, fn ($q) => $q->where(fn ($w) => $w->whereNull('company_id')->orWhere('company_id', $f)))
            ->orderBy('from')->get()->map(fn ($h) => $h->only('id', 'name', 'kind', 'company_id') + ['from' => $h->from->toDateString(), 'to' => $h->to->toDateString(), 'days' => $h->from->diffInDays($h->to) + 1]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403, 'إدارة العطلات الرسمية للموارد البشرية');
        $d = $request->validate(['name' => 'required|string|max:120', 'kind' => 'required|in:official,eid,company,bridge', 'from' => 'required|date', 'to' => 'required|date|after_or_equal:from',
            'company_id' => 'nullable|exists:companies,id', 'id' => 'nullable|exists:holidays,id']);
        $h = ! empty($d['id']) ? Holiday::findOrFail($d['id']) : new Holiday();
        $before = $h->exists ? $h->toArray() : null;
        $h->fill($d)->save();
        Holiday::flush();
        Audit::log('holidays', $h->id, $before ? 'update' : 'create', $before, $h->toArray());

        return response()->json($h, 201);
    }

    public function destroy(Request $request, Holiday $holiday)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        Audit::log('holidays', $holiday->id, 'delete', $holiday->toArray());
        $holiday->delete();

        return ['ok' => true];
    }
}
