<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Obstacle;
use App\Models\Project;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Quarterly projects: 12 weekly plan slots with % progress, members, obstacles with owners and decisions. */
class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $u = $request->user();
        $acc = Access::for();
        $q = Project::with('company:id,name', 'owner:id,name')->withCount(['obstacles as open_obstacles' => fn ($q) => $q->where('status', 'open')]);
        if ($acc->canAny('PRF', 'view')) {
            $q->whereIn('company_id', $acc->companyIds());
        } else {
            $q->where(fn ($w) => $w->where('owner_employee_id', $u->employee_id)->orWhereJsonContains('members', $u->employee_id));
        }

        return $q->when($request->input('quarter'), fn ($q, $qt) => $q->where('quarter', $qt))->orderByDesc('id')->get()->map(fn ($p) => $this->row($p));
    }

    public function show(Request $request, Project $project)
    {
        $this->canView($request, $project);
        $project->load('company:id,name', 'owner:id,name', 'obstacles.owner:id,name');
        $members = Employee::whereIn('id', $project->members ?? [])->get(['id', 'name', 'emp_no']);

        return $this->row($project) + ['weeks' => $project->weeks, 'members_list' => $members, 'goal' => $project->goal,
            'obstacles' => $project->obstacles->map(fn ($o) => $o->only('id', 'title', 'severity', 'due_date', 'status', 'decision', 'created_at') + ['owner' => $o->owner?->name, 'owner_employee_id' => $o->owner_employee_id]),
            'can_edit' => $this->canEdit($request, $project)];
    }

    public function store(Request $request, ?Project $project = null)
    {
        $d = $request->validate(['name' => 'required|string|max:160', 'goal' => 'nullable|string|max:3000', 'company_id' => 'required|exists:companies,id', 'owner_employee_id' => 'nullable|exists:employees,id',
            'quarter' => ['required', 'regex:/^\d{4}-Q[1-4]$/'], 'members' => 'array', 'members.*' => 'integer', 'status' => 'in:active,done,archived']);
        abort_unless(Access::for()->can('PRF', 'edit', ['company_id' => $d['company_id']]) || ($project && $this->canEdit($request, $project)), 403);
        if (! $project) {
            [$y, $qn] = explode('-Q', $d['quarter']);
            $start = Carbon::create((int) $y, ((int) $qn - 1) * 3 + 1, 1)->startOfWeek(Carbon::SUNDAY);
            $d['weeks'] = array_map(fn ($i) => ['n' => $i + 1, 'start' => $start->copy()->addWeeks($i)->toDateString(), 'plan' => '', 'pct' => 0, 'status' => 'planned', 'note' => ''], range(0, 11));
        }
        $project ? $project->update($d) : $project = Project::create($d);
        Audit::log('projects', $project->id, 'save', null, $d);
        foreach (User::whereIn('employee_id', $d['members'] ?? [])->pluck('id') as $uid) {
            Audit::notify($uid, 'أُضفت إلى مشروع', $project->name.' — '.$project->quarter, 'info', ['screen' => 'projects']);
        }

        return $this->show($request, $project->fresh());
    }

    public function week(Request $request, Project $project)
    {
        abort_unless($this->canEdit($request, $project), 403);
        $d = $request->validate(['n' => 'required|integer|min:1|max:12', 'plan' => 'nullable|string|max:1000', 'pct' => 'nullable|integer|min:0|max:100', 'status' => 'nullable|in:planned,progress,done,late', 'note' => 'nullable|string|max:1000']);
        $weeks = $project->weeks;
        $i = $d['n'] - 1;
        $weeks[$i] = array_merge($weeks[$i], array_filter($d, fn ($v, $k) => $k !== 'n' && $v !== null, ARRAY_FILTER_USE_BOTH));
        if (($weeks[$i]['pct'] ?? 0) >= 100) {
            $weeks[$i]['status'] = 'done';
        } elseif (($weeks[$i]['status'] ?? '') !== 'done' && Carbon::parse($weeks[$i]['start'])->addDays(6)->isPast() && ($weeks[$i]['pct'] ?? 0) < 100) {
            $weeks[$i]['status'] = 'late';
        }
        $project->update(['weeks' => $weeks]);

        return $this->show($request, $project->fresh());
    }

    public function obstacle(Request $request, Project $project, ?Obstacle $obstacle = null)
    {
        abort_unless($this->canEdit($request, $project), 403);
        $d = $request->validate(['title' => ($obstacle ? 'nullable' : 'required').'|string|max:200', 'owner_employee_id' => 'nullable|exists:employees,id', 'severity' => 'in:high,med,low', 'due_date' => 'nullable|date',
            'status' => 'nullable|in:open,closed', 'decision' => 'nullable|string|max:2000']);
        if ($obstacle) {
            if (($d['status'] ?? null) === 'closed') {
                abort_unless(! empty($d['decision']) || $obstacle->decision, 422, 'القرار إلزامي عند الإغلاق');
                $d['decided_by'] = $request->user()->id;
            }
            $obstacle->update($d);
        } else {
            $obstacle = $project->obstacles()->create($d + ['status' => 'open']);
            if ($obstacle->owner_employee_id) {
                Audit::notify(User::where('employee_id', $obstacle->owner_employee_id)->value('id'), 'عقبة مُسندة إليك', $obstacle->title.' — '.$project->name, 'warn', ['screen' => 'projects']);
            }
        }

        return $this->show($request, $project->fresh());
    }

    private function canView(Request $r, Project $p): void
    {
        $u = $r->user();
        abort_unless(Access::for()->can('PRF', 'view', ['company_id' => $p->company_id]) || $p->owner_employee_id === $u->employee_id || in_array($u->employee_id, $p->members ?? [], true), 403);
    }

    private function canEdit(Request $r, Project $p): bool
    {
        $u = $r->user();

        return Access::for()->can('PRF', 'edit', ['company_id' => $p->company_id]) || $p->owner_employee_id === $u->employee_id || in_array($u->employee_id, $p->members ?? [], true);
    }

    private function row(Project $p): array
    {
        $weeks = collect($p->weeks);

        return $p->only('id', 'name', 'company_id', 'owner_employee_id', 'quarter', 'members', 'status') + ['company' => $p->company?->name, 'owner' => $p->owner?->name,
            'progress' => round($weeks->avg('pct') ?? 0), 'done_weeks' => $weeks->where('status', 'done')->count(), 'late_weeks' => $weeks->where('status', 'late')->count(),
            'open_obstacles' => $p->open_obstacles ?? $p->obstacles()->where('status', 'open')->count()];
    }
}
