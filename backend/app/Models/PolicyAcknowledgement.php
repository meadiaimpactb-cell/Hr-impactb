<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Appendix 4: an employee's e-signed acknowledgement of a policy version (kept in the employee file). */
class PolicyAcknowledgement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function employee() { return $this->belongsTo(Employee::class); }

    /** Appendix 4 text for a given version. */
    public static function text(string $version): string
    {
        return 'أقرّ أنا الموظف الموقّع أدناه بأنني اطلعت على «سياسات ولوائح رأس المال البشري لمجموعة وادي الأثر المالية» (الإصدار '.$version.')، وفهمت ما ورد فيها من حقوق وواجبات، '
            .'وألتزم بالعمل بها، وبرسالة المجموعة وقيمها في صناعة الأثر الاجتماعي، وبقواعد السلوك والمثول الأخلاقي واللباس المحتشم داخل العمل وخارجه، '
            .'وبضوابط العهد والسلف والصرف البنكي. وأعلم أن هذا الإقرار لا يُعد تنازلًا عن أي حق نظامي أو تعاقدي.';
    }
}
