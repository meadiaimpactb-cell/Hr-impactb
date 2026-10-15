<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\JobOpening;
use App\Models\Lookup;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Recruitment: openings per company, applications through data-defined stages, convert accepted candidates to employees. */
class RecruitmentController extends Controller
{
    private function hr(Request $r, ?int $companyId = null): void
    {
        abort_unless($r->user()->isHr() && ($companyId ? Access::for()->can('EMP', 'add', ['company_id' => $companyId]) : Access::for()->canAny('EMP', 'add')), 403);
    }

    public function openings(Request $request)
    {
        $this->hr($request);
        $cids = Access::for()->companyIds();

        return JobOpening::with('company:id,name', 'jobTitle:id,name')->withCount('applications')->whereIn('company_id', $cids)->orderByDesc('id')->get()
            ->map(fn ($o) => $o->toArray() + ['company_name' => $o->company?->name, 'job' => $o->jobTitle?->name]);
    }

    public function saveOpening(Request $request, ?JobOpening $opening = null)
    {
        $d = $request->validate(['company_id' => 'required|exists:companies,id', 'job_title_id' => 'nullable|exists:job_titles,id', 'title' => 'required|string|max:160', 'description' => 'nullable|string|max:5000',
            'headcount' => 'integer|min:1|max:99', 'status' => 'in:open,closed', 'public' => 'boolean']);
        $this->hr($request, (int) $d['company_id']);
        $opening ? $opening->update($d) : $opening = JobOpening::create($d + ['created_by' => $request->user()->id]);
        Audit::log('job_openings', $opening->id, 'save', null, $d);

        return $opening->load('company:id,name', 'jobTitle:id,name');
    }

    /** Public career page: open & public openings (no auth). */
    public function publicOpenings()
    {
        return JobOpening::with('company:id,name', 'jobTitle:id,name')->where('status', 'open')->where('public', true)->get(['id', 'title', 'description', 'company_id', 'job_title_id'])
            ->map(fn ($o) => ['id' => $o->id, 'title' => $o->title, 'description' => $o->description, 'company' => $o->company?->name, 'job' => $o->jobTitle?->name]);
    }

    public function publicApply(Request $request, JobOpening $opening)
    {
        abort_unless($opening->status === 'open' && $opening->public, 404);
        $d = $request->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:2000']);
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
            'email' => 'nullable|email|max:150', 'phone' => 'nullable|string|max:30', 'stage' => ['nullable', Rule::in($stages)], 'rating' => 'nullable|integer|min:1|max:5', 'notes' => 'nullable|string|max:5000']);
        $this->hr($request, (int) $d['company_id']);
        if ($request->hasFile('cv')) {
            $d['cv_path'] = $request->file('cv')->store('cv/'.now()->format('Y'), 'local');
        }
        if ($application) {
            $hist = $application->history ?? [];
            if (! empty($d['stage']) && $d['stage'] !== $application->stage) {
                $hist[] = ['stage' => $d['stage'], 'at' => now()->toDateTimeString(), 'by' => $request->user()->name];
            }
            $application->update($d + ['history' => $hist]);
        } else {
            $application = Application::create($d + ['stage' => $d['stage'] ?? 'new', 'history' => [['stage' => $d['stage'] ?? 'new', 'at' => now()->toDateTimeString(), 'by' => $request->user()->name]]]);
        }
        Audit::log('applications', $application->id, 'save', null, $d);

        return $this->row($application->fresh()->load('company:id,name', 'jobTitle:id,name', 'opening:id,title'));
    }

    /** Pre-filled employee payload for an accepted candidate (the UI opens the employee form with it). */
    public function convert(Request $request, Application $application)
    {
        $this->hr($request, $application->company_id);
        abort_unless(collect(Lookup::grouped()['recruit_stages'])->firstWhere('code', $application->stage)['meta']['terminal'] ?? false, 422, 'يُحوَّل المتقدم بعد الوصول لمرحلة نهائية (مقبول)');
        abort_if($application->employee_id, 422, 'حُوّل مسبقًا');

        return ['prefill' => ['name' => $application->name, 'email' => $application->email, 'phone' => $application->phone, 'company_id' => $application->company_id, 'job_title_id' => $application->job_title_id, 'hire_date' => now()->toDateString(), 'application_id' => $application->id]];
    }

    public function linkEmployee(Request $request, Application $application)
    {
        $this->hr($request, $application->company_id);
        $d = $request->validate(['employee_id' => 'required|exists:employees,id']);
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
        return $a->only('id', 'opening_id', 'company_id', 'job_title_id', 'name', 'email', 'phone', 'stage', 'rating', 'notes', 'history', 'employee_id', 'source', 'created_at') + [
            'stage_name' => Lookup::name('recruit_stages', $a->stage), 'company' => $a->company?->name, 'job' => $a->jobTitle?->name, 'opening' => $a->opening?->title, 'cv' => $a->cv_path];
    }
}
