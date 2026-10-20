<?php

namespace App\Models;

use App\Services\AttendanceService;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class CustodyItem extends Model
{
    protected $guarded = ['id'];

    /** The encrypted secret never leaves the server in a listing; it is only decrypted on an explicit, logged reveal. */
    protected $hidden = ['secret'];

    // §43: lost and damaged are separate end states
    public const STATUSES = [
        'available' => 'متاحة', 'pending_ack' => 'بانتظار الإقرار', 'in_use' => 'قيد الاستخدام',
        'return_requested' => 'مطلوب استردادها', 'inspecting' => 'قيد الفحص', 'lost' => 'مفقودة', 'damaged' => 'تالفة',
    ];

    /** States in which the item is still held by (or charged to) the employee. */
    public const OPEN = ['pending_ack', 'in_use', 'return_requested'];

    /** §43: the undertaking the employee accepts electronically when acknowledging receipt. */
    public const ACK_TEXT = 'أقرّ بأنني استلمت هذه العهدة، وأتحمّل مسؤولية المحافظة عليها واستخدامها في أغراض العمل فقط، '
        .'وأوافق على استيفاء ما لا يُسوّى منها وفق أحكام الباب الثامن من لائحة تنظيم العمل (المادة 43)، '
        .'وأتعهد بتسوية العهدة النقدية بالفواتير والمستندات المؤيدة خلال المدة المحددة في المادة (44).';

    protected function casts(): array
    {
        return ['fields' => 'array', 'history' => 'array', 'value' => 'float', 'secret_rotated_at' => 'datetime',
            'disbursed_at' => 'datetime', 'purpose_end' => 'date', 'settle_due' => 'date', 'overdue_at' => 'datetime', 'overdue_notified_at' => 'datetime',
            'ack_at' => 'datetime', 'overspend_approved' => 'boolean', 'overspend_limit' => 'float'];
    }

    protected static function booted(): void
    {
        // Every hand-over stamps the disbursement date; a trip custody ends with the trip (§44 settlement clock).
        static::saving(function (CustodyItem $i) {
            if (! self::hasDeadlineColumns()) {
                return;
            }
            if ($i->employee_id && ! $i->disbursed_at && in_array($i->status, self::OPEN, true)) {
                $i->disbursed_at = now();
            }
            if ($i->isDirty('employee_id') && $i->getOriginal('employee_id') && $i->employee_id !== $i->getOriginal('employee_id')) {
                $i->disbursed_at = $i->employee_id ? now() : null; // transferred / returned: the clock restarts
                $i->overdue_at = null;
                $i->overdue_notified_at = null;
            }
            if (! $i->employee_id) {
                $i->settle_due = null;
                $i->overdue_at = null;
            }
            if ($i->trip_request_id && ! $i->purpose_end) {
                $to = ApprovalRequest::whereKey($i->trip_request_id)->value('data');
                $to = is_string($to) ? (json_decode($to, true)['to'] ?? null) : ($to['to'] ?? null);
                if ($to) {
                    $i->purpose_end = $to;
                }
            }
            if ($i->employee_id && $i->isCash()) {
                $i->settle_due = $i->computeSettleDue();
            }
        });
    }

    private static ?bool $hasCols = null;

    public static function hasDeadlineColumns(): bool
    {
        return self::$hasCols ??= Schema::hasColumn('custody_items', 'settle_due');
    }

    public function type() { return $this->belongsTo(CustodyType::class, 'custody_type_id'); }
    public function company() { return $this->belongsTo(Company::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function secretLogs() { return $this->hasMany(CustodySecretLog::class)->latest(); }
    public function claims() { return $this->hasMany(ExpenseClaim::class); }

    public function isCash(): bool
    {
        return (bool) ($this->type?->is_cash ?? CustodyType::whereKey($this->custody_type_id)->value('is_cash'));
    }

    public function log(string $event, ?string $note = null, ?int $employeeId = null): void
    {
        $h = $this->history ?? [];
        $h[] = array_filter(['at' => now()->toDateTimeString(), 'event' => $event, 'by' => auth()->id(), 'employee_id' => $employeeId ?? $this->employee_id, 'note' => $note], fn ($v) => $v !== null);
        $this->history = $h;
    }

    /**
     * §44: settle within custodySettleDays working days of the end of the purpose (or return from the trip),
     * and in any case within custodySettleMaxDays calendar days of the disbursement — whichever comes first.
     */
    public function computeSettleDue(): ?string
    {
        $from = $this->disbursed_at ? Carbon::parse($this->disbursed_at) : ($this->created_at ? Carbon::parse($this->created_at) : now());
        $max = (int) Settings::get('custodySettleMaxDays', $this->company_id, 30);
        $due = $from->copy()->startOfDay()->addDays($max);
        if ($this->purpose_end) {
            $work = (int) Settings::get('custodySettleDays', $this->company_id, 10);
            $emp = $this->employee ?? ($this->employee_id ? Employee::find($this->employee_id) : null);
            $d = Carbon::parse($this->purpose_end)->startOfDay();
            for ($n = 0, $guard = 0; $n < $work && $guard < 120; $guard++) {
                $d->addDay();
                $isWork = \App\Services\WorkCalendar::isWorkDay($emp, $d); // the employee's week minus official holidays
                if ($isWork) {
                    $n++;
                }
            }
            $due = $d->lt($due) ? $d : $due;
        }

        return $due->toDateString();
    }

    /** A claim is with finance: the employee has done their part, so the item is not overdue on them. */
    public function awaitingFinance(): bool
    {
        return $this->claims()->where('status', 'pending_finance')->exists();
    }

    public function isOverdue(): bool
    {
        if (! $this->employee_id || ! in_array($this->status, self::OPEN, true) || ! $this->isCash() || $this->value <= 0) {
            return false;
        }
        $due = $this->settle_due ? $this->settle_due->toDateString() : $this->computeSettleDue();

        return $due && now()->toDateString() > $due && ! $this->awaitingFinance();
    }

    /** Open cash custody held by an employee (pending acknowledgement, in use or being returned). */
    public static function openCash(int $employeeId)
    {
        return self::with('type:id,is_cash')->where('employee_id', $employeeId)->whereIn('status', self::OPEN)
            ->whereHas('type', fn ($q) => $q->where('is_cash', true))->get();
    }

    /**
     * §44: an employee late in settling a cash custody may not receive a new custody or advance.
     * Used by the request engine to block new advance / custody requests.
     */
    public static function hasOverdueCash(int $employeeId): bool
    {
        return self::openCash($employeeId)->contains(fn (CustodyItem $i) => $i->isOverdue());
    }

    public static function hasOpenCash(int $employeeId): bool
    {
        return self::openCash($employeeId)->isNotEmpty();
    }
}
