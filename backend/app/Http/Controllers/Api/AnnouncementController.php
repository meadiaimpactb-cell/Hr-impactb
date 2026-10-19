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
        // who may address whom: super/hr_mgr any; ceo & hr_staff their companies; managers only their direct reports
        if ($u->hasRole('super', 'hr_mgr')) {
            // any
        } elseif ($u->hasRole('ceo', 'hr_staff') && $acc->canAny('COM', 'publish')) {
            abort_if($a['scope'] === 'group', 403, 'إعلان المجموعة للمدير العام ومدير الموارد البشرية');
            if ($a['scope'] === 'company') {
                $a['companies'] = array_values(array_intersect(array_map('intval', $a['companies'] ?? []), $acc->companyIds()));
            }
        } else {
            $reports = Employee::where('manager_id', $u->employee_id)->pluck('id')->all();
            abort_unless($reports, 403, 'لا صلاحية لنشر إعلانات');
            $a = ['scope' => 'people', 'employee_ids' => array_values(array_intersect(array_map('intval', $a['employee_ids'] ?? $reports), $reports)) ?: $reports];
        }
        $users = array_values(array_diff(self::audienceUsers($a), []));
        if (! $u->hasRole('super', 'hr_mgr')) { // job / contract / people audiences are narrowed to the publisher's companies too
            $allowed = User::whereIn('employee_id', Employee::whereIn('company_id', $acc->companyIds() ?: [0])->pluck('id'))->pluck('id')->all();
            if ($u->hasRole('ceo', 'hr_staff') && $acc->canAny('COM', 'publish')) {
                $users = array_values(array_intersect($users, $allowed));
            }
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
