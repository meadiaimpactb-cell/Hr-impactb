<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\CustodyItem;
use App\Models\CustodyType;
use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;

/** Custody types + a few demo items. Safe to run on an existing database (idempotent on type names). */
class CustodySeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            ['جهاز / لابتوب', 'Laptop', ['الماركة والموديل', 'الرقم التسلسلي', 'المواصفات', 'تاريخ الشراء'], true, false, false, 'التقنية'],
            ['جوال', 'Mobile', ['الماركة والموديل', 'IMEI', 'الحالة'], true, false, false, 'التقنية'],
            ['خط جوال / شريحة', 'SIM line', ['رقم الخط', 'المزود', 'الباقة وقيمتها', 'تاريخ التجديد'], true, true, false, 'الإدارة'],
            ['عهدة مالية', 'Cash float', ['الغرض', 'تاريخ الصرف'], true, false, true, 'المالية'],
            ['بريد وظيفي', 'Work email', ['العنوان', 'مزود الخدمة', 'صلاحيات الحساب'], false, true, false, 'التقنية'],
            ['حساب نظام / اشتراك', 'System account', ['اسم النظام', 'اسم المستخدم', 'نوع الاشتراك', 'تاريخ التجديد'], false, true, false, 'التقنية'],
            ['وسيلة دخول', 'Access card', ['البطاقة أو المفتاح', 'الرقم', 'النطاق المسموح'], true, false, false, 'الإدارة'],
        ];
        $T = [];
        foreach ($types as [$name, $en, $fields, $ack, $secret, $cash, $dept]) {
            $T[$en] = CustodyType::firstOrCreate(['name' => $name], ['name_en' => $en, 'fields' => $fields, 'requires_ack' => $ack, 'has_secret' => $secret, 'is_cash' => $cash, 'dept' => $dept]);
        }
        if (CustodyItem::exists()) {
            return;
        }

        $co = fn ($code) => Company::where('code', $code)->value('id');
        $emp = fn ($no) => Employee::where('emp_no', $no)->value('id');
        $mk = function (string $type, string $company, string $ident, array $fields, float $value, ?string $empNo, string $status, ?string $secret = null) use ($T, $co, $emp) {
            $eid = $empNo ? $emp($empNo) : null;
            $history = [['at' => now()->subDays(40)->toDateTimeString(), 'event' => 'تجهيز']];
            if ($eid && in_array($status, ['in_use', 'return_requested'], true)) {
                $history[] = ['at' => now()->subDays(30)->toDateTimeString(), 'event' => 'تسليم', 'employee_id' => $eid];
                $history[] = ['at' => now()->subDays(30)->addHours(2)->toDateTimeString(), 'event' => 'إقرار استلام', 'employee_id' => $eid];
            }
            if ($eid && $status === 'pending_ack') {
                $history[] = ['at' => now()->toDateTimeString(), 'event' => 'طلب تسليم', 'employee_id' => $eid];
            }
            if ($status === 'return_requested') {
                $history[] = ['at' => now()->toDateTimeString(), 'event' => 'طلب استرداد', 'employee_id' => $eid];
            }
            CustodyItem::create(['custody_type_id' => $T[$type]->id, 'company_id' => $co($company), 'ident' => $ident, 'fields' => $fields, 'value' => $value,
                'employee_id' => $eid, 'status' => $status, 'history' => $history, 'secret' => $secret ? Crypt::encryptString($secret) : null, 'secret_rotated_at' => $secret ? now() : null]);
        };

        $mk('Laptop', 'IVC', 'MacBook Pro 14 — M3', ['الماركة والموديل' => 'Apple MacBook Pro 14 M3', 'الرقم التسلسلي' => 'C02XK1ABJG5H', 'المواصفات' => '16GB / 512GB'], 9800, 'E013', 'in_use');
        $mk('Laptop', 'IRTH', 'Dell Latitude 5540', ['الماركة والموديل' => 'Dell Latitude 5540', 'الرقم التسلسلي' => 'DL5540-88213', 'المواصفات' => '16GB / 256GB'], 4200, 'E008', 'in_use');
        $mk('Mobile', 'IRTH', 'iPhone 15', ['الماركة والموديل' => 'Apple iPhone 15', 'IMEI' => '35-209900-176148-1', 'الحالة' => 'جيدة'], 3400, 'E009', 'pending_ack');
        $mk('SIM line', 'IRTH', '0551234567', ['رقم الخط' => '0551234567', 'المزود' => 'STC', 'الباقة وقيمتها' => 'بيزنس 250 ر.س'], 250, 'E009', 'in_use', 'PIN 4471 · PUK 88213311');
        $mk('Laptop', 'IVC', 'Lenovo ThinkPad T14', ['الماركة والموديل' => 'Lenovo ThinkPad T14', 'الرقم التسلسلي' => 'PF3K9X2', 'المواصفات' => '16GB / 512GB'], 5100, null, 'available');
        $mk('System account', 'IVC', 'حساب Canva للفريق', ['اسم النظام' => 'Canva Teams', 'اسم المستخدم' => 'design@impactv.sa', 'نوع الاشتراك' => 'سنوي'], 1200, 'E009', 'in_use', 'Cnv@2026!design');
        $mk('Cash float', 'PTRIPS', 'عهدة مصروفات — رحلة المغرب', ['الغرض' => 'مصروفات تشغيلية للرحلة', 'تاريخ الصرف' => now()->subDays(20)->toDateString()], 8000, 'E011', 'in_use');
        $mk('Access card', 'IVC', 'بطاقة دخول المقر AC-0031', ['البطاقة أو المفتاح' => 'بطاقة', 'الرقم' => 'AC-0031', 'النطاق المسموح' => 'الدور الثالث'], 0, 'E003', 'return_requested');
    }
}
