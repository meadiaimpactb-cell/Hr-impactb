<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** §88: the incentives & praise register, next to the violations register. */
class Incentive extends Model
{
    protected $guarded = ['id'];

    public const KINDS = ['praise' => 'إشادة', 'thanks_letter' => 'خطاب شكر', 'certificate' => 'شهادة تقدير', 'bonus' => 'مكافأة فورية', 'honor' => 'تكريم', 'achievement' => 'إنجاز نوعي', 'star' => 'نجم التطوير'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'paid' => 'boolean'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function giver() { return $this->belongsTo(User::class, 'given_by'); }
}
