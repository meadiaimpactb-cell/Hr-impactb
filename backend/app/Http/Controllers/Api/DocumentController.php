<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Lookup;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employee & company documents with expiry states driven by docAlertDays; types are lookups (scope + mandatory). */
class DocumentController extends Controller
{
    public static function state(Document $d, ?int $companyId = null): array
    {
        if ($d->renewed) {
            return ['code' => 'renewed', 'label' => 'مجددة', 'color' => 'gray', 'days' => null];
        }
        if (! $d->expiry_date) {
            return ['code' => 'nodate', 'label' => 'بلا تاريخ', 'color' => 'gray', 'days' => null];
        }
        $n = (int) now()->startOfDay()->diffInDays($d->expiry_date, false);
        $alerts = collect((array) Settings::get('docAlertDays', $companyId, [90, 60, 30, 7]))->map(fn ($x) => (int) $x)->sort()->values();
        if ($n < 0) {
            return ['code' => 'expired', 'label' => 'منتهية', 'color' => 'bad', 'days' => $n];
        }
        if ($n <= ($alerts[0] ?? 7)) {
            return ['code' => 'critical', 'label' => 'حرجة', 'color' => 'bad', 'days' => $n];
        }
        if ($n <= ($alerts[1] ?? 30)) {
            return ['code' => 'soon', 'label' => 'قريبة الانتهاء', 'color' => 'warn', 'days' => $n];
        }
        if ($n <= ($alerts->last() ?? 90)) {
            return ['code' => 'follow', 'label' => 'تحتاج متابعة', 'color' => 'info', 'days' => $n];
        }

        return ['code' => 'valid', 'label' => 'سارية', 'color' => 'ok', 'days' => $n];
    }

    public function index(Request $request)
    {
        $acc = Access::for();
        abort_unless($acc->canAny('DOC', 'view'), 403);
        $scope = $request->input('scope', 'employee');
        $q = Document::where('scope', $scope);
        if ($scope === 'employee') {
            $q->whereIn('owner_id', $acc->employees('DOC')->pluck('id'))->with('employee:id,name,emp_no,company_id');
        } else {
            $q->whereIn('owner_id', $acc->companyIds('DOC'))->with('company:id,name');
        }
        $rows = $q->orderBy('expiry_date')->get()->map(fn ($d) => $this->row($d));
        // missing mandatory documents per employee / company
        $types = collect(Lookup::grouped()['document_types'] ?? [])->filter(fn ($t) => ($t['meta']['scope'] ?? '') === $scope);
        $mandatory = $types->filter(fn ($t) => ! empty($t['meta']['mandatory']))->pluck('code');
        $owners = $scope === 'employee' ? $acc->employees('DOC')->where('status', '!=', 'terminated')->get(['id', 'name']) : Company::whereIn('id', $acc->companyIds('DOC'))->get(['id', 'name']);
        $have = $rows->groupBy('owner_id')->map(fn ($g) => $g->pluck('type')->all());
        $missing = [];
        foreach ($owners as $o) {
            foreach ($mandatory as $m) {
                if (! in_array($m, $have[$o->id] ?? [], true)) {
                    $missing[] = ['owner_id' => $o->id, 'owner' => $o->name, 'type' => $m, 'type_name' => Lookup::name('document_types', $m)];
                }
            }
        }

        return ['rows' => $rows, 'missing' => $missing, 'types' => $types->values()];
    }

    public function mine(Request $request)
    {
        $e = $request->user()->employee;
        abort_unless($e, 404);

        return Document::where('scope', 'employee')->where('owner_id', $e->id)->orderBy('expiry_date')->get()->map(fn ($d) => $this->row($d));
    }

    public function store(Request $request, ?Document $document = null)
    {
        $types = collect(Lookup::grouped()['document_types'] ?? []);
        $d = $request->validate(['scope' => 'required|in:employee,company', 'owner_id' => 'required|integer', 'type' => ['required', Rule::in($types->pluck('code'))],
            'number' => 'nullable|string|max:80', 'issue_date' => 'nullable|date', 'expiry_date' => 'nullable|date|after_or_equal:issue_date', 'issuer' => 'nullable|string|max:120', 'follow_user_id' => 'nullable|exists:users,id',
            'attachment' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png,webp,heic,doc,docx,xls,xlsx'], ['expiry_date.after_or_equal' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ الإصدار', 'attachment.mimes' => 'نوع الملف غير مسموح (PDF أو صورة أو Word أو Excel)']);
        if ($document) { // updating: must own/manage the existing document, and re-homing it needs DOC edit on both sides
            $this->authorizeOwner($document->scope, $document->owner_id, 'edit');
            $this->guardHrRecord($document);
            if ($document->scope !== $d['scope'] || (int) $document->owner_id !== (int) $d['owner_id']) {
                abort_unless(Access::for()->canAny('DOC', 'edit'), 403, 'لا يمكن نقل الوثيقة لمالك آخر');
            }
        }
        $this->authorizeOwner($d['scope'], (int) $d['owner_id'], 'edit');
        if ($request->hasFile('attachment')) {
            $d['attachment_path'] = $request->file('attachment')->store("docs/{$d['scope']}/{$d['owner_id']}", 'local');
        }
        $before = $document?->toArray();
        if ($document) {
            $document->update($d + ['alerted' => $document->expiry_date?->toDateString() !== ($d['expiry_date'] ?? null) ? [] : $document->alerted]);
        } else {
            $document = Document::create($d + ['alerted' => []]);
        }
        Audit::log('documents', $document->id, $before ? 'update' : 'create', $before, $document->toArray());

        return $this->row($document->fresh());
    }

    /** Renew: old values are archived into renewals, new number/dates set, alerts reset. */
    public function renew(Request $request, Document $document)
    {
        $this->authorizeOwner($document->scope, $document->owner_id, 'edit');
        $d = $request->validate(['number' => 'nullable|string|max:80', 'issue_date' => 'nullable|date', 'expiry_date' => 'required|date|after:today']);
        $hist = [...($document->renewals ?? []), ['number' => $document->number, 'issue_date' => $document->issue_date?->toDateString(), 'expiry_date' => $document->expiry_date?->toDateString(), 'at' => now()->toDateTimeString(), 'by' => $request->user()->name]];
        if ($request->hasFile('attachment')) {
            $d['attachment_path'] = $request->file('attachment')->store("docs/{$document->scope}/{$document->owner_id}", 'local');
        }
        $document->update($d + ['renewals' => $hist, 'renewed' => false, 'alerted' => []]);
        Audit::log('documents', $document->id, 'renew', null, $d);

        return $this->row($document->fresh());
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorizeOwner($document->scope, $document->owner_id, 'edit');
        $this->guardHrRecord($document);
        $document->delete();
        Audit::log('documents', $document->id, 'delete', $document->toArray());

        return ['deleted' => true];
    }

    /** An employee may change or remove only documents they uploaded themselves; records entered by HR stay with HR. */
    private function guardHrRecord(Document $doc): void
    {
        $u = auth()->user();
        if ($doc->scope === 'employee' && (int) $doc->owner_id === (int) $u->employee_id && ! Access::for()->can('DOC', 'edit', ['employee' => $doc->employee ?? Employee::find($doc->owner_id)])) {
            $mine = \App\Models\AuditLog::where('entity', 'documents')->where('entity_id', (string) $doc->id)->where('action', 'create')->where('user_id', $u->id)->exists();
            abort_unless($mine, 403, 'هذه الوثيقة سجلتها الموارد البشرية — اطلب تعديلها منهم');
        }
    }

    private function authorizeOwner(string $scope, int $ownerId, string $action): void
    {
        $acc = Access::for();
        if ($scope === 'employee') {
            $e = Employee::findOrFail($ownerId);
            abort_unless($acc->can('DOC', $action, ['employee' => $e]) || ($e->id === auth()->user()->employee_id && $action === 'edit' && Settings::get('employeeCanUploadDocs', $e->company_id, true)), 403);
        } else {
            abort_unless($acc->can('DOC', $action, ['company_id' => $ownerId]) && in_array($ownerId, $acc->companyIds('DOC'), true), 403);
        }
    }

    private function row(Document $d): array
    {
        $cid = $d->scope === 'employee' ? ($d->relationLoaded('employee') ? $d->employee?->company_id : Employee::whereKey($d->owner_id)->value('company_id')) : $d->owner_id;

        return $d->only('id', 'scope', 'owner_id', 'type', 'number', 'issue_date', 'expiry_date', 'issuer', 'follow_user_id', 'renewed', 'renewals') + [
            'type_name' => Lookup::name('document_types', $d->type), 'state' => self::state($d, $cid), 'has_file' => (bool) $d->attachment_path, 'file' => $d->attachment_path,
            'owner' => $d->scope === 'employee' ? ($d->relationLoaded('employee') ? $d->employee?->only('id', 'name', 'emp_no') : null) : ($d->relationLoaded('company') ? $d->company?->only('id', 'name') : null),
        ];
    }
}
