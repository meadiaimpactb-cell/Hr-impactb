<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JobOpening;
use App\Models\Lookup;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Recruitment: openings per company, applications through data-defined stages, convert accepted candidates to employees.
 *
 * Policy §14–17 and appendix 5:
 *  - a vacancy is opened through an approval chain: the direct manager (or HR) prepares it, HR (m1), finance (m2), the
 *    company CEO gives the final approval for an in-plan vacancy; an out-of-plan vacancy needs the group CEO as well.
 *    Nobody approves their own vacancy: a stage held by the preparer is skipped and recorded as such.
 *  - acceptance criteria are required before the vacancy is submitted or published (§15).
 *  - candidates disclose any relationship, interviewers declare conflicts of interest; whoever declared a conflict does
 *    not take part in the final decision (§16).
 *  - the offer states title, company, location, wage and allowances, work pattern, start conditions and probation (§17).
 *  - at hire the employee file must carry manager, job title, schedule, ID/passport and IBAN (§17).
 */
class RecruitmentController extends Controller
{
    /** Approval chain stages in order; 'group' only applies to an out-of-plan vacancy. */
    public const STAGES = ['draft' => 'مسودة لدى المُعِدّ', 'hr' => 'بانتظار الموارد البشرية', 'fin' => 'بانتظار المالية', 'ceo' => 'بانتظار الرئيس التنفيذي للشركة',
        'group' => 'بانتظار الرئيس التنفيذي للمجموعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض'];

    private function hr(Request $r, ?int $companyId = null): void
    {
        abort_unless($r->user()->isHr() && ($companyId ? Access::for()->can('EMP', 'add', ['company_id' => $companyId]) : Access::for()->canAny('EMP', 'add')), 403);
    }

    private function isHrFor(User $u, int $companyId): bool
    {
        return $u->isHr() && Access::for($u)->can('EMP', 'add', ['company_id' => $companyId]);
    }

    /** A manager (has direct reports or the mgr role) may prepare a vacancy for their own company. */
    private function isManagerFor(User $u, int $companyId): bool
    {
        $e = $u->employee;

        return $e && (int) $e->company_id === $companyId && ($u->hasRole('mgr') || Employee::where('manager_id', $e->id)->exists());
    }

    private function companyHasCeo(int $companyId): bool
    {
        return Company::whereKey($companyId)->whereNotNull('ceo_employee_id')->exists()
            || User::where('active', true)->where('company_id', $companyId)->get()->contains(fn ($u) => $u->hasRole('ceo'));
    }

    /** Who may act at a stage of the chain. */
    private function canAct(User $u, JobOpening $o, string $stage): bool
    {
        return match ($stage) {
            'draft' => $o->created_by === $u->id || $this->isHrFor($u, $o->company_id),
            'hr' => $this->isHrFor($u, $o->company_id),
            'fin' => $u->hasRole('fin'),
            'ceo' => Access::for($u)->isCeoOf($o->company_id) || ($u->hasRole('super') && ! $this->companyHasCeo($o->company_id)),
            'group' => $u->hasRole('super'),
            default => false,
        };
    }

    private function nextStage(JobOpening $o, string $stage): string
    {
        return match ($stage) {
            'draft' => 'hr', 'hr' => 'fin', 'fin' => 'ceo', 'ceo' => $o->in_plan ? 'approved' : 'group', 'group' => 'approved', default => $stage,
        };
    }

    private function stageUsers(JobOpening $o, string $stage): array
    {
        return match ($stage) {
            'hr' => Access::hrUsersFor($o->company_id, 'EMP'),
            'fin' => Access::usersWithRole('fin'),
            'ceo' => array_values(array_filter([User::where('employee_id', Company::whereKey($o->company_id)->value('ceo_employee_id'))->value('id'),
                ...User::where('active', true)->where('company_id', $o->company_id)->get()->filter(fn ($u) => $u->hasRole('ceo'))->pluck('id')->all()])),
            'group' => Access::usersWithRole('super'),
            default => [],
        };
    }

    private function canSeeOpening(User $u, JobOpening $o): bool
    {
        return $o->created_by === $u->id || $this->isHrFor($u, $o->company_id) || $u->hasRole('super', 'fin') || Access::for($u)->isCeoOf($o->company_id);
    }

    public function openings(Request $request)
    {
        $u = $request->user();
        $hrView = $u->isHr() && Access::for()->canAny('EMP', 'add');
        abort_unless($hrView || $u->hasRole('super', 'fin', 'ceo', 'mgr') || ($u->employee_id && Employee::where('manager_id', $u->employee_id)->exists()), 403);

        return JobOpening::with('company:id,name', 'jobTitle:id,name')->withCount('applications')->orderByDesc('id')->get()
            ->filter(fn ($o) => $this->canSeeOpening($u, $o))->values()
            ->map(fn ($o) => $this->openingRow($o, $u));
    }

    private function openingRow(JobOpening $o, User $u): array
    {
        return $o->toArray() + ['company_name' => $o->company?->name, 'job' => $o->jobTitle?->name, 'stage_label' => self::STAGES[$o->stage] ?? $o->stage,
            'can_act' => $this->canAct($u, $o, $o->stage), 'can_edit' => $o->stage === 'draft' ? $this->canAct($u, $o, 'draft') : $this->isHrFor($u, $o->company_id),
            'chain' => $o->in_plan ? ['hr', 'fin', 'ceo'] : ['hr', 'fin', 'ceo', 'group']];
    }

    public function saveOpening(Request $request, ?JobOpening $opening = null)
    {
        $u = $request->user();
        $d = $request->validate(['company_id' => 'required|exists:companies,id', 'job_title_id' => 'nullable|exists:job_titles,id', 'title' => 'required|string|max:160', 'description' => 'nullable|string|max:5000',
            'criteria' => 'nullable|string|max:5000', 'work_location' => 'nullable|string|max:160', 'in_plan' => 'boolean',
            'headcount' => 'integer|min:1|max:99', 'status' => 'in:open,closed', 'public' => 'boolean']);
        $cid = (int) $d['company_id'];
        abort_unless($this->isHrFor($u, $cid) || $this->isManagerFor($u, $cid), 403, 'إعداد الشاغر للمشرف المباشر أو الموارد البشرية');
        if ($opening) {
            abort_unless($opening->stage === 'draft' ? $this->canAct($u, $opening, 'draft') : $this->isHrFor($u, $opening->company_id), 403);
            if ($opening->stage !== 'draft') {
                // after submission the approved content is locked; HR only publishes / closes and edits the text
                unset($d['in_plan'], $d['headcount'], $d['company_id']);
            }
        }
        $stage = $opening?->stage ?? 'draft';
        if (($d['status'] ?? null) === 'open') {
            abort_unless($stage === 'approved', 422, 'لا يُنشر الشاغر قبل اعتماده وفق مصفوفة الصلاحيات ('.(self::STAGES[$stage] ?? $stage).')');
            abort_if(blank($d['criteria'] ?? $opening?->criteria), 422, 'حدد معايير القبول قبل نشر الشاغر (المادة 15)');
        }
        if (! $opening) {
            $d['status'] = 'closed'; // a new vacancy goes through the approval chain before it is open
        }
        $opening ? $opening->update($d) : $opening = JobOpening::create($d + ['created_by' => $u->id, 'stage' => 'draft', 'approvals' => []]);
        Audit::log('job_openings', $opening->id, 'save', null, $d);

        return $this->openingRow($opening->fresh()->load('company:id,name', 'jobTitle:id,name'), $u);
    }

    /** Appendix 5: draft → HR → finance → company CEO → (group CEO when out of plan) → approved (and open). */
    public function decideOpening(Request $request, JobOpening $opening)
    {
        $u = $request->user();
        $d = $request->validate(['decision' => 'required|in:approve,return,reject', 'reason' => 'required_unless:decision,approve|nullable|string|max:1000']);
        $stage = $opening->stage;
        abort_unless(in_array($stage, ['draft', 'hr', 'fin', 'ceo', 'group'], true), 422, 'الشاغر ليس في مرحلة اعتماد');
        abort_unless($this->canAct($u, $opening, $stage), 403, 'ليست لديك صلاحية هذه المرحلة: '.self::STAGES[$stage]);
        if ($stage === 'draft' && $d['decision'] !== 'approve') {
            abort(422, 'المسودة تُرسل للاعتماد أو تُعدَّل');
        }
        $log = $opening->approvals ?? [];
        $entry = ['stage' => $stage, 'decision' => $d['decision'], 'by' => $u->id, 'name' => $u->name, 'reason' => $d['reason'] ?? null, 'at' => now()->toDateTimeString()];
        if ($d['decision'] === 'approve') {
            if ($stage === 'draft') {
                abort_if(blank($opening->criteria), 422, 'حدد معايير القبول قبل إرسال الشاغر للاعتماد (المادة 15)');
                $entry['decision'] = 'submit';
            }
            $log[] = $entry;
            $next = $this->nextStage($opening, $stage);
            // no self-approval (§12): a stage held by the preparer is skipped and goes to the next level
            $creator = User::find($opening->created_by);
            while ($creator && in_array($next, ['hr', 'fin', 'ceo'], true) && $this->canAct($creator, $opening, $next)) {
                $log[] = ['stage' => $next, 'decision' => 'skipped', 'by' => null, 'name' => $creator->name, 'reason' => 'تُخطّيت: المُعِدّ صاحب هذه المرحلة', 'at' => now()->toDateTimeString()];
                $next = $next === 'ceo' ? 'group' : $this->nextStage($opening, $next); // the CEO's own vacancy goes up to the group CEO
            }
            $upd = ['stage' => $next, 'approvals' => $log];
            if ($next === 'approved') {
                $upd['status'] = 'open';
            }
            $opening->update($upd);
            if ($next === 'approved') {
                Audit::notify(array_values(array_unique(array_filter([$opening->created_by, ...Access::hrUsersFor($opening->company_id, 'EMP')]))), 'اعتُمد الشاغر وفُتح', $opening->title, 'ok', ['screen' => 'recruitment']);
            } else {
                Audit::notify($this->stageUsers($opening, $next), 'شاغر بانتظار اعتمادك', $opening->title.($opening->in_plan ? ' — ضمن الخطة' : ' — خارج الخطة'), 'info', ['screen' => 'recruitment']);
            }
        } else {
            $log[] = $entry;
            $opening->update(['stage' => $d['decision'] === 'return' ? 'draft' : 'rejected', 'status' => 'closed', 'approvals' => $log]);
            Audit::notify($opening->created_by, $d['decision'] === 'return' ? 'أُعيد الشاغر للاستكمال' : 'رُفض الشاغر', $opening->title.' — '.$d['reason'], $d['decision'] === 'return' ? 'warn' : 'bad', ['screen' => 'recruitment']);
        }
        Audit::log('job_openings', $opening->id, 'decide_'.$d['decision'], ['stage' => $stage], ['stage' => $opening->stage], $d['reason'] ?? null);

        return $this->openingRow($opening->fresh()->load('company:id,name', 'jobTitle:id,name'), $u);
    }

    /** Public career page: open & public openings (no auth). */
    public function publicOpenings()
    {
        return JobOpening::with('company:id,name', 'jobTitle:id,name')->where('status', 'open')->where('stage', 'approved')->where('public', true)->get(['id', 'title', 'description', 'criteria', 'company_id', 'job_title_id'])
            ->map(fn ($o) => ['id' => $o->id, 'title' => $o->title, 'description' => $o->description, 'criteria' => $o->criteria, 'company' => $o->company?->name, 'job' => $o->jobTitle?->name]);
    }

    public function publicApply(Request $request, JobOpening $opening)
    {
        abort_unless($opening->status === 'open' && $opening->stage === 'approved' && $opening->public, 404);
        $d = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:2000', 'relation' => 'nullable|string|max:1000']);
        abort_if(Application::where('opening_id', $opening->id)->where('email', $d['email'])->exists(), 422, 'سبق التقديم بهذا البريد');
        $path = $request->file('cv')?->store('cv/'.now()->format('Y'), 'local');
        $app = Application::create($d + ['opening_id' => $opening->id, 'company_id' => $opening->company_id, 'job_title_id' => $opening->job_title_id, 'stage' => 'new', 'source' => 'public', 'cv_path' => $path,
            'history' => [['stage' => 'new', 'at' => now()->toDateTimeString(), 'by' => 'public']]]);
        Audit::notify(Access::hrUsersFor($opening->company_id, 'EMP'), 'متقدم جديد', $app->name.' — '.$opening->title, 'info', ['screen' => 'recruitment']);

        return response()->json(['ok' => true, 'id' => $app->id], 201);
    }

    public function applications(Request $request)
    {
        $this->hr($request);

        return Application::with('company:id,name', 'jobTitle:id,name', 'opening:id,title')->whereIn('company_id', Access::for()->companyIds())
            ->when($request->input('stage'), fn ($q, $s) => $q->where('stage', $s))->when($request->integer('opening_id'), fn ($q, $o) => $q->where('opening_id', $o))
            ->orderByDesc('id')->limit(500)->get()->map(fn ($a) => $this->row($a));
    }

    public function saveApplication(Request $request, ?Application $application = null)
    {
        $stages = array_column(Lookup::grouped()['recruit_stages'] ?? [], 'code');
        $d = $request->validate(['opening_id' => 'nullable|exists:job_openings,id', 'company_id' => 'required|exists:companies,id', 'job_title_id' => 'nullable|exists:job_titles,id', 'name' => 'required|string|max:120',
            'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:30', 'stage' => ['nullable', Rule::in($stages)], 'rating' => 'nullable|integer|min:1|max:5', 'notes' => 'nullable|string|max:5000',
            'relation' => 'nullable|string|max:1000']);
        $this->hr($request, (int) $d['company_id']);
        if (! empty($d['opening_id'])) {
            $op = JobOpening::find($d['opening_id']);
            abort_unless($op->stage === 'approved', 422, 'لا يُقبل متقدمون على شاغر غير معتمد');
        }
        if ($request->hasFile('cv')) {
            $d['cv_path'] = $request->file('cv')->store('cv/'.now()->format('Y'), 'local');
        }
        if ($application) {
            $hist = $application->history ?? [];
            if (! empty($d['stage']) && $d['stage'] !== $application->stage) {
                // §16: whoever declared a conflict of interest takes no part in the final decision
                $terminal = collect(Lookup::grouped()['recruit_stages'] ?? [])->firstWhere('code', $d['stage'])['meta']['terminal'] ?? false;
                $mine = collect($application->interviewers ?? [])->firstWhere('user_id', $request->user()->id);
                abort_if($terminal && $mine && ! empty($mine['conflict']), 403, 'أفصحت عن تعارض مصالح مع هذا المرشح — لا تشارك في القرار النهائي (المادة 16)');
                abort_if($d['stage'] === 'accepted' && empty($application->offer), 422, 'أصدر العرض الوظيفي أولًا (المادة 17) ثم انقل المرشح إلى «مقبول»');
                $hist[] = ['stage' => $d['stage'], 'at' => now()->toDateTimeString(), 'by' => $request->user()->name];
            }
            $application->update($d + ['history' => $hist]);
        } else {
            $application = Application::create($d + ['stage' => $d['stage'] ?? 'new', 'history' => [['stage' => $d['stage'] ?? 'new', 'at' => now()->toDateTimeString(), 'by' => $request->user()->name]]]);
        }
        Audit::log('applications', $application->id, 'save', null, $d);

        return $this->row($application->fresh()->load('company:id,name', 'jobTitle:id,name', 'opening:id,title'));
    }

    /** §16: an interviewer / selection participant declares whether they have a relationship or interest with the candidate. */
    public function disclose(Request $request, Application $application)
    {
        $u = $request->user();
        abort_unless($this->isHrFor($u, $application->company_id) || $this->isManagerFor($u, $application->company_id) || Access::for()->isCeoOf($application->company_id) || $u->hasRole('super'), 403);
        $d = $request->validate(['conflict' => 'required|boolean', 'note' => 'required_if:conflict,true|nullable|string|max:1000']);
        $list = collect($application->interviewers ?? [])->reject(fn ($x) => ($x['user_id'] ?? null) === $u->id)->values()->all();
        $list[] = ['user_id' => $u->id, 'name' => $u->name, 'conflict' => (bool) $d['conflict'], 'note' => $d['note'] ?? null, 'at' => now()->toDateTimeString()];
        $application->update(['interviewers' => $list]);
        Audit::log('applications', $application->id, 'disclose', null, ['conflict' => $d['conflict']], $d['note'] ?? null);
        if ($d['conflict']) {
            Audit::notify(Access::hrManagersFor($application->company_id), 'إفصاح عن تعارض مصالح', $u->name.' — المرشح '.$application->name, 'warn', ['screen' => 'recruitment']);
        }

        return $this->row($application->fresh()->load('company:id,name', 'jobTitle:id,name', 'opening:id,title'));
    }

    /** §17: the job offer — title, employer company, location, wage and allowances, work pattern, start conditions, probation. */
    public function offer(Request $request, Application $application)
    {
        $this->hr($request, $application->company_id);
        abort_if($application->employee_id, 422, 'حُوّل المرشح إلى موظف');
        if ($application->opening_id) {
            abort_unless($application->opening?->stage === 'approved', 422, 'لا يصدر عرض على شاغر غير معتمد (المادة 14)');
        }
        $d = $request->validate(['title' => 'required|string|max:160', 'location' => 'required|string|max:160', 'basic' => 'required|numeric|min:0', 'allowances' => 'nullable|array',
            'allowances.*.name' => 'required|string|max:80', 'allowances.*.amount' => 'required|numeric|min:0', 'benefits' => 'nullable|string|max:2000',
            'work_pattern' => 'required|string|max:200', 'start_conditions' => 'required|string|max:2000', 'start_date' => 'nullable|date', 'probation_days' => 'nullable|integer|min:0|max:180']);
        $d['probation_days'] = (int) ($d['probation_days'] ?? 180);
        $d['company_id'] = $application->company_id;
        $d['company'] = $application->company?->name;
        $d['issued_by'] = $request->user()->name;
        $d['issued_at'] = now()->toDateTimeString();
        $hist = $application->history ?? [];
        if ($application->stage !== 'offer' && $application->stage !== 'accepted') {
            $hist[] = ['stage' => 'offer', 'at' => now()->toDateTimeString(), 'by' => $request->user()->name];
        }
        $application->update(['offer' => $d, 'stage' => $application->stage === 'accepted' ? 'accepted' : 'offer', 'history' => $hist]);
        Audit::log('applications', $application->id, 'offer', null, $d);

        return $this->row($application->fresh()->load('company:id,name', 'jobTitle:id,name', 'opening:id,title'));
    }

    /** Pre-filled employee payload for an accepted candidate (the UI opens the employee form with it). */
    public function convert(Request $request, Application $application)
    {
        $this->hr($request, $application->company_id);
        abort_unless($application->stage === 'accepted', 422, 'يُحوَّل المتقدم بعد الوصول لمرحلة «مقبول»');
        abort_if($application->employee_id, 422, 'حُوّل مسبقًا');
        $o = $application->offer ?? [];

        return ['prefill' => ['name' => $application->name, 'email' => $application->email, 'phone' => $application->phone, 'company_id' => $application->company_id,
            'job_title_id' => $application->job_title_id ?? $application->opening?->job_title_id, 'hire_date' => $o['start_date'] ?? now()->toDateString(), 'application_id' => $application->id,
            'probation_days' => $o['probation_days'] ?? 180],
            'offer' => $o ?: null,
            // §17: the file is complete at hire — the link step refuses an employee missing any of these
            'required' => ['manager_id' => 'المشرف المباشر', 'job_title_id' => 'المسمى الوظيفي', 'schedule' => 'جدول الدوام', 'national_id' => 'رقم الهوية أو الإقامة أو الجواز', 'bank_iban' => 'الآيبان']];
    }

    /** §17: fields that must be present on the employee at hire. */
    public static function missingAtHire(Employee $e): array
    {
        $miss = [];
        if (! $e->manager_id) $miss[] = 'المشرف المباشر';
        if (! $e->job_title_id) $miss[] = 'المسمى الوظيفي';
        if (! $e->schedule_id && empty($e->work_hours)) $miss[] = 'جدول الدوام';
        if (blank($e->national_id)) $miss[] = 'رقم الهوية أو الإقامة أو الجواز';
        if (blank($e->bank_iban)) $miss[] = 'الآيبان';

        return $miss;
    }

    public function linkEmployee(Request $request, Application $application)
    {
        $this->hr($request, $application->company_id);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id']);
        $emp = Employee::find($d['employee_id']);
        $miss = self::missingAtHire($emp);
        abort_if($miss, 422, 'أكمل ملف الموظف قبل إتمام التعيين (المادة 17): '.implode('، ', $miss));
        $application->update(['employee_id' => $d['employee_id'], 'stage' => 'accepted', 'history' => [...($application->history ?? []), ['stage' => 'hired', 'at' => now()->toDateTimeString(), 'by' => $request->user()->name]]]);
        if ($application->opening && Application::where('opening_id', $application->opening_id)->whereNotNull('employee_id')->count() >= $application->opening->headcount) {
            $application->opening->update(['status' => 'closed']);
        }

        return $this->row($application->fresh()->load('company:id,name', 'jobTitle:id,name', 'opening:id,title'));
    }

    public function stages()
    {
        return Lookup::grouped()['recruit_stages'] ?? [];
    }

    private function row(Application $a): array
    {
        $me = auth()->id();

        return $a->only('id', 'opening_id', 'company_id', 'job_title_id', 'name', 'email', 'phone', 'stage', 'rating', 'notes', 'relation', 'interviewers', 'offer', 'history', 'employee_id', 'source', 'created_at') + [
            'stage_name' => Lookup::name('recruit_stages', $a->stage), 'company' => $a->company?->name, 'job' => $a->jobTitle?->name, 'opening' => $a->opening?->title, 'cv' => $a->cv_path,
            'conflicts' => collect($a->interviewers ?? [])->where('conflict', true)->count(), 'my_disclosure' => collect($a->interviewers ?? [])->firstWhere('user_id', $me)];
    }
}
