<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseClaim extends Model
{
    protected $guarded = ['id'];

    /** §44 finance decisions: (1) settle & close, (2) return for documents, (3) close with refund to the company account, or deduct from next salary. */
    public const STATUSES = ['pending_finance' => 'بانتظار اعتماد المالية', 'settled' => 'مسوّاة ومُقفلة', 'refunded' => 'أُقفلت برد المتبقي لحساب الشركة',
        'to_salary' => 'أُقفلت بخصم المتبقي من الراتب', 'returned' => 'مُرجَعة لاستكمال المستندات'];

    protected function casts(): array
    {
        return ['invoices' => 'array', 'claimed' => 'float', 'settled' => 'float', 'decided_at' => 'datetime',
            'refund_amount' => 'float', 'refund_date' => 'date', 'salary_amount' => 'float'];
    }

    public function item() { return $this->belongsTo(CustodyItem::class, 'custody_item_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
