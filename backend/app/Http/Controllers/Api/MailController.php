<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailDraft;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use Illuminate\Http\Request;

/** Internal mail: threads between users, replies, read state, attachments. */
class MailController extends Controller
{
    public function threads(Request $request)
    {
        $u = $request->user();

        return MailThread::whereJsonContains('participants', $u->id)->orderByDesc('last_at')->limit(200)->get()->map(fn ($t) => $this->row($t, $u));
    }

    /** Who the user may write to (§115): employees inside their own company; HR, finance, the group CEO and company CEOs across their scope. */
    public function recipients(Request $request)
    {
        $u = $request->user();
        $ids = Access::for($u)->companyIds();
        $q = User::where('active', true)->where('id', '!=', $u->id)->with('employee:id,company_id,name', 'employee.company:id,name');
        if ($f = Access::for($u)->focus()) { // the company selector narrows the recipient list too
            $q->whereHas('employee', fn ($e) => $e->where('company_id', $f));
        }
        if (! $u->hasRole('super', 'hr_mgr', 'hr_staff', 'fin')) {
            $own = $u->employee?->company_id;
            $cids = $u->hasRole('ceo') && $ids ? $ids : array_values(array_filter([$own]));
            $q->whereHas('employee', fn ($e) => $e->whereIn('company_id', $cids ?: [0]));
        }

        return $q->orderBy('name')->get(['id', 'name', 'login', 'roles', 'employee_id'])
            ->map(fn ($x) => ['id' => $x->id, 'name' => $x->name, 'role' => User::ROLES[$x->roles[0] ?? 'emp'] ?? '', 'company_id' => $x->employee?->company_id, 'company' => $x->employee?->company?->name]);
    }

    /** Governance view (§115): the group CEO and the HR manager may read every thread, silently (audited). */
    public function all(Request $request)
    {
        $u = $request->user();
        abort_unless($u->hasRole('super', 'hr_mgr'), 403);
        Audit::log('mail', 0, 'governance_view');
        $f = $request->validate(['q' => 'nullable|string|max:100', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $q = MailThread::with('messages.sender:id,name')->orderByDesc('last_at');
        if (! empty($f['from'])) $q->where('last_at', '>=', $f['from']);
        if (! empty($f['to'])) $q->where('last_at', '<=', $f['to'].' 23:59:59');
        $names = User::pluck('name', 'id');

        return $q->limit(300)->get()->filter(fn ($t) => empty($f['q']) || str_contains(mb_strtolower($t->subject), mb_strtolower($f['q'])) || collect($t->participants)->contains(fn ($p) => str_contains(mb_strtolower($names[$p] ?? ''), mb_strtolower($f['q']))))
            ->map(fn ($t) => ['id' => $t->id, 'subject' => $t->subject, 'participants' => collect($t->participants)->map(fn ($p) => $names[$p] ?? '')->values(), 'last_at' => $t->last_at,
                'messages' => $t->messages->map(fn ($m) => ['from' => $m->sender?->name, 'body' => $m->body, 'at' => $m->created_at])])->values();
    }

    /** The author's unsent drafts — the only mail that can be deleted (a sent message is a record and stays). */
    public function drafts(Request $request)
    {
        $names = User::pluck('name', 'id');

        return MailDraft::where('user_id', $request->user()->id)->latest('updated_at')->get()
            ->map(fn ($d) => ['id' => $d->id, 'to' => $d->to ?? [], 'to_names' => collect($d->to ?? [])->map(fn ($i) => $names[$i] ?? '')->filter()->values(), 'subject' => $d->subject, 'body' => $d->body, 'group' => $d->group, 'updated_at' => $d->updated_at]);
    }

    public function saveDraft(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['id' => 'nullable|integer', 'to' => 'nullable|array|max:20', 'to.*' => 'exists:users,id', 'subject' => 'nullable|string|max:200', 'body' => 'nullable|string|max:10000', 'group' => 'nullable|boolean']);
        abort_if(empty($d['subject']) && empty($d['body']) && empty($d['to']), 422, 'المسودة فارغة');
        $data = ['to' => array_map('intval', $d['to'] ?? []), 'subject' => $d['subject'] ?? null, 'body' => $d['body'] ?? null, 'group' => (bool) ($d['group'] ?? false)];
        $draft = ! empty($d['id']) ? MailDraft::where('user_id', $u->id)->findOrFail($d['id']) : new MailDraft(['user_id' => $u->id]);
        $draft->fill($data)->save();

        return response()->json(['id' => $draft->id], 201);
    }

    public function deleteDraft(Request $request, MailDraft $draft)
    {
        abort_unless($draft->user_id === $request->user()->id, 403);
        $draft->delete();

        return ['ok' => true];
    }

    public function unread(Request $request)
    {
        $u = $request->user();

        return ['count' => MailThread::whereJsonContains('participants', $u->id)->get()->filter(fn ($t) => ! in_array($u->id, $t->read_by ?? [], true))->count()];
    }

    public function show(Request $request, MailThread $thread)
    {
        $u = $request->user();
        abort_unless(in_array($u->id, $thread->participants, true), 403);
        if (! in_array($u->id, $thread->read_by ?? [], true)) {
            $thread->update(['read_by' => [...($thread->read_by ?? []), $u->id]]);
        }

        return $this->row($thread, $u) + ['messages' => $thread->messages()->with('sender:id,name')->get()->map(fn ($m) => ['id' => $m->id, 'from' => $m->sender?->only('id', 'name'), 'mine' => $m->from_user_id === $u->id, 'body' => $m->body, 'file' => $m->attachment_path, 'at' => $m->created_at])];
    }

    public function store(Request $request)
    {
        $u = $request->user();
        $d = $request->validate(['to' => 'required|array|min:1|max:20', 'to.*' => 'exists:users,id', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:10000', 'group' => 'nullable|boolean', 'draft_id' => 'nullable|integer']);
        $allowed = collect($this->recipients($request))->pluck('id')->all();
        if (array_diff(array_map('intval', $d['to']), $allowed)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['to' => 'أحد المستلمين خارج نطاق مراسلاتك (موظفو شركتك فقط)']);
        }
        $to = array_values(array_unique(array_diff(array_map('intval', $d['to']), [$u->id])));
        // Privacy: by default every recipient gets a separate, private conversation with the sender — recipients never see
        // each other or each other's replies. A shared conversation is created only when the sender asks for one.
        $groups = ! empty($d['group']) && count($to) > 1 ? [$to] : array_map(fn ($id) => [$id], $to);
        $first = null;
        foreach ($groups as $members) {
            $thread = MailThread::create(['subject' => $d['subject'], 'participants' => [$u->id, ...$members], 'read_by' => [$u->id], 'last_at' => now()]);
            $this->addMessage($request, $thread, $d['body']);
            $first ??= $thread;
        }

        if (! empty($d['draft_id'])) { // a sent draft is no longer a draft
            MailDraft::where('user_id', $u->id)->where('id', $d['draft_id'])->delete();
        }

        return response()->json($this->row($first->fresh(), $u) + ['threads' => count($groups)], 201);
    }

    public function reply(Request $request, MailThread $thread)
    {
        $u = $request->user();
        abort_unless(in_array($u->id, $thread->participants, true), 403);
        $d = $request->validate(['body' => 'required|string|max:10000']);
        $this->addMessage($request, $thread, $d['body']);

        return $this->show($request, $thread->fresh());
    }

    private function addMessage(Request $request, MailThread $thread, string $body): void
    {
        $u = $request->user();
        $path = $request->file('attachment')?->store('mail/'.now()->format('Y/m'), 'local');
        MailMessage::create(['thread_id' => $thread->id, 'from_user_id' => $u->id, 'body' => $body, 'attachment_path' => $path]);
        $thread->update(['last_at' => now(), 'read_by' => [$u->id]]);
        $others = array_values(array_diff($thread->participants, [$u->id]));
        Audit::notify($others, 'رسالة داخلية من '.$u->name, $thread->subject.' — '.mb_substr($body, 0, 100), 'info', ['screen' => 'mail']);
        // email copy to each recipient's official address when the mailbox is configured
        if (\App\Services\InviteService::mailConfigured()) {
            foreach (User::whereIn('id', $others)->where('active', true)->whereNotNull('email')->get() as $to) {
                try {
                    \Illuminate\Support\Facades\Mail::to($to->email)->send(new \App\Mail\InternalMessageMail($u->name, $thread->subject, $body, $thread->id));
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    private function row(MailThread $t, User $u): array
    {
        $names = User::whereIn('id', $t->participants)->pluck('name', 'id');
        $last = $t->messages()->latest('id')->first();

        return ['id' => $t->id, 'subject' => $t->subject, 'participants' => collect($t->participants)->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? '#'.$id])->values(),
            'others' => collect($t->participants)->reject(fn ($id) => $id === $u->id)->map(fn ($id) => $names[$id] ?? '#'.$id)->join('، '),
            'unread' => ! in_array($u->id, $t->read_by ?? [], true), 'last_at' => $t->last_at, 'preview' => mb_substr($last?->body ?? '', 0, 90), 'count' => $t->messages()->count()];
    }
}
