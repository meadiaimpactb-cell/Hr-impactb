<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ApprovalRequest;
use App\Models\AttendanceRecord;
use App\Models\AnnouncementReceipt;
use App\Models\Application;
use App\Models\Company;
use App\Models\Document;
use App\Models\Evaluation;
use App\Models\MonthlyReport;
use App\Models\Termination;
use App\Models\Violation;
use App\Models\WeeklyReport;
use App\Services\Access;
use App\Services\ApprovalService;
use App\Services\AttendanceService;
use App\Services\LeaveService;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // the daily jobs that drive the owner's notes (weekly-report deadlines, pending daily achievements) also start from
        // the first visit of the day, so they run even before the server's cron is configured (each runs once a day)
        if (\Illuminate\Support\Facades\Cache::add('hc.daily-kick.'.now()->toDateString(), 1, now()->endOfDay())) {
            dispatch(function () {
                \Illuminate\Support\Facades\Artisan::call('hc:weekly-watch');
                \Illuminate\Support\Facades\Artisan::call('hc:policy-reminders');
            })->afterResponse();
        }
        $u = $request->user();
        $acc = Access::for($u);
        $today = now()->toDateString();
        $empQ = $acc->employees()->where('status', '!=', 'terminated')->when($request->integer('company_id'), fn ($q, $c) => $q->where('company_id', $c));
        $ids = (clone $empQ)->pluck('id');
        $punchers = (clone $empQ)->where('punch_required', true)->count();
        $inbox = ApprovalService::inboxFor($u);
        $onLeave = ApprovalRequest::with('employee:id,name')->whereIn('employee_id', $ids)->where('type', 'leave')->where('status', 'approved')
            ->where('data->from', '<=', now()->addDays(6)->toDateString())->where('data->to', '>=', $today)->get(['id', 'employee_id', 'data']);

        $me = null;
        if ($e = $u->employee) {
            $e->load('company.tier', 'schedule');
            $me = [
                'today' => $e->attendance()->whereDate('work_date', $today)->first(),
                'punch_required' => $e->punch_required,
                'shift' => $e->hoursFor((int) now()->dayOfWeek), // employee's own hours or the schedule's; null on a day off
                'annual' => LeaveService::balance($e, 'annual'),
                'pending_mine' => ApprovalRequest::where('employee_id', $e->id)->where('status', 'pending')->count(),
                'month' => AttendanceService::summary($e, now()->format('Y-m')),
            ];
            // Personal action items across modules
            $prev = now()->subMonthNoOverflow()->format('Y-m');
            $rep = MonthlyReport::where('employee_id', $e->id)->where('period', $prev)->first();
            \App\Services\EvaluationService::ensureWeeks($e, now()->format('Y-m'));
            $lastEval = Evaluation::where('employee_id', $e->id)->where('type', 'monthly')->orderByDesc('period')->first();
            $me['todo'] = array_values(array_filter([
                (! $rep || in_array($rep->status, ['draft', 'returned'], true)) && now()->day <= 25 ? ['screen' => 'reports', 'label' => "تقريرك الشهري عن $prev ".($rep?->status === 'returned' ? 'مُرجَع للتعديل' : 'لم يُرفع بعد'), 'kind' => 'warn'] : null,
                ($w = WeeklyReport::where('employee_id', $e->id)->where('status', 'missing')->where('week_end', '<', $today)->count()) ? ['screen' => 'reports', 'label' => "$w تقارير أسبوعية لم تُرفع", 'kind' => 'warn'] : null,
                ($np = $e->attendance()->where('note_pending', true)->where('work_date', '>=', now()->subDays((int) \App\Services\Settings::get('dailyNoteLateDays', $e->company_id, 7))->toDateString())->count()) ? ['screen' => 'attendance', 'label' => "التحضير معلّق في $np يوم حتى تكتب المنجز اليومي", 'kind' => 'bad'] : null,
                ($v = Violation::where('employee_id', $e->id)->where('status', 'awaiting_statement')->count()) ? ['screen' => 'violations', 'label' => "$v مخالفة تنتظر إفادتك", 'kind' => 'bad'] : null,
                ($a = AnnouncementReceipt::where('user_id', $u->id)->whereNull('ack_at')->whereHas('announcement', fn ($q) => $q->where('require_ack', true))->count()) ? ['screen' => 'announcements', 'label' => "$a إعلانات تتطلب إقرارك", 'kind' => 'info'] : null,
                ($dx = Document::where('scope', 'employee')->where('owner_id', $e->id)->whereNotNull('expiry_date')->where('renewed', false)->where('expiry_date', '<=', now()->addDays(30)->toDateString())->count()) ? ['screen' => 'documents', 'label' => "$dx وثائق تنتهي خلال 30 يومًا", 'kind' => 'warn'] : null,
                ($c = \App\Models\CustodyItem::where('employee_id', $e->id)->where('status', 'pending_ack')->count()) ? ['screen' => 'custody', 'label' => "$c عهد بانتظار إقرار استلامك", 'kind' => 'info'] : null,
            ]));
            $me['last_eval'] = $lastEval ? $lastEval->only('period', 'score', 'grade', 'grade_label') : null;
        }
        // HR / manager operational widgets (scoped)
        $ops = null;
        if ($acc->canAny('PRF', 'view')) {
            $prevP = now()->subMonthNoOverflow()->format('Y-m');
            $ops = [
                'reports_waiting' => MonthlyReport::whereIn('employee_id', $ids)->where('period', $prevP)->whereIn('status', ['submitted', 'mgr_reviewed', 'hr_reviewed'])->count(),
                'reports_missing' => max(0, $ids->count() - MonthlyReport::whereIn('employee_id', $ids)->where('period', $prevP)->where('status', '!=', 'draft')->count()),
                'docs_alert' => Document::where(fn ($q) => $q->where(fn ($w) => $w->where('scope', 'employee')->whereIn('owner_id', $ids))->orWhere(fn ($w) => $w->where('scope', 'company')->whereIn('owner_id', $acc->companyIds())))->whereNotNull('expiry_date')->where('renewed', false)->where('expiry_date', '<=', now()->addDays(30)->toDateString())->count(),
                'violations_open' => Violation::whereIn('employee_id', $ids)->whereIn('status', ['awaiting_statement', 'awaiting_approval'])->count(),
                'terminations_open' => Termination::whereIn('employee_id', $ids)->whereIn('status', ['open', 'settled'])->count(),
                'applications_new' => $u->isHr() ? Application::whereIn('company_id', $acc->companyIds())->where('stage', 'new')->count() : 0,
                'evals_unpublished' => max(0, MonthlyReport::whereIn('employee_id', $ids)->where('period', $prevP)->where('status', 'approved')->count() - Evaluation::whereIn('employee_id', $ids)->where('period', $prevP)->count()),
            ];
        }

        return [
            'kpis' => [
                'employees' => $ids->count(),
                'companies' => count($acc->companyIds()),
                'inbox' => $inbox->count(),
                'inbox_late' => $inbox->filter(fn ($r) => $r->created_at->diffInDays(now()) >= (int) \App\Services\Settings::conf('approvalSlaDays'))->count(),
                'present' => AttendanceRecord::whereIn('employee_id', $ids)->whereDate('work_date', $today)->whereNotNull('check_in')->count(),
                'active_now' => AttendanceRecord::whereIn('employee_id', $ids)->whereDate('work_date', $today)->whereNotNull('check_in')->whereNull('check_out')->count(),
                'punchers' => $punchers,
                'on_leave_today' => $onLeave->filter(fn ($r) => $r->data['from'] <= $today)->count(),
                'pending_attendance' => AttendanceRecord::whereIn('employee_id', $ids)->where('pending', true)->count(),
            ],
            'inbox' => $inbox->take(6)->map(fn ($r) => ['id' => $r->id, 'type_label' => ApprovalService::label($r->type), 'summary' => ApprovalService::summary($r),
                'employee' => $r->employee?->name, 'age_days' => (int) $r->created_at->diffInDays(now())])->values(),
            'on_leave' => $onLeave->map(fn ($r) => ['employee' => $r->employee?->name, 'from' => $r->data['from'], 'to' => $r->data['to'], 'type' => $r->data['type_name'] ?? ''])->values(),
            'by_company' => Company::whereIn('id', $acc->companyIds())->withCount(['employees' => fn ($q) => $q->where('status', '!=', 'terminated')])->get(['id', 'name', 'code']),
            'me' => $me,
            'ops' => $ops,
            'unread' => AppNotification::where('user_id', $u->id)->whereNull('read_at')->count(),
        ];
    }
}
