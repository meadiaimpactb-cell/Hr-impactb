<?php

namespace App\Services;

use App\Http\Controllers\Api\ReportsCenterController;
use App\Models\Advance;
use App\Models\ApprovalRequest;
use App\Models\CustodyItem;
use App\Models\Employee;
use App\Models\Evaluation;
use Illuminate\Http\Request;

/**
 * The data the in-platform assistant may read. Every tool runs as the signed-in user and goes through the platform's
 * own permission layer (Access::for(), the report centre's scope rules), so the assistant can never show more than the
 * user could open on screen.
 */
class AssistantTools
{
    public static function definitions(): array
    {
        $obj = fn (array $props = [], array $req = []) => ['type' => 'object', 'properties' => (object) $props, 'required' => $req, 'additionalProperties' => false];

        return [
            ['name' => 'my_overview', 'description' => 'The signed-in employee\'s own situation: leave balances, this month\'s attendance, open requests and where each one is waiting, custody held, advances being repaid, and the latest published evaluation. Use for any question about "my" balance, attendance, requests, custody, advances or evaluation.',
                'inputSchema' => $obj()],
            ['name' => 'pending_approvals', 'description' => 'Requests currently waiting for the signed-in user\'s decision (approvals inbox), with how long each has waited in working days.',
                'inputSchema' => $obj()],
            ['name' => 'find_employee', 'description' => 'Look up employees the user is allowed to see by name or employee number: company, job, manager, status, hire date, annual leave balance and this month\'s attendance. Never returns salaries unless the user has salary rights.',
                'inputSchema' => $obj(['query' => ['type' => 'string', 'description' => 'Name or employee number (partial is fine)']], ['query'])],
            ['name' => 'list_reports', 'description' => 'The reports this user may run, with their keys and the period type each needs (range, month or none).',
                'inputSchema' => $obj()],
            ['name' => 'run_report', 'description' => 'Run one report from list_reports within the user\'s scope and return its columns and up to 40 rows, plus the total row count and a link to open the full printable version with the company identity.',
                'inputSchema' => $obj([
                    'key' => ['type' => 'string', 'description' => 'Report key from list_reports'],
                    'from' => ['type' => 'string', 'description' => 'Start date YYYY-MM-DD for range reports'],
                    'to' => ['type' => 'string', 'description' => 'End date YYYY-MM-DD for range reports'],
                    'period' => ['type' => 'string', 'description' => 'Month YYYY-MM for monthly reports'],
                    'company_id' => ['type' => 'integer', 'description' => 'Optional company filter'],
                ], ['key'])],
        ];
    }

    public static function run(string $name, array $in): array
    {
        $u = auth()->user();

        return match ($name) {
            'my_overview' => self::overview(),
            'pending_approvals' => ['items' => ApprovalService::inboxFor($u)->take(30)->map(fn ($r) => ['type' => ApprovalService::label($r->type), 'employee' => $r->employee?->name, 'summary' => ApprovalService::summary($r),
                'stage' => $r->flow[$r->stage]['label'] ?? null, 'waiting_working_days' => WorkCalendar::between(null, $r->stage_entered_at ?? $r->created_at)])->values()->all(), 'link' => '/approvals'],
            'find_employee' => self::findEmployee((string) ($in['query'] ?? '')),
            'list_reports' => ['reports' => app(ReportsCenterController::class)->catalog(request())->values()->all()],
            'run_report' => self::report($in),
            default => ['error' => 'unknown tool'],
        };
    }

    private static function overview(): array
    {
        $u = auth()->user();
        $e = $u->employee;
        if (! $e) {
            return ['note' => 'الحساب غير مرتبط بملف موظف'];
        }
        $period = now()->format('Y-m');
        $att = AttendanceService::summary($e, $period);
        $ev = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->orderByDesc('period')->first();

        return [
            'employee' => ['name' => $e->name, 'company' => $e->company?->name, 'job' => $e->jobTitle?->name, 'hire_date' => $e->hire_date?->format('d/m/Y')],
            'leave_balances' => collect(LeaveService::balances($e))->map(fn ($b) => ['type' => $b['name'], 'available' => $b['available'], 'used' => $b['used'], 'unit' => $b['unit_label'] ?? null])->all(),
            'attendance_this_month' => array_intersect_key($att, array_flip(['required', 'present', 'late', 'late_minutes', 'absent', 'leave'])),
            'open_requests' => ApprovalRequest::where('employee_id', $e->id)->where('status', 'pending')->latest()->limit(10)->get()
                ->map(fn ($r) => ['type' => ApprovalService::label($r->type), 'summary' => ApprovalService::summary($r), 'waiting_for' => $r->flow[$r->stage]['label'] ?? null])->all(),
            'custody' => CustodyItem::with('type:id,name,is_cash')->where('employee_id', $e->id)->whereNotIn('status', ['available', 'returned'])->get()
                ->map(fn ($c) => ['item' => $c->ident, 'type' => $c->type?->name, 'cash' => (bool) $c->type?->is_cash, 'value' => $c->type?->is_cash ? (float) $c->value : null, 'status' => CustodyItem::STATUSES[$c->status] ?? $c->status])->all(),
            'advances' => Advance::where('employee_id', $e->id)->where('status', 'repaying')->get(['amount', 'remaining', 'monthly_amount'])->toArray(),
            'latest_evaluation' => $ev ? ['period' => $ev->period, 'score' => $ev->score, 'grade' => $ev->grade, 'label' => $ev->grade_label] : null,
            'links' => ['leaves' => '/leaves', 'attendance' => '/attendance', 'requests' => '/requests', 'custody' => '/custody', 'evaluation' => '/evaluation'],
        ];
    }

    private static function findEmployee(string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['error' => 'اكتب حرفين على الأقل من الاسم أو الرقم الوظيفي'];
        }
        $acc = Access::for();
        $rows = $acc->employees()->with('company:id,name', 'jobTitle:id,name', 'manager:id,name')
            ->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('emp_no', 'like', "%$q%"))->limit(5)->get();

        return ['employees' => $rows->map(function (Employee $e) use ($acc) {
            $b = collect(LeaveService::balances($e))->firstWhere('type', 'annual');
            $att = AttendanceService::summary($e, now()->format('Y-m'));
            $row = ['name' => $e->name, 'emp_no' => $e->emp_no, 'company' => $e->company?->name, 'job' => $e->jobTitle?->name, 'manager' => $e->manager?->name,
                'status' => $e->status, 'hire_date' => $e->hire_date?->format('d/m/Y'), 'annual_leave_available' => $b['available'] ?? null,
                'attendance_this_month' => array_intersect_key($att, array_flip(['present', 'late', 'absent'])), 'link' => '/employees/'.$e->id];
            if ($acc->canSeeSalary($e)) {
                $row['monthly_gross'] = $e->monthlyGross();
            }

            return $row;
        })->all(), 'note' => $rows->isEmpty() ? 'لا يوجد موظف بهذا الاسم ضمن نطاق صلاحيتك' : null];
    }

    private static function report(array $in): array
    {
        $key = (string) ($in['key'] ?? '');
        $req = Request::create('/', 'GET', array_filter(['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'period' => $in['period'] ?? null, 'company_id' => $in['company_id'] ?? null]));
        $req->setUserResolver(fn () => auth()->user());
        try {
            $r = app(ReportsCenterController::class)->run($req, $key);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return ['error' => $e->getMessage() ?: 'هذا التقرير خارج نطاق صلاحيتك'];
        }
        $rows = collect($r['rows']);

        return ['name' => $r['name'], 'scope' => $r['scope'] ?? null, 'columns' => $r['columns'], 'rows' => $rows->take(40)->values()->all(), 'total_rows' => $rows->count(),
            'link' => '/reports-center?key='.$key.'&'.http_build_query(array_filter(['from' => $in['from'] ?? null, 'to' => $in['to'] ?? null, 'period' => $in['period'] ?? null]))];
    }
}
