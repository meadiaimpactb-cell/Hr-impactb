<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\Employee;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Announcements with targeted audiences, read/ack receipts, reminders, reactions. */
class AnnouncementController extends Controller
{
    /** Resolve an audience definition to user ids. */
    public static function audienceUsers(array $a): array
    {
        $q = Employee::query()->where('status', '!=', 'terminated')->whereHas('user', fn ($u) => $u->where('active', true));
        match ($a['scope'] ?? 'group') {
            'company' => $q->whereIn('company_id', array_map('intval', $a['companies'] ?? [])),
            'job' => $q->whereIn('job_title_id', array_map('intval', $a['jobs'] ?? [])),
            'contract' => $q->where('contract_type', $a['contract'] ?? 'full'),
            'people' => $q->whereIn('id', array_map('intval', $a['employee_ids'] ?? [])),
            default => null,
        };

        return User::whereIn('employee_id', $q->pluck('id'))->where('active', true)->pluck('id')->all();
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $mine = AnnouncementReceipt::where('user_id', $u->id)->pluck('announcement_id');
        $rows = Announcement::with('sender:id,name')->where(fn ($q) => $q->whereIn('id', $mine)->orWhere('sender_id', $u->id))->orderByDesc('published_at')->limit(100)->get();
        $receipts = AnnouncementReceipt::where('user_id', $u->id)->whereIn('announcement_id', $rows->pluck('id'))->get()->keyBy('announcement_id');
        $counts = $this->counts($rows->pluck('id')->all());

        return $rows->map(fn ($x) => $this->row($x, $receipts[$x->id] ?? null, $counts[$x->id] ?? null, $u));
    }

    /** Announcements requiring acknowledgement that this user has not acknowledged yet (popup on login). */
    public function pendingAck(Request $request)
    {
        $u = $request->user();
        $ids = AnnouncementReceipt::where('user_id', $u->id)->whereNull('ack_at')->pluck('announcement_id');

        return Announcement::with('sender:id,name')->whereIn('id', $ids)->where('require_ack', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now()->toDateString()))
            ->orderBy('published_at')->get()->map(fn ($x) => $this->row($x, null, null, $u));
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $acc = Access::for();
        $d = $request->validate(['title' => 'required|string|max:200', 'body' => 'required|string|max:10000', 'require_ack' => 'boolean', 'expires_at' => 'nullable|date|after:today',
            'audience' => 'required|array', 'audience.scope' => ['required', Rule::in(['group', 'company', 'job', 'contract', 'people'])], 'audience.companies' => 'array', 'audience.jobs' => 'array',
            'audience.contract' => 'nullable|string', 'audience.employee_ids' => 'array']);
        $a = $d['audience'];
        // §115: group CEO and group-wide HR publish to anyone; the company CEO (and the publishers he designates) and
        // company-scoped HR publish within their companies only. Direct managers no longer publish unless designated.
        $scope = self::publishScope($u);
        abort_unless($scope !== null, 403, 'نشر الإعلانات للرئيس التنفيذي للمجموعة والموارد البشرية، وللرئيس التنفيذي للشركة أو من يحددهم من موظفيها (§115)');
        if ($scope !== 'all') {
            abort_if($a['scope'] === 'group', 403, 'إعلان المجموعة للرئيس التنفيذي للمجموعة والموارد البشرية للمجموعة');
            if ($a['scope'] === 'company') {
                $a['companies'] = array_values(array_intersect(array_map('intval', $a['companies'] ?? []), $scope));
                abort_unless($a['companies'], 403, 'لا تملك النشر لهذه الشركات');
            }
        }
        $users = array_values(array_diff(self::audienceUsers($a), []));
        if ($scope !== 'all') { // job / contract / people audiences are narrowed to the publisher's companies too
            $allowed = User::whereIn('employee_id', Employee::whereIn('company_id', $scope ?: [0])->pluck('id'))->pluck('id')->all();
            $users = array_values(array_intersect($users, $allowed));
        }
        abort_unless($users, 422, 'لا مستلمين لهذا الجمهور');
        $path = $request->file('attachment')?->store('announcements/'.now()->format('Y'), 'local');
        $an = Announcement::create(['sender_id' => $u->id, 'title' => $d['title'], 'body' => $d['body'], 'audience' => $a, 'require_ack' => (bool) ($d['require_ack'] ?? false),
            'expires_at' => $d['expires_at'] ?? null, 'published_at' => now(), 'status' => 'published', 'attachment_path' => $path]);
        $now = now();
        AnnouncementReceipt::insert(array_map(fn ($uid) => ['announcement_id' => $an->id, 'user_id' => $uid, 'created_at' => $now, 'updated_at' => $now], $users));
        Audit::notify($users, ($an->require_ack ? 'إعلان يتطلب إقرارك: ' : 'إعلان: ').$an->title, mb_substr($an->body, 0, 140), 'info', ['screen' => 'announcements']);
        Audit::log('announcements', $an->id, 'publish', null, ['audience' => $a, 'recipients' => count($users)]);
        Audit::email($users, 'إعلان: '.$an->title, $an->title, $an->body, 'announcements', 'إعلان من '.$u->name.($an->require_ack ? ' — يتطلب تأكيد القراءة' : ''));

        return response()->json($this->row($an->load('sender:id,name'), null, $this->counts([$an->id])[$an->id] ?? null, $u), 201);
    }

    /**
     * Companies the user may publish to: 'all' (group CEO / group-wide HR), a list of company ids (company CEO, HR scoped
     * to companies, designated publishers), or null (no publishing right).
     */
    public static function publishScope(User $u): string|array|null
    {
        if ($u->hasRole('super') || ($u->hasRole('hr_mgr') && ! $u->hr_scope)) {
            return 'all';
        }
        $acc = Access::for($u);
        $ids = [];
        if ($u->hasRole('hr_mgr')) {
            $ids = array_map('intval', (array) $u->hr_scope);
        }
        if ($u->hasRole('hr_staff') && $acc->canAny('COM', 'publish')) {
            $ids = array_merge($ids, array_map('intval', $u->perms['companies'] ?? []));
        }
        if ($u->hasRole('ceo') && $u->company_id) {
            $ids[] = (int) $u->company_id;
        }
        if ($u->employee_id) {
            $ids = array_merge($ids, \App\Models\Company::where('ceo_employee_id', $u->employee_id)->pluck('id')->all(),
                DB::table('announcement_publishers')->where('employee_id', $u->employee_id)->pluck('company_id')->all());
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids ?: null;
    }

    /** What the current user may publish (UI). */
    public function canPublish(Request $request)
    {
        $s = self::publishScope($request->user());

        return ['can' => $s !== null, 'all' => $s === 'all', 'companies' => $s === 'all' ? \App\Models\Company::pluck('id') : ($s ?? []),
            'manage_publishers' => self::publisherCompanies($request->user())];
    }

    /** Companies whose publishers this user manages: the company CEO for his company, the group CEO / group HR for all. */
    private static function publisherCompanies(User $u): array
    {
        if ($u->hasRole('super') || ($u->hasRole('hr_mgr') && ! $u->hr_scope)) {
            return \App\Models\Company::pluck('id')->all();
        }
        $ids = $u->hasRole('ceo') && $u->company_id ? [(int) $u->company_id] : [];
        if ($u->employee_id) {
            $ids = array_merge($ids, \App\Models\Company::where('ceo_employee_id', $u->employee_id)->pluck('id')->all());
        }

        return array_values(array_unique($ids));
    }

    /** §115: publishers designated by the company CEO. */
    public function publishers(Request $request)
    {
        $cids = self::publisherCompanies($request->user());
        abort_unless($cids, 403);

        return DB::table('announcement_publishers as p')->join('employees as e', 'e.id', '=', 'p.employee_id')->join('companies as c', 'c.id', '=', 'p.company_id')
            ->whereIn('p.company_id', $cids)->orderBy('c.name')->get(['p.id', 'p.company_id', 'c.name as company', 'p.employee_id', 'e.name as employee', 'e.emp_no', 'p.created_at']);
    }

    public function addPublisher(Request $request)
    {
        $d = $request->validate(['company_id' => 'required|exists:companies,id', 'employee_id' => 'required|exists:employees,id']);
        abort_unless(in_array((int) $d['company_id'], self::publisherCompanies($request->user()), true), 403, 'يحدد الناشرين الرئيس التنفيذي للشركة (§115)');
        abort_unless((int) Employee::whereKey($d['employee_id'])->value('company_id') === (int) $d['company_id'], 422, 'الناشر من موظفي الشركة نفسها');
        DB::table('announcement_publishers')->updateOrInsert(['company_id' => $d['company_id'], 'employee_id' => $d['employee_id']], ['designated_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log('announcement_publishers', $d['employee_id'], 'designate', null, $d);
        Audit::notify(User::where('employee_id', $d['employee_id'])->value('id'), 'خُوّلت بنشر الإعلانات لشركتك', 'بتحديد من الرئيس التنفيذي للشركة (§115)', 'info', ['screen' => 'announcements']);

        return $this->publishers($request);
    }

    public function removePublisher(Request $request, int $id)
    {
        $row = DB::table('announcement_publishers')->find($id);
        abort_unless($row && in_array((int) $row->company_id, self::publisherCompanies($request->user()), true), 403);
        DB::table('announcement_publishers')->where('id', $id)->delete();
        Audit::log('announcement_publishers', $row->employee_id, 'revoke', (array) $row);

        return $this->publishers($request);
    }

    public function read(Request $request, Announcement $announcement)
    {
        $r = AnnouncementReceipt::where('announcement_id', $announcement->id)->where('user_id', $request->user()->id)->firstOrFail();
        $r->update(['read_at' => $r->read_at ?? now(), 'device' => mb_substr($request->userAgent() ?? '', 0, 80)]);

        return ['ok' => true];
    }

    public function ack(Request $request, Announcement $announcement)
    {
        $r = AnnouncementReceipt::where('announcement_id', $announcement->id)->where('user_id', $request->user()->id)->firstOrFail();
        $r->update(['read_at' => $r->read_at ?? now(), 'ack_at' => now(), 'device' => mb_substr($request->userAgent() ?? '', 0, 80)]);
        Audit::log('announcements', $announcement->id, 'ack', null, ['user' => $request->user()->id]);

        return ['ok' => true];
    }

    public function react(Request $request, Announcement $announcement)
    {
        abort_unless(Settings::get('reactionsEnabled', null, true), 422, 'التفاعلات معطّلة');
        $d = $request->validate(['reaction' => ['nullable', Rule::in(['like', 'thanks', 'celebrate'])]]);
        AnnouncementReceipt::where('announcement_id', $announcement->id)->where('user_id', $request->user()->id)->update(['reaction' => $d['reaction']]);

        return ['ok' => true];
    }

    /** Sender / HR: recipients with read & ack status. */
    public function tracking(Request $request, Announcement $announcement)
    {
        $u = $request->user();
        abort_unless($announcement->sender_id === $u->id || $u->hasRole('super', 'hr_mgr'), 403);
        $rows = AnnouncementReceipt::with('user:id,name,employee_id')->where('announcement_id', $announcement->id)->get();

        return ['announcement' => $this->row($announcement->load('sender:id,name'), null, $this->counts([$announcement->id])[$announcement->id] ?? null, $u), 'recipients' => $rows->map(fn ($r) => ['user' => $r->user?->only('id', 'name'), 'read_at' => $r->read_at, 'ack_at' => $r->ack_at, 'device' => $r->device, 'reaction' => $r->reaction]),
            'reactions' => $rows->whereNotNull('reaction')->groupBy('reaction')->map->count()];
    }

    public function remind(Request $request, Announcement $announcement)
    {
        $u = $request->user();
        abort_unless($announcement->sender_id === $u->id || $u->hasRole('super', 'hr_mgr'), 403);
        $ids = AnnouncementReceipt::where('announcement_id', $announcement->id)->whereNull($announcement->require_ack ? 'ack_at' : 'read_at')->pluck('user_id')->all();
        Audit::notify($ids, 'تذكير بإعلان: '.$announcement->title, $announcement->require_ack ? 'مطلوب إقرارك بالاطلاع' : 'لم تطّلع عليه بعد', 'warn', ['screen' => 'announcements']);

        return ['reminded' => count($ids)];
    }

    private function counts(array $ids)
    {
        return AnnouncementReceipt::whereIn('announcement_id', $ids)->selectRaw('announcement_id, count(*) as n, sum(case when read_at is null then 0 else 1 end) as r, sum(case when ack_at is null then 0 else 1 end) as a')->groupBy('announcement_id')->get()->keyBy('announcement_id');
    }

    private function row(Announcement $x, ?AnnouncementReceipt $mine, $counts, User $u): array
    {
        return $x->only('id', 'title', 'body', 'audience', 'require_ack', 'published_at', 'expires_at', 'status') + [
            'sender' => $x->sender?->name, 'is_sender' => $x->sender_id === $u->id, 'has_attachment' => (bool) $x->attachment_path, 'file' => $x->attachment_path,
            'read_at' => $mine?->read_at, 'ack_at' => $mine?->ack_at, 'my_reaction' => $mine?->reaction,
            'recipients' => (int) ($counts->n ?? 0), 'reads' => (int) ($counts->r ?? 0), 'acks' => (int) ($counts->a ?? 0),
            'expired' => $x->expires_at && $x->expires_at->isPast(),
        ];
    }
}
