<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\MailThread;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Serves private uploads with per-path authorization. */
class FileController extends Controller
{
    public function download(Request $request)
    {
        $u = $request->user();
        $path = ltrim((string) $request->query('path'), '/');
        abort_if(str_contains($path, '..') || $path === '' || ! Storage::disk('local')->exists($path), 404);
        $ok = $u->hasRole('super', 'hr_mgr');
        if (! $ok && preg_match('#^docs/employee/(\d+)/#', $path, $m)) {
            $ok = (int) $m[1] === $u->employee_id || \App\Services\Access::for()->canSeeEmployee(\App\Models\Employee::findOrFail($m[1]));
        } elseif (! $ok && preg_match('#^docs/company/(\d+)/#', $path, $m)) {
            $ok = \App\Services\Access::for()->can('DOC', 'view', ['company_id' => (int) $m[1]]);
        } elseif (! $ok && str_starts_with($path, 'requests/')) {
            $r = ApprovalRequest::where('attachment_path', $path)->first();
            $ok = $r && ($r->employee_id === $u->employee_id || in_array($u->id, \App\Services\ApprovalService::approvers($r), true) || \App\Services\Access::for()->canSeeEmployee($r->employee));
        } elseif (! $ok && str_starts_with($path, 'letters/')) {
            $l = \App\Models\Letter::where('attachment_path', $path)->first();
            $ok = $l && ($l->employee_id === $u->employee_id || \App\Services\Access::for()->canSeeEmployee($l->employee));
        } elseif (! $ok && str_starts_with($path, 'mail/')) {
            $ok = MailThread::whereHas('messages', fn ($q) => $q->where('attachment_path', $path))->whereJsonContains('participants', $u->id)->exists();
        } elseif (! $ok && str_starts_with($path, 'announcements/')) {
            $ok = \App\Models\AnnouncementReceipt::where('user_id', $u->id)->whereHas('announcement', fn ($q) => $q->where('attachment_path', $path))->exists();
        } elseif (! $ok && str_starts_with($path, 'cv/')) {
            $ok = $u->isHr() && \App\Services\Access::for()->canAny('EMP', 'add');
        } elseif (! $ok && str_starts_with($path, 'claims/')) {
            $ok = $u->hasRole('fin') || \App\Models\ExpenseClaim::where('attachment_path', $path)->where('employee_id', $u->employee_id)->exists();
        } elseif (! $ok && str_starts_with($path, 'selfies/')) {
            $rec = \App\Models\AttendanceRecord::where('selfie_path', $path)->first();
            $ok = $rec && ($rec->employee_id === $u->employee_id || \App\Services\Access::for()->can('ATT', 'view', ['employee' => $rec->employee]));
        } elseif (! $ok && str_starts_with($path, 'dev/')) {
            $ok = \App\Models\DevRecord::where('certificate_path', $path)->where(fn ($q) => $q->where('employee_id', $u->employee_id))->exists() || $u->isHr();
        }
        abort_unless($ok, 403);
        if (\App\Services\Settings::get('accessLogEnabled', null, true)) {
            \App\Services\Audit::log('files', $path, 'download');
        }

        return Storage::disk('local')->download($path);
    }
}
