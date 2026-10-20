<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\Employee;
use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Policy 2.1 rules applied when a request is filed (one place per request type, so the rules stay readable):
 * tickets §53–54, leave deferral §57, special leaves §59–61, overtime §26, flexible work §30, bank account §38,
 * expense claims §45, probation §20–21, promotion / acting §22 and §84, transfer §23, allowances §39.
 */
class PolicyRules
{
    private static function fail(string $field, string $msg): never
    {
        throw ValidationException::withMessages([$field => $msg]);
    }

    /** Benefits year (§5): 1 February → 31 January. */
    public static function benefitsYear(Carbon $d): array
    {
        $start = $d->month >= 2 ? Carbon::create($d->year, 2, 1) : Carbon::create($d->year - 1, 2, 1);

        return [$start, $start->copy()->addYear()->subDay()];
    }

    public static function isSaudi(Employee $e): bool
    {
        return in_array(strtoupper((string) $e->nationality), ['SA', 'SAU'], true) || str_contains((string) $e->nationality, 'سعود');
    }

    public static function check(?string $handler, array $data, Employee $e, Request $request): array
    {
        return match ($handler) {
            'ticket' => self::ticket($data, $e),
            'ticket_carry' => ($e->benefit('ticketCap') ?? 0) > 0 ? $data : self::fail('reason', 'لا تذكرة إجازة في فئة شركتك (§52)'),
            'ticket_children' => (int) ($data['children'] ?? 0) >= 1 && (int) $data['children'] <= 8 ? $data : self::fail('children', 'عدد الأبناء غير صحيح'),
            'leave_defer' => self::leaveDefer($data, $e),
            'overtime' => self::overtime($data, $e),
            'flex_arrangement' => ($data['to'] ?? '') >= ($data['from'] ?? '') ? $data : self::fail('to', 'تاريخ النهاية قبل البداية'),
            'iban_change' => self::iban($data, $e),
            'expense' => self::expense($data, $e),
            'probation' => self::probation($data, $e),
            'probation_shorten' => self::probationShorten($data, $e),
            'promotion' => self::promotion($data, $e),
            'transfer' => (int) $data['to_company'] !== (int) $e->company_id ? ($data['consent'] ? $data : self::fail('consent', 'النقل يتطلب موافقة الموظف (§23)')) : self::fail('to_company', 'الموظف في هذه الشركة أصلًا'),
            'allowance' => self::allowance($data, $e),
            'tier_change' => self::tierChange($data, $e),
            default => $data,
        };
    }

    /** Extra stages computed from the data; null = the configured flow. */
    public static function flow(?string $handler, array $data, Employee $e): ?array
    {
        return null;
    }

    /** Request types whose approvers may change days / amounts with a reason (§12). */
    public static function adjustable(string $type): array
    {
        return match (ApprovalService::type($type)?->handler) {
            'leave' => ['from' => 'date', 'to' => 'date'],
            'trip' => ['from' => 'date', 'to' => 'date', 'advance' => 'number'],
            'advance' => ['amount' => 'number', 'installments' => 'number'],
            'overtime' => ['hours' => 'number'],
            'expense' => ['amount' => 'number'],
            'ticket' => ['estimate' => 'number'],
            'custody' => ['amount' => 'number'],
            'bonus' => ['amount' => 'number'],
            'leave_defer' => ['days' => 'number'],
            'flex_arrangement' => ['from' => 'date', 'to' => 'date'],
            default => [],
        };
    }

    /** §44: an overdue cash-custody settlement stops any new advance or custody. */
    public static function overdueCustody(Employee $e): bool
    {
        if (method_exists(\App\Models\CustodyItem::class, 'hasOverdueCash')) {
            return (bool) \App\Models\CustodyItem::hasOverdueCash($e->id);
        }
        $max = (int) Settings::get('custodySettleMaxDays', $e->company_id, 30);

        return \App\Models\CustodyItem::where('employee_id', $e->id)->whereIn('status', ['in_use', 'return_requested'])
            ->whereHas('type', fn ($q) => $q->where('is_cash', true))->where('updated_at', '<', now()->subDays($max))->exists();
    }

    // ---------------------------------------------------------------- tickets §53–54
    private static function ticket(array $d, Employee $e): array
    {
        $cap = (float) ($e->benefit('ticketCap') ?? 0);
        $family = (bool) ($e->benefit('ticketFamily') ?? false);
        $saudi = self::isSaudi($e);
        if ($cap <= 0 && $saudi) {
            self::fail('travel_date', 'لا تذكرة إجازة للموظف السعودي في فئة شركتك (§52) — تذاكر غير السعودي في الفئة الأولى وفق النظام والعقد');
        }
        $min = (int) Settings::get('ticketMinMonths', $e->company_id, 10);
        if (! $e->hire_date || $e->hire_date->copy()->addMonths($min)->gt(Carbon::parse($d['travel_date']))) {
            self::fail('travel_date', "يُشترط إكمال {$min} أشهر من الخدمة لاستحقاق تذكرة الإجازة (§54)");
        }
        [$ys, $ye] = self::benefitsYear(Carbon::parse($d['travel_date']));
        $taken = ApprovalRequest::where('employee_id', $e->id)->where('type', 'ticket')->whereIn('status', ['pending', 'approved'])
            ->where('data->travel_date', '>=', $ys->toDateString())->where('data->travel_date', '<=', $ye->toDateString())->count();
        $carry = (int) (($e->custom_benefits['ticketCarryYear'] ?? 0) === $ys->year);
        if ($taken >= 1 + $carry) {
            self::fail('travel_date', 'تُمنح تذكرة الإجازة مرة واحدة في سنة المزايا (1 فبراير – 31 يناير) §54');
        }
        $leave = ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])->where('data->type', 'annual')
            ->where('data->from', '<=', Carbon::parse($d['travel_date'])->addDays(3)->toDateString())->where('data->to', '>=', Carbon::parse($d['travel_date'])->subDays(3)->toDateString())->exists();
        if (! $leave) {
            self::fail('travel_date', 'تذكرة الإجازة تُصرف مع إجازة سنوية معتمدة — قدّم طلب الإجازة أولًا (§54)');
        }
        $companions = (int) ($d['companions'] ?? 0);
        $children = (int) ($e->benefit('ticketChildren') ?? 2);
        if ($companions > 0 && ! $family) {
            self::fail('companions', 'تذاكر الأسرة في الفئة الثالثة فقط؛ في فئة شركتك تُمنح التذكرة للموظف وحده (§54)');
        }
        if ($companions > 1 + $children) {
            self::fail('companions', 'الأسرة المستحقة: الزوج أو الزوجة و'.$children.' من الأبناء (§53)');
        }
        if (! empty($d['cash']) && ($saudi || ! $family)) {
            self::fail('cash', 'البدل المالي متاح لغير السعودي في الفئة الثالثة فقط؛ وتُصرف التذكرة حجزًا (§54)');
        }
        $approved = $cap > 0 ? min((float) $d['estimate'], $cap) : (float) $d['estimate'];

        return $d + ['cap' => $cap, 'approved_amount' => round($approved, 2), 'benefits_year' => $ys->format('Y').'/'.$ye->format('Y'), 'family' => $family];
    }

    // ---------------------------------------------------------------- §57 deferral up to 3 months
    private static function leaveDefer(array $d, Employee $e): array
    {
        $b = LeaveService::balance($e, 'annual');
        if ((float) $d['days'] <= 0 || (float) $d['days'] > (float) $b['available']) {
            self::fail('days', "الأيام المؤجلة يجب ألا تتجاوز الرصيد المتاح ({$b['available']})");
        }

        return $d + ['year' => (int) now()->year, 'until' => Carbon::create(now()->year + 1, 3, 31)->toDateString()];
    }

    // ---------------------------------------------------------------- §26 overtime
    private static function overtime(array $d, Employee $e): array
    {
        if ((float) $d['hours'] <= 0 || (float) $d['hours'] > 12) {
            self::fail('hours', 'عدد ساعات العمل الإضافي بين 0 و12 ساعة لليوم');
        }
        $rate = (float) Settings::get('overtimeRate', $e->company_id, 1.5);

        return $d + ['rate' => $rate, 'holiday' => \App\Models\Holiday::isHoliday($e, $d['date']) || ! $e->isWorkDay(Carbon::parse($d['date'])->dayOfWeek)];
    }

    // ---------------------------------------------------------------- §38 bank account
    private static function iban(array $d, Employee $e): array
    {
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $d['iban']));
        if (! preg_match('/^SA\d{22}$/', $iban)) {
            self::fail('iban', 'رقم الآيبان السعودي يبدأ بـ SA ويتبعه 22 رقمًا');
        }
        if ($iban === strtoupper((string) $e->bank_iban)) {
            self::fail('iban', 'هذا هو الحساب المسجل حاليًا');
        }

        return array_merge($d, ['iban' => $iban, 'before' => $e->bank_iban]);
    }

    // ---------------------------------------------------------------- §45 expense claim
    private static function expense(array $d, Employee $e): array
    {
        if ((float) $d['amount'] <= 0) {
            self::fail('amount', 'المبلغ غير صحيح');
        }
        if ($d['date'] > now()->toDateString()) {
            self::fail('date', 'تاريخ المصروف في المستقبل');
        }
        $limit = (int) Settings::get('expenseClaimDays', $e->company_id, 10);
        if (WorkCalendar::between($e, $d['date']) > $limit) {
            self::fail('date', "تُقدَّم المطالبة خلال {$limit} أيام عمل من تاريخ المصروف (§45)");
        }
        $dup = ApprovalRequest::where('employee_id', $e->id)->where('type', 'expense')->whereIn('status', ['pending', 'approved'])
            ->where('data->date', $d['date'])->where('data->amount', (float) $d['amount'])->exists();
        if ($dup) {
            self::fail('amount', 'مطالبة بالمبلغ والتاريخ نفسيهما مقدَّمة مسبقًا — لا تجزئة ولا تكرار (§45)');
        }

        return $d;
    }

    // ---------------------------------------------------------------- §20–21 probation
    public static function probationEnd(Employee $e): ?Carbon
    {
        if (! $e->hire_date) {
            return null;
        }
        $days = (int) ($e->probation_days ?: Settings::get('probationDays', $e->company_id, 180));
        // §20: Eid holidays and sick leave are not counted in the probation period
        $sick = (int) ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')->where('data->type', 'sick')
            ->where('data->from', '<=', $e->hire_date->copy()->addDays($days + 60)->toDateString())->get(['data'])->sum(fn ($r) => $r->data['days'] ?? 0);
        $eid = \App\Models\Holiday::where('kind', 'eid')->whereBetween('from', [$e->hire_date->toDateString(), $e->hire_date->copy()->addDays($days + 30)->toDateString()])
            ->get()->sum(fn ($h) => $h->from->diffInDays($h->to) + 1);

        return $e->hire_date->copy()->addDays($days + $sick + $eid);
    }

    private static function probation(array $d, Employee $e): array
    {
        if ($e->probation_status === 'confirmed') {
            self::fail('decision', 'الموظف مثبَّت مسبقًا');
        }
        $end = self::probationEnd($e);
        $months = \App\Models\Evaluation::where('employee_id', $e->id)->where('type', 'monthly')
            ->whereBetween('period', [$e->hire_date->format('Y-m'), ($end ?? now())->format('Y-m')])->get(['score', 'details']);
        $avg = $months->count() ? round($months->avg('score'), 2) : null;
        $red = $months->contains(fn ($m) => ($m->details['flag']['color'] ?? null) === 'red');

        // §21: confirmation is recommended at an average of 70 or more without an open red flag
        return $d + ['probation_end' => $end?->toDateString(), 'avg_score' => $avg, 'months' => $months->count(), 'red_flag' => $red,
            'recommendation' => $avg !== null && $avg >= 70 && ! $red ? 'يوصى بالتثبيت' : 'لا تتحقق شروط التوصية بالتثبيت (متوسط 70 فأكثر دون علامة حمراء)'];
    }

    private static function probationShorten(array $d, Employee $e): array
    {
        if (empty($d['consent'])) {
            self::fail('consent', 'تقصير التجربة يتطلب موافقة الموظف الكتابية (§20)');
        }
        if ((int) $d['days'] < 1 || (int) $d['days'] >= 180) {
            self::fail('days', 'المدة الجديدة أقل من 180 يومًا');
        }

        return $d;
    }

    // ---------------------------------------------------------------- §22 / §84 promotion and acting
    private static function promotion(array $d, Employee $e): array
    {
        if ($d['kind'] === 'ترقية') {
            $blockers = [];
            if (\App\Models\ImprovementPlan::where('employee_id', $e->id)->whereIn('status', ['active', 'extended', 'hr_reviewed', 'draft'])->exists()) {
                $blockers[] = 'في خطة تحسين أداء';
            }
            if (EvaluationService::hasActivePenalty($e) || (method_exists(\App\Models\Violation::class, 'activeDeprivation') && \App\Models\Violation::activeDeprivation($e->id))) {
                $blockers[] = 'عليه جزاء نافذ';
            }
            $annual = null;
            try {
                $annual = EvaluationService::annual($e, (int) now()->year - 1) ?? EvaluationService::annual($e, (int) now()->year);
            } catch (\Throwable) {
            }
            $grade = $annual['grade']['grade'] ?? null;
            if ($grade && ! in_array($grade, ['A+', 'A', 'B+', 'B'], true)) {
                $blockers[] = 'التقييم السنوي أقل من B';
            }
            if ($blockers) {
                self::fail('kind', 'شروط الترشيح للترقية غير مستوفاة (§84): '.implode('، ', $blockers));
            }

            return $d + ['annual_grade' => $grade];
        }
        // acting: an allowance of 15% of the role's basic wage from the day after the third month, for up to six months (§22)
        if (empty($d['role_basic']) || (float) $d['role_basic'] <= 0) {
            self::fail('role_basic', 'أدخل الأجر الأساسي المعتمد للوظيفة المكلف بها لحساب بدل التكليف');
        }
        $start = Carbon::parse($d['effective']);
        $pct = (float) Settings::get('actingAllowancePct', $e->company_id, 15);

        return $d + ['acting_allowance' => round((float) $d['role_basic'] * $pct / 100, 2), 'acting_pct' => $pct,
            'allowance_from' => $start->copy()->addMonthsNoOverflow(3)->toDateString(), 'allowance_to' => $start->copy()->addMonthsNoOverflow(9)->subDay()->toDateString()];
    }

    // ---------------------------------------------------------------- §47–50 company classification
    private static function tierChange(array $d, Employee $e): array
    {
        $u = auth()->user();
        $cid = (int) $d['company'];
        if (! $u->hasRole('super', 'hr_mgr') && ! \App\Models\Company::where('id', $cid)->where('ceo_employee_id', $u->employee_id)->exists()) {
            self::fail('company', 'يُعد طلب التصنيف الرئيس التنفيذي للشركة مع الموارد البشرية (الملحق الخامس)');
        }
        $pct = (float) $d['achievement'];
        // §47: below 100% of the scorecard → تأسيس · 100% to below 110% → تمكين · 110% and above → أثر
        $earned = $pct >= 110 ? 'أثر' : ($pct >= 100 ? 'تمكين' : 'تأسيس');
        $rank = ['تأسيس' => 1, 'تمكين' => 2, 'أثر' => 3];
        if ($rank[$d['tier']] > $rank[$earned] && empty($d['board_exception'])) {
            self::fail('tier', "نتيجة البطاقة ({$pct}%) تستحق فئة «{$earned}»؛ التصنيف الأعلى يتطلب قرارًا مسبَّبًا من مجلس الإدارة (§48)");
        }
        $eff = \Illuminate\Support\Carbon::parse($d['effective']);
        if ($eff->format('m-d') !== '02-01') {
            self::fail('effective', 'يسري التصنيف على سنة المزايا التالية — تبدأ في 1 فبراير (§49)');
        }
        $cur = \App\Models\Company::with('tier')->find($cid)?->tier?->name;

        return $d + ['earned' => $earned, 'current' => $cur, 'company_name' => \App\Models\Company::find($cid)?->name];
    }

    // ---------------------------------------------------------------- §39 car vs transport
    private static function allowance(array $d, Employee $e): array
    {
        $key = $d['component'] ?? '';
        if ($key === 'car' && $e->componentAmount('transport') > 0 && (float) $d['after'] > 0) {
            self::fail('component', 'بدل استخدام السيارة الخاصة لا يُجمع مع بدل المواصلات (§39)');
        }
        if ($key === 'transport' && $e->componentAmount('car') > 0 && (float) $d['after'] > 0) {
            self::fail('component', 'بدل المواصلات لا يُجمع مع بدل استخدام السيارة الخاصة (§39)');
        }

        return $d;
    }

    // ---------------------------------------------------------------- §59–61 special leaves
    public static function specialLeave(LeaveType $lt, array $v, int $days, Employee $e): array
    {
        $out = [];
        $from = Carbon::parse($v['from']);
        $event = ! empty($v['event_date']) ? Carbon::parse($v['event_date']) : null;
        $female = in_array(strtolower((string) $e->gender), ['f', 'female', 'أنثى'], true);
        switch ($lt->code) {
            case 'sick': // 30 full + 60 at 75% + 30 unpaid within one year from the first sick leave
                $winStart = ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])->where('data->type', 'sick')
                    ->where('data->from', '>=', $from->copy()->subYear()->toDateString())->get(['data'])->min(fn ($r) => $r->data['from'] ?? null);
                $startFrom = $winStart && $winStart < $v['from'] ? $winStart : $v['from'];
                $used = (float) ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])->where('data->type', 'sick')
                    ->where('data->from', '>=', $startFrom)->get(['data'])->sum(fn ($r) => $r->data['days'] ?? 0);
                if ($used + $days > 120) {
                    self::fail('to', 'تجاوزت المدة النظامية للإجازة المرضية في السنة (30 بأجر كامل + 60 بثلاثة أرباع الأجر + 30 دون أجر) — المتبقي '.max(0, 120 - $used).' يوم');
                }
                $full = max(0, min($days, 30 - $used));
                $tq = max(0, min($days - $full, 90 - max($used, 30)));
                $out['sick_pay'] = ['full' => $full, 'three_quarters' => $tq, 'unpaid' => $days - $full - $tq, 'window_from' => $startFrom, 'used_before' => $used];
                break;
            case 'hajj':
                if (! $e->hire_date || $e->hire_date->copy()->addYears(2)->gt($from)) {
                    self::fail('from', 'إجازة الحج بعد سنتين متصلتين من الخدمة على الأقل (§59)');
                }
                if (ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')->where('data->type', 'hajj')->exists()) {
                    self::fail('leave_type', 'إجازة الحج مرة واحدة خلال الخدمة (§59)');
                }
                $span = Carbon::parse($v['from'])->diffInDays(Carbon::parse($v['to'])) + 1;
                if ($span < 10 || $span > 15) {
                    self::fail('to', 'إجازة الحج من 10 إلى 15 يومًا تشمل إجازة عيد الأضحى (§59)');
                }
                break;
            case 'newborn':
                if (! $event) self::fail('event_date', 'أدخل تاريخ الولادة');
                if ($from->lt($event) || $from->gt($event->copy()->addDays(6))) {
                    self::fail('from', 'إجازة المولود خلال 7 أيام من تاريخ الولادة (§59)');
                }
                break;
            case 'marriage':
            case 'bereavement':
            case 'sibling':
                if (! $event) self::fail('event_date', 'أدخل تاريخ الواقعة — تُحسب الإجازة منه (§59)');
                if ($from->lt($event) || $from->gt($event->copy()->addDays(3))) {
                    self::fail('from', 'تبدأ الإجازة من تاريخ الواقعة (§59)');
                }
                break;
            case 'iddah':
                if (! $female) self::fail('leave_type', 'إجازة العدة للموظفة');
                $max = $e->religion === 'muslim' || ! $e->religion ? 130 : 15; // 4 months and 10 days, or 15 days for a non-Muslim employee
                if (Carbon::parse($v['from'])->diffInDays(Carbon::parse($v['to'])) + 1 > $max) {
                    self::fail('to', $max === 130 ? 'عدة الوفاة 4 أشهر و10 أيام بأجر كامل (§59)' : 'إجازة وفاة الزوج للموظفة غير المسلمة 15 يومًا (§59)');
                }
                break;
            case 'maternity':
                if (! $female) self::fail('leave_type', 'إجازة الوضع للموظفة');
                break;
            case 'maternity_ext':
            case 'maternity_care':
                if (! $female) self::fail('leave_type', 'هذا النوع للموظفة');
                $prev = ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->where('status', 'approved')->whereIn('data->type', ['maternity', 'maternity_ext', 'maternity_care'])
                    ->where('data->to', '>=', $from->copy()->subDays(2)->toDateString())->where('data->to', '<', $v['from'])->exists();
                if (! $prev) self::fail('from', 'يبدأ التمديد بعد انتهاء إجازة الوضع مباشرة (§60)');
                break;
            case 'exam':
                $n = (int) Settings::get('examNoticeDays', $e->company_id, 15);
                if ($from->lt(now()->startOfDay()->addDays($n))) {
                    self::fail('from', "تُقدَّم إجازة الامتحان قبل {$n} يومًا على الأقل (§61)");
                }
                break;
            case 'unpaid':
                $max = (float) ($e->benefit('unpaidMax') ?? 30);
                $year = substr($v['from'], 0, 4);
                $taken = (float) ApprovalRequest::where('employee_id', $e->id)->where('type', 'leave')->whereIn('status', ['approved', 'pending'])->where('data->type', 'unpaid')
                    ->where('data->from', 'like', $year.'%')->get(['data'])->sum(fn ($r) => $r->data['days'] ?? 0);
                if ($taken + $days > $max) {
                    $out['needs_super'] = 'الرئيس التنفيذي للمجموعة (استثناء: تجاوز '.(int) $max.' يومًا دون أجر في السنة)';
                }
                if ($taken + $days > 20) {
                    $out['suspends_contract'] = true; // §59: the contract is suspended beyond 20 days unless agreed otherwise
                }
                break;
        }

        return $out;
    }
}
