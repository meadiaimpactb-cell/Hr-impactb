<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Termination extends Model
{
    protected $guarded = ['id'];

    /** Appendix 5 — final settlement & payment: manager m1 → HR prepares → company CEO m2 → finance approves (final) and executes. */
    public const STAGES = ['mgr' => 'المشرف المباشر', 'hr' => 'الموارد البشرية (إعداد)', 'ceo' => 'الرئيس التنفيذي للشركة', 'fin' => 'المدير المالي (اعتماد نهائي وصرف)'];

    /** Role labels used by the other chains. */
    public const ROLES = ['mgr' => 'المشرف المباشر', 'hr' => 'الموارد البشرية', 'custodian' => 'الخدمات المساندة (العهد والتقنية)', 'fin' => 'المدير المالي',
        'ceo' => 'الرئيس التنفيذي للشركة', 'gceo' => 'الرئيس التنفيذي للمجموعة'];

    /**
     * Chains (appendix 5):
     * - decision: termination of a non-leader — manager m1 → finance m2 → company CEO m3 → group CEO final (§109)
     * - decision_leader: a department manager / leader — finance m1 → group CEO final
     * - resign: accepting / postponing a resignation — manager m1 → HR m2 → company CEO final (§108)
     * - clearance: manager → HR → support services → finance → company CEO final (§111); tasks themselves run in parallel
     */
    public const FLOWS = [
        'decision' => ['mgr', 'fin', 'ceo', 'gceo'],
        'decision_leader' => ['fin', 'gceo'],
        'resign' => ['mgr', 'hr', 'ceo'],
        'clearance' => ['mgr', 'hr', 'custodian', 'fin', 'ceo'],
    ];

    public const FLOW_LABELS = ['decision' => 'اعتماد قرار الإنهاء (§109)', 'resign' => 'قبول الاستقالة (§108)', 'clearance' => 'اعتماد إخلاء الطرف (§111)'];

    /** Reasons that are an employer decision to terminate and need the §109 checklist and decision chain. */
    public const DECISION_REASONS = ['employer'];

    public const STATUSES = ['resign_pending' => 'استقالة مقدَّمة — بانتظار القبول', 'resign_postponed' => 'استقالة مؤجَّل قبولها', 'withdrawn' => 'استقالة معدول عنها',
        'pending_decision' => 'قرار إنهاء بانتظار الاعتماد', 'rejected' => 'رُفض قرار الإنهاء',
        'open' => 'قائمة', 'settled' => 'مسوّاة — بانتظار الصرف', 'paid' => 'مصروفة', 'cancelled' => 'ملغاة'];

    /** Statuses that block opening another case for the same employee. */
    public const LIVE = ['resign_pending', 'resign_postponed', 'pending_decision', 'open', 'settled'];

    protected function casts(): array
    {
        return ['requested_at' => 'date:Y-m-d', 'filed_on' => 'date:Y-m-d', 'postponed_until' => 'date:Y-m-d', 'last_working_day' => 'date:Y-m-d', 'withdrawn_at' => 'datetime', 'accepted_at' => 'datetime',
            'settlement_alerted_on' => 'date:Y-m-d', 'clearance' => 'array', 'eosb' => 'array', 'settlement' => 'array', 'approvals' => 'array', 'checklist' => 'array', 'flows' => 'array',
            'eosb_adjusted' => 'float', 'paid_at' => 'datetime'];
    }

    public function byEmployee(): bool
    {
        return in_array($this->reason, ['resign', 'resignation'], true) || $this->initiated_by === 'employee';
    }

    /** §112: settlement due 7 days after the contract ends, 14 when the employee ended it. */
    public function settlementDue(): ?\Illuminate\Support\Carbon
    {
        $last = $this->last_working_day;

        return $last ? $last->copy()->addDays($this->byEmployee() ? 14 : 7) : null;
    }

    public function flow(string $key): ?array
    {
        return ($this->flows ?? [])[$key] ?? null;
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function company() { return $this->belongsTo(Company::class); }
}
