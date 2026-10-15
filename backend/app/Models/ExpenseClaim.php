<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseClaim extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['pending_finance' => 'بانتظار اعتماد المالية', 'settled' => 'مسوّاة', 'to_salary' => 'حُوّل الباقي لخصم من الراتب', 'returned' => 'مُرجَعة'];

    protected function casts(): array
    {
        return ['invoices' => 'array', 'claimed' => 'float', 'settled' => 'float', 'decided_at' => 'datetime'];
    }

    public function item() { return $this->belongsTo(CustodyItem::class, 'custody_item_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
}
