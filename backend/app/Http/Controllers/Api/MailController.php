<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MailMessage;
use App\Models\MailThread;
use App\Models\User;
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
        $d = $request->validate(['to' => 'required|array|min:1|max:20', 'to.*' => 'exists:users,id', 'subject' => 'required|string|max:200', 'body' => 'required|string|max:10000']);
        $participants = array_values(array_unique([$u->id, ...array_map('intval', $d['to'])]));
        $thread = MailThread::create(['subject' => $d['subject'], 'participants' => $participants, 'read_by' => [$u->id], 'last_at' => now()]);
        $this->addMessage($request, $thread, $d['body']);

        return response()->json($this->row($thread->fresh(), $u), 201);
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
