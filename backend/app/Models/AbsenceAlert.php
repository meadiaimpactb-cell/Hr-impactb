<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** §100–101 absence watch: consecutive / scattered unexcused absence thresholds in the contract year (alerts only, never automatic sanctions). */
class AbsenceAlert extends Model
{
    protected $guarded = ['id'];

    public const KINDS = [
        'consecutive10' => 'انقطاع متصل 10 أيام — يلزم الإنذار الكتابي قبل الفصل (§101)',
        'consecutive15' => 'انقطاع متصل تجاوز 15 يومًا — يُنظر في الفصل وفق المادة (80) بعد الإنذار (§101)',
        'scattered20' => 'غياب متفرق بلغ 20 يومًا في السنة العقدية — يلزم الإنذار الكتابي قبل الفصل (§101)',
        'scattered30' => 'غياب متفرق تجاوز 30 يومًا في السنة العقدية — يُنظر في الفصل وفق المادة (80) بعد الإنذار (§101)',
    ];

    protected function casts(): array
    {
        return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'year_start' => 'date:Y-m-d', 'dates' => 'array'];
    }

    public function employee() { return $this->belongsTo(Employee::class); }

    /** Violation type to pre-fill: the longest absence ladder for consecutive absence, the short one for scattered days. */
    public function suggestedType(): string
    {
        return str_starts_with($this->kind, 'consecutive') ? 'absence7' : 'absence1';
    }

    public function describe(): string
    {
        $label = str_starts_with($this->kind, 'consecutive') ? "غياب متصل دون عذر {$this->days} يوم عمل" : "غياب متفرق دون عذر {$this->days} يوم عمل في السنة العقدية";

        return 'إنذار كتابي قبل الفصل — '.$label.' من '.$this->from->format('d/m/Y').' إلى '.$this->to->format('d/m/Y').' (§100–101)';
    }
}
