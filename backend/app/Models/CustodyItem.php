<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustodyItem extends Model
{
    protected $guarded = ['id'];

    /** The encrypted secret never leaves the server in a listing; it is only decrypted on an explicit, logged reveal. */
    protected $hidden = ['secret'];

    public const STATUSES = [
        'available' => 'متاحة', 'pending_ack' => 'بانتظار الإقرار', 'in_use' => 'قيد الاستخدام',
        'return_requested' => 'مطلوب استردادها', 'inspecting' => 'قيد الفحص', 'lost' => 'مفقودة / تالفة',
    ];

    protected function casts(): array
    {
        return ['fields' => 'array', 'history' => 'array', 'value' => 'float', 'secret_rotated_at' => 'datetime'];
    }

    public function type() { return $this->belongsTo(CustodyType::class, 'custody_type_id'); }
    public function company() { return $this->belongsTo(Company::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function secretLogs() { return $this->hasMany(CustodySecretLog::class)->latest(); }
    public function claims() { return $this->hasMany(ExpenseClaim::class); }

    public function log(string $event, ?string $note = null, ?int $employeeId = null): void
    {
        $h = $this->history ?? [];
        $h[] = array_filter(['at' => now()->toDateTimeString(), 'event' => $event, 'by' => auth()->id(), 'employee_id' => $employeeId ?? $this->employee_id, 'note' => $note], fn ($v) => $v !== null);
        $this->history = $h;
    }
}
