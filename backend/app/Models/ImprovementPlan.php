<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** §75: correction session (30 days) and performance improvement plan (60–90 days). */
class ImprovementPlan extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = ['draft' => 'مسودة', 'active' => 'جارية', 'extended' => 'ممددة', 'succeeded' => 'ناجحة', 'failed' => 'لم تنجح', 'closed' => 'مغلقة'];

    public const STAGES = ['session' => 'جلسة التصحيح (30 يومًا)', 'plan' => 'خطة تحسين الأداء (60–90 يومًا)'];

    protected function casts(): array
    {
        return ['start' => 'date:Y-m-d', 'end' => 'date:Y-m-d', 'goals' => 'array', 'reviews' => 'array', 'approved_at' => 'datetime'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
}
