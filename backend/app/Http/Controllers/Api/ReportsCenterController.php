<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Advance;
use App\Models\ApprovalRequest;
use App\Models\CustodyItem;
use App\Models\DevRecord;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Evaluation;
use App\Models\LeaveLedger;
use App\Models\LeaveType;
use App\Models\Lookup;
use App\Models\Payslip;
use App\Models\Violation;
use App\Models\WeeklyReport;
use App\Services\Access;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Reports centre: catalog of tabular reports over the user's scope, with a period picker and favourites. */
class ReportsCenterController extends Controller
{
    public const CATALOG = [
        'att_detail' => ['الحضور التفصيلي', 'ATT', 'range'], 'att_month' => ['ملخص الحضور الشهري', 'ATT', 'month'], 'lv_bal' => ['أرصدة الإجازات', 'LVE', 'none'],
        'lv_moves' => ['حركات الإجازات', 'LVE', 'range'], 'payroll' => ['الرواتب', 'PAY', 'month'], 'custody' => ['العهد', 'CUS', 'none'], 'docs' => ['الوثائق غير السارية', 'DOC', 'none'],
        'evals' => ['التقييمات المنشورة', 'PRF', 'range'], 'weekly' => ['التقارير الأسبوعية', 'PRF', 'range'], 'dev' => ['التطوير والتدريب', 'DEV', 'range'], 'alloc' => ['توزيع الموظفين', 'EMP', 'none'],
        'viol' => ['المخالفات', 'VIO', 'range'], 'turnover' => ['معدل الدوران', 'EMP', 'range'], 'requests' => ['الطلبات والاعتمادات', 'EMP', 'range'],
        'fines' => ['سجل الغرامات (§106)', 'VIO', 'range'],
    ];

    /** §117: financial reports — the only ones a finance user (without an HR / CEO role) may run, print and export. */
    public const FINANCIAL = ['payroll', 'fines', 'custody'];

    /**
     * §117 print / export scope: group CEO every report; company CEO his company's employees; HR its scope; finance the
     * financial reports; a manager his own team (the employee scope itself comes from Access::employees()).
     */
    private static function allowed(string $key): bool
    {
        $u = auth()->user();
        if (! $u) {
            return false;
        }
        $financeOnly = $u->hasRole('fin') && ! $u->hasRole('super', 'hr_mgr', 'hr_staff', 'ceo') && ! Access::for()->isCeoOf($u->employee?->company_id);

        return ! $financeOnly || in_array($key, self::FINANCIAL, true);
    }

    /** View right on a report: its operation, or PAY for the fines register (finance keeps it with HR, §106). */
    private static function canView(string $key): bool
    {
        $acc = Access::for();
        $op = self::CATALOG[$key][1];

        return $acc->canAny($op, 'view') || ($key === 'fines' && $acc->canAny('PAY', 'view'));
    }

    private static function scopeLabel(): string
    {
        $u = auth()->user();

        return match (true) {
            $u->hasRole('super') => 'الرئيس التنفيذي للمجموعة — كل الشركات',
            $u->hasRole('hr_mgr', 'hr_staff') => 'الموارد البشرية — نطاق صلاحيتك',
            $u->hasRole('ceo') || (bool) \App\Models\Company::where('ceo_employee_id', $u->employee_id)->exists() => 'الرئيس التنفيذي للشركة — موظفو شركتك',
            $u->hasRole('fin') => 'المالية — التقارير المالية',
            default => 'فريقك المباشر',
        };
    }

    /** Print / export trail (§117: export is controlled and traceable). */
    public function exported(Request $request, string $key)
    {
        abort_unless(isset(self::CATALOG[$key]) && self::allowed($key) && self::canView($key), 403);
        $d = $request->validate(['kind' => 'required|in:print,excel', 'from' => 'nullable|string|max:10', 'to' => 'nullable|string|max:10', 'period' => 'nullable|string|max:7', 'company_id' => 'nullable|integer']);
        \App\Services\Audit::log('reports_center', $key, 'export_'.$d['kind'], null, $d);

        return ['ok' => true];
    }

    public function catalog(Request $request)
    {
        $acc = Access::for();
        $fav = DB::table('report_favorites')->where('user_id', $request->user()->id)->pluck('key')->all();

        return collect(self::CATALOG)->filter(fn ($c, $k) => self::canView($k) && self::allowed($k))
            ->map(fn ($c, $k) => ['key' => $k, 'name' => $c[0], 'op' => $c[1], 'period' => $c[2], 'favorite' => in_array($k, $fav, true), 'can_export' => true, 'scope' => self::scopeLabel()])->values();
    }

    public function favorite(Request $request, string $key)
    {
        abort_unless(isset(self::CATALOG[$key]), 404);
        $row = ['user_id' => $request->user()->id, 'key' => $key];
        DB::table('report_favorites')->where($row)->exists() ? DB::table('report_favorites')->where($row)->delete() : DB::table('report_favorites')->insert($row);

        return ['ok' => true];
    }

    public function run(Request $request, string $key)
    {
        $c = self::CATALOG[$key] ?? abort(404);
        $acc = Access::for();
        abort_unless(self::canView($key) && self::allowed($key), 403, 'هذا التقرير خارج نطاق صلاحيتك (§117)');
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());
        $period = $request->input('period', now()->format('Y-m'));
        $emps = $acc->employees()->when($request->integer('company_id'), fn ($q, $cid) => $q->where('company_id', $cid))->with('company:id,name', 'jobTitle:id,name')->orderBy('name')->get();
        $ids = $emps->pluck('id');
        $name = fn ($id) => $emps->firstWhere('id', $id)?->name;
        [$cols, $rows] = match ($key) {
            'att_detail' => [['الموظف', 'الشركة', 'التاريخ', 'الحالة', 'الحضور', 'الانصراف', 'تأخير (د)', 'خروج مبكر (د)', 'الموقع'],
                \App\Models\AttendanceRecord::with('location:id,name')->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->get()
                    ->map(fn ($r) => [$name($r->employee_id), $emps->firstWhere('id', $r->employee_id)?->company?->name, $r->work_date->toDateString(), $r->status, $r->check_in?->format('H:i'), $r->check_out?->format('H:i'), $r->late_minutes, $r->early_minutes, $r->location?->name])],
            'att_month' => [['الموظف', 'الشركة', 'أيام العمل', 'حضور', 'تأخيرات', 'دقائق التأخير', 'غياب', 'إجازة'],
                $emps->where('punch_required', true)->map(fn ($e) => [$e->name, $e->company?->name, ...array_values(array_intersect_key(AttendanceService::summary($e, $period), array_flip(['required', 'present', 'late', 'late_minutes', 'absent', 'leave'])))])],
            'lv_bal' => (function () use ($emps) {
                $types = LeaveType::where('active', true)->whereNotNull('quota_key')->where('accrual', '!=', 'none')->orderBy('sort')->get();

                return [['الموظف', 'الشركة', ...$types->pluck('name')->all()], $emps->where('status', '!=', 'terminated')->map(fn ($e) => [$e->name, $e->company?->name, ...$types->map(fn ($t) => LeaveService::balance($e, $t)['available'])->all()])];
            })(),
            'lv_moves' => [['الموظف', 'التاريخ', 'النوع', 'الحركة', 'الأيام', 'ملاحظة'], LeaveLedger::whereIn('employee_id', $ids)->whereBetween('occurred_on', [$from, $to])->orderBy('occurred_on')->get()->map(fn ($l) => [$name($l->employee_id), $l->occurred_on, $l->leave_type, $l->entry, $l->days, $l->note])],
            'payroll' => (function () use ($ids, $period, $acc, $emps) {
                abort_unless($acc->canAny('PAY', 'view'), 403);

                return [['الموظف', 'الشركة', 'الفترة', 'الإجمالي', 'الاستقطاعات', 'الصافي', 'الحالة'], Payslip::whereIn('employee_id', $ids)->where('period', $period)->get()->map(fn ($p) => [$emps->firstWhere('id', $p->employee_id)?->name, $emps->firstWhere('id', $p->employee_id)?->company?->name, $p->period, $p->line['gross'] ?? 0, $p->deductionsTotal(), $p->net(), $p->published ? 'منشورة' : 'غير منشورة'])];
            })(),
            'custody' => [['العهدة', 'النوع', 'الشركة', 'الموظف', 'القيمة', 'الحالة'], CustodyItem::with('type:id,name', 'company:id,name', 'employee:id,name')->where(fn ($q) => $q->whereIn('company_id', $acc->companyIds())->orWhereIn('employee_id', $ids))->get()->map(fn ($i) => [$i->ident, $i->type?->name, $i->company?->name, $i->employee?->name, $i->value, CustodyItem::STATUSES[$i->status] ?? $i->status])],
            'docs' => [['المالك', 'النطاق', 'النوع', 'الرقم', 'الانتهاء', 'الحالة'], Document::where(fn ($q) => $q->where(fn ($w) => $w->where('scope', 'employee')->whereIn('owner_id', $ids))->orWhere(fn ($w) => $w->where('scope', 'company')->whereIn('owner_id', $acc->companyIds())))->get()
                ->map(fn ($d) => [$d->scope === 'employee' ? $name($d->owner_id) : \App\Models\Company::whereKey($d->owner_id)->value('name'), $d->scope === 'employee' ? 'موظف' : 'شركة', Lookup::name('document_types', $d->type), $d->number, $d->expiry_date?->toDateString(), DocumentController::state($d)['label']])->filter(fn ($r) => $r[5] !== 'سارية')->values()],
            'evals' => [['الموظف', 'الشهر', 'إنجاز', 'حضور', 'تطوير', 'انضباط', 'الدرجة', 'التقدير', 'اعتراض'], Evaluation::whereIn('employee_id', $ids)->where('type', 'monthly')->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])->orderBy('period')->get()->map(fn ($e) => [$name($e->employee_id), $e->period, $e->axes['achieve'] ?? null, $e->axes['attend'] ?? 'معفى', $e->axes['dev'] ?? null, $e->axes['disc'] ?? null, $e->score, $e->grade, $e->objection_status])],
            'weekly' => [['الموظف', 'الأسبوع', 'الحالة', 'رُفع في'], WeeklyReport::whereIn('employee_id', $ids)->whereBetween('week_start', [$from, $to])->orderBy('week_start')->get()->map(fn ($w) => [$name($w->employee_id), $w->week_start->toDateString(), ['submitted' => 'في الموعد', 'late' => 'متأخر', 'missing' => 'لم يُرفع'][$w->status] ?? $w->status, $w->submitted_at?->toDateTimeString()])],
            'dev' => [['الموظف', 'البرنامج', 'الجهة', 'من', 'إلى', 'الساعات', 'التكلفة', 'الحالة'], DevRecord::whereIn('employee_id', $ids)->where(fn ($q) => $q->whereBetween('from', [$from, $to])->orWhereBetween('to', [$from, $to]))->get()->map(fn ($r) => [$name($r->employee_id), $r->program, $r->provider, $r->from?->toDateString(), $r->to?->toDateString(), $r->hours, $r->cost, DevRecord::STATUSES[$r->status] ?? $r->status])],
            'alloc' => [['الشركة', 'الوظيفة', 'نوع التعاقد', 'العدد'], $emps->where('status', '!=', 'terminated')->groupBy(fn ($e) => ($e->company?->name ?? '').'|'.($e->jobTitle?->name ?? '—').'|'.$e->contract_type)->map(fn ($g, $k) => [...explode('|', $k), $g->count()])->values()],
            'viol' => [['الموظف', 'النوع', 'التاريخ', 'الدرجة', 'الخصم', 'الحالة'], Violation::whereIn('employee_id', $ids)->whereBetween('occurred_on', [$from, $to])->get()->map(fn ($v) => [$name($v->employee_id), Lookup::name('violation_types', $v->type), $v->occurred_on->toDateString(), $v->signed_degree ? Lookup::name('penalty_degrees', (string) $v->signed_degree) : '—', $v->amount, Violation::STATUSES[$v->status] ?? $v->status])],
            'turnover' => (function () use ($emps, $from, $to) {
                $rows = $emps->groupBy('company_id')->map(function ($g) use ($from, $to) {
                    $left = $g->filter(fn ($e) => $e->status === 'terminated' && $e->last_working_day && $e->last_working_day->between(Carbon::parse($from), Carbon::parse($to)))->count();
                    $active = $g->where('status', '!=', 'terminated')->count();
                    $joined = $g->filter(fn ($e) => $e->hire_date->between(Carbon::parse($from), Carbon::parse($to)))->count();

                    return [$g->first()->company?->name, $active, $joined, $left, ($active + $left) ? round($left / (($active + $left) / 2) * 100, 1) : 0];
                })->values();

                return [['الشركة', 'النشطون', 'التحق', 'غادر', 'معدل الدوران %'], $rows];
            })(),
            'fines' => [['الموظف', 'الشركة', 'نوع المخالفة', 'تاريخ الواقعة', 'الفترة', 'الأجر الأساسي اليومي', 'الغرامة'], Violation::whereIn('employee_id', $ids)->where('status', 'active')->where('amount', '>', 0)
                ->whereBetween('period', [substr($from, 0, 7), substr($to, 0, 7)])->orderBy('period')->get()->map(fn ($v) => [$name($v->employee_id), $emps->firstWhere('id', $v->employee_id)?->company?->name, Lookup::name('violation_types', $v->type), $v->occurred_on->toDateString(), $v->period, $v->basis, $v->amount])],
            'requests' => [['الموظف', 'النوع', 'الملخص', 'الحالة', 'المرحلة', 'التاريخ'], ApprovalRequest::whereIn('employee_id', $ids)->whereBetween('created_at', [$from, $to.' 23:59:59'])->orderByDesc('id')->limit(2000)->get()->map(fn ($r) => [$name($r->employee_id), \App\Services\ApprovalService::label($r->type), \App\Services\ApprovalService::summary($r), $r->status, $r->flow[$r->stage]['label'] ?? '—', $r->created_at->toDateString()])],
        };

        return ['key' => $key, 'name' => $c[0], 'columns' => $cols, 'rows' => collect($rows)->values(), 'generated_at' => now()->toDateTimeString(), 'params' => compact('from', 'to', 'period'), 'scope' => self::scopeLabel(), 'can_export' => true];
    }
}
