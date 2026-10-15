<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustodyItem;
use App\Models\DevRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\Letter;
use App\Models\Lookup;
use App\Models\MonthlyReport;
use App\Models\Termination;
use App\Models\Violation;
use App\Services\Access;
use Illuminate\Http\Request;

/** One-call 360° view of an employee for the profile screen (each section honours its own permission). */
class Employee360Controller extends Controller
{
    public function show(Request $request, Employee $employee)
    {
        $acc = Access::for();
        $u = $request->user();
        $self = $employee->id === $u->employee_id;
        abort_unless($self || $acc->canSeeEmployee($employee), 403);
        $can = fn ($op) => $self || $acc->can($op, 'view', ['employee' => $employee]);

        return [
            'documents' => $can('DOC') ? Document::where('scope', 'employee')->where('owner_id', $employee->id)->orderBy('expiry_date')->get()
                ->map(fn ($d) => ['id' => $d->id, 'type' => Lookup::name('document_types', $d->type), 'type_code' => $d->type, 'number' => $d->number, 'issue_date' => $d->issue_date?->toDateString(), 'expiry_date' => $d->expiry_date?->toDateString(), 'issuer' => $d->issuer, 'file' => $d->attachment_path, 'state' => DocumentController::state($d, $employee->company_id)]) : null,
            'letters' => $can('EMP') ? Letter::where('employee_id', $employee->id)->orderByDesc('id')->limit(20)->get()->map(fn ($l) => ['id' => $l->id, 'no' => $l->no, 'kind' => Lookup::name('letter_kinds', $l->kind), 'to' => $l->to, 'issued_at' => $l->issued_at->toDateString(), 'signed' => (bool) $l->signed_doc_id]) : null,
            'evaluations' => $can('PRF') ? Evaluation::where('employee_id', $employee->id)->where('type', 'monthly')->orderByDesc('period')->limit(12)->get()->map(fn ($e) => $e->only('id', 'period', 'score', 'grade', 'grade_label', 'axes', 'objection_status')) : null,
            'reports' => $can('PRF') ? MonthlyReport::where('employee_id', $employee->id)->orderByDesc('period')->limit(12)->get()->map(fn ($r) => ['id' => $r->id, 'period' => $r->period, 'status' => $r->status, 'status_label' => MonthlyReport::STATUSES[$r->status] ?? $r->status, 'late' => $r->late, 'items' => count($r->items ?? []), 'quality' => $r->quality, 'coop' => $r->coop, 'mgr_note' => $r->mgr_note]) : null,
            'violations' => $can('VIO') ? Violation::where('employee_id', $employee->id)->orderByDesc('id')->get()->map(fn ($v) => ['id' => $v->id, 'type' => Lookup::name('violation_types', $v->type), 'occurred_on' => $v->occurred_on->toDateString(), 'degree' => $v->signed_degree ? Lookup::name('penalty_degrees', (string) $v->signed_degree) : null, 'amount' => $v->amount, 'status' => Violation::STATUSES[$v->status] ?? $v->status]) : null,
            'custody' => $can('CUS') ? CustodyItem::with('type:id,name')->where('employee_id', $employee->id)->get()->map(fn ($i) => ['id' => $i->id, 'ident' => $i->ident, 'type' => $i->type?->name, 'value' => $i->value, 'status' => CustodyItem::STATUSES[$i->status] ?? $i->status]) : null,
            'development' => $can('DEV') ? DevRecord::where('employee_id', $employee->id)->orderByDesc('from')->get()->map(fn ($r) => ['id' => $r->id, 'program' => $r->program, 'provider' => $r->provider, 'hours' => $r->hours, 'cost' => $r->cost, 'from' => $r->from?->toDateString(), 'to' => $r->to?->toDateString(), 'status' => DevRecord::STATUSES[$r->status] ?? $r->status]) : null,
            'insurance' => $can('EMP') ? ['current' => $employee->insurance, 'tier_class' => $employee->benefit('insuranceClass'), 'tier_dependents' => $employee->benefit('dependents')] : null,
            'termination' => $can('TRM') ? Termination::where('employee_id', $employee->id)->orderByDesc('id')->first()?->only('id', 'reason', 'last_working_day', 'status', 'stage') : null,
        ];
    }
}
