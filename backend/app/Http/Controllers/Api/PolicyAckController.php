<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PolicyAcknowledgement;
use App\Models\SignedDoc;
use App\Models\User;
use App\Services\Access;
use App\Services\Audit;
use App\Services\Settings;
use Illuminate\Http\Request;

/**
 * Appendix 4 — acknowledgement of the HC policy: signed electronically on hiring and on every substantial amendment
 * (HR bumps the setting policyVersion), kept in the employee file (signed_docs + career log). Until the current
 * version is acknowledged the portal shows a blocking acknowledgement screen.
 */
class PolicyAckController extends Controller
{
    public static function version(?int $companyId = null): string
    {
        return (string) (Settings::get('policyVersion', $companyId, '2.1') ?: '2.1');
    }

    public function status(Request $request)
    {
        $u = $request->user();
        $e = $u->employee?->loadMissing('company:id,name,logo_path', 'jobTitle:id,name');
        $v = self::version($e?->company_id);
        $ack = PolicyAcknowledgement::where('user_id', $u->id)->where('version', $v)->first();

        return ['version' => $v, 'required' => (bool) $e && ! $ack, 'signed_at' => $ack?->signed_at?->toDateTimeString(), 'text' => PolicyAcknowledgement::text($v),
            'employee' => $e ? ['name' => $e->name, 'emp_no' => $e->emp_no, 'company' => $e->company?->name, 'job' => $e->jobTitle?->name] : null,
            'history' => PolicyAcknowledgement::where('user_id', $u->id)->orderByDesc('id')->get(['version', 'signed_at', 'typed_name'])];
    }

    public function sign(Request $request)
    {
        $u = $request->user();
        $e = $u->employee;
        abort_unless($e, 422, 'الإقرار للموظفين');
        $d = $request->validate(['typed_name' => 'required|string|min:3|max:160', 'signature' => 'nullable|string|max:200000|starts_with:data:image/png;base64,', 'agree' => 'accepted']);
        $v = self::version($e->company_id);
        abort_if(PolicyAcknowledgement::where('user_id', $u->id)->where('version', $v)->exists(), 422, 'سبق إقرارك بهذا الإصدار');
        $text = PolicyAcknowledgement::text($v);
        $hash = hash('sha256', $text."\n".$e->id."\n".$d['typed_name']);
        $doc = SignedDoc::create(['type' => 'policy_ack', 'ref_id' => $e->id, 'title' => 'إقرار الاطلاع والالتزام باللائحة — الإصدار '.$v, 'hash' => $hash, 'signed_by' => $u->id, 'employee_id' => $e->id,
            'signature' => $d['signature'] ?? $u->signature, 'device' => mb_substr($request->userAgent() ?? '', 0, 120), 'ip' => $request->ip()]);
        $ack = PolicyAcknowledgement::create(['user_id' => $u->id, 'employee_id' => $e->id, 'version' => $v, 'typed_name' => $d['typed_name'], 'signature' => $d['signature'] ?? $u->signature,
            'text_hash' => $hash, 'ip' => $request->ip(), 'device' => mb_substr($request->userAgent() ?? '', 0, 160), 'signed_doc_id' => $doc->id, 'signed_at' => now()]);
        $e->addCareer('إقرار اللائحة', 'أقرّ بالاطلاع على اللائحة والالتزام بها — الإصدار '.$v.' (الملحق الرابع)', $u->id);
        Audit::log('policy_acknowledgements', $ack->id, 'sign', null, ['version' => $v, 'employee' => $e->id]);

        return $this->status($request);
    }

    /** HR: who has / hasn't acknowledged the current version (employee scope). */
    public function report(Request $request)
    {
        $acc = Access::for();
        abort_unless($request->user()->isHr() || $request->user()->hasRole('super') || $acc->canAny('EMP', 'view'), 403);
        $emps = $acc->employees()->where('status', '!=', 'terminated')->with('company:id,name', 'user:id,employee_id')->orderBy('name')->get(['id', 'name', 'emp_no', 'company_id', 'hire_date']);
        $acks = PolicyAcknowledgement::whereIn('employee_id', $emps->pluck('id'))->get()->groupBy('employee_id');
        $rows = $emps->map(function ($e) use ($acks) {
            $v = self::version($e->company_id);
            $mine = $acks[$e->id] ?? collect();
            $cur = $mine->firstWhere('version', $v);

            return ['employee' => $e->only('id', 'name', 'emp_no'), 'company' => $e->company?->name, 'version' => $v, 'signed' => (bool) $cur, 'signed_at' => $cur?->signed_at?->toDateTimeString(),
                'ip' => $cur?->ip, 'has_account' => (bool) $e->user, 'previous' => $mine->where('version', '!=', $v)->pluck('version')->values()];
        });

        return ['version' => self::version(), 'rows' => $rows->values(), 'signed' => $rows->where('signed', true)->count(), 'total' => $rows->count(), 'can_bump' => $request->user()->hasRole('super', 'hr_mgr')];
    }

    /** HR manager / group CEO publishes a new version → everyone must acknowledge again. */
    public function setVersion(Request $request)
    {
        abort_unless($request->user()->hasRole('super', 'hr_mgr'), 403);
        $d = $request->validate(['version' => 'required|string|max:20|regex:/^[0-9A-Za-z.\-]+$/', 'reason' => 'required|string|max:500']);
        abort_if($d['version'] === self::version(), 422, 'هذا هو الإصدار الحالي');
        Settings::set('policyVersion', $d['version'], null, 'تعديل جوهري للائحة: '.$d['reason']);
        $ids = User::where('active', true)->whereNotNull('employee_id')->pluck('id')->all();
        Audit::notify($ids, 'صدر إصدار جديد من اللائحة ('.$d['version'].') — مطلوب إقرارك', $d['reason'], 'warn', ['screen' => 'dash']);

        return $this->report($request);
    }
}
