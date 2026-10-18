<?php

use App\Models\Company;
use App\Models\Tier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Policy v2.0 — Chapter 9/10 (§47–55): companies are classified into three tiers (تأسيس / تمكين / أثر) that drive
 * benefits, and every company carries its own visual identity (logo + brand colours) that its employees see.
 * Replaces the one-package-per-company model: packages become the three classification tiers, all companies start
 * in tier 1 (§49), and the group's eight companies get their official names, logos and colours.
 */
return new class extends Migration
{
    private const COMMON = [
        'leaveUnit' => 'calendar', 'sick' => 30, 'emergency' => 5, 'maternity' => 84, 'marriage' => 5, 'bereavement' => 5, 'siblingBereavement' => 3, 'newborn' => 3, 'hajj' => 15,
        'unpaidMax' => 30, 'carryOverMax' => 0, 'housingPct' => 25, 'transportPct' => 10, 'transportMin' => 350, 'transport' => 350, 'weeklyHours' => 45, 'workDays' => 5,
        'programsPerMonth' => 1, 'dependents' => 2, 'carAllowance2' => 0, 'carAllowance4' => 0, 'carAllowanceDaily' => 0,
    ];

    private const TIERS = [
        ['code' => 'tasis', 'rank' => 1, 'name' => 'الفئة الأولى: تأسيس', 'name_en' => 'Tier 1 — Foundation', 'color' => '#6B7A99', 'is_default' => true, 'benefits' => [
            'annualLeave' => 21, 'leaveUnit' => 'calendar', 'eidExtraDays' => 0, 'bridgeDay' => 0, 'devBudget' => 500, 'devDays' => 0, 'flexMinutes' => 0, 'remote' => 0,
            'ticketCap' => 0, 'ticketFamily' => 0, 'bonusMaxMonths' => 1, 'hotelCap' => 200, 'perDiemCeo' => 150, 'perDiemDir' => 150, 'perDiemMgr' => 150, 'perDiemSpec' => 150, 'perDiemStaff' => 150,
            'comm' => 250, 'commMax' => 250, 'insuranceClass' => 'Basic', 'insuranceMgrs' => 'Basic', 'insuranceLeaders' => 'C', 'insuranceCeo' => 'B',
        ]],
        ['code' => 'tamkeen', 'rank' => 2, 'name' => 'الفئة الثانية: تمكين', 'name_en' => 'Tier 2 — Empowerment', 'color' => '#1B82C3', 'is_default' => false, 'benefits' => [
            'annualLeave' => 25, 'leaveUnit' => 'work', 'eidExtraDays' => 1, 'bridgeDay' => 1, 'devBudget' => 5000, 'devDays' => 3, 'flexMinutes' => 120, 'remote' => 0,
            'ticketCap' => 2500, 'ticketFamily' => 0, 'bonusMaxMonths' => 3, 'hotelCap' => 400, 'perDiemCeo' => 300, 'perDiemDir' => 250, 'perDiemMgr' => 200, 'perDiemSpec' => 150, 'perDiemStaff' => 100,
            'comm' => 500, 'commMax' => 500, 'carAllowance2' => 600, 'carAllowance4' => 800, 'carAllowanceDaily' => 1000,
            'insuranceClass' => 'Basic', 'insuranceMgrs' => 'C', 'insuranceLeaders' => 'B', 'insuranceCeo' => 'A',
        ]],
        ['code' => 'athar', 'rank' => 3, 'name' => 'الفئة الثالثة: أثر', 'name_en' => 'Tier 3 — Impact', 'color' => '#17936B', 'is_default' => false, 'benefits' => [
            'annualLeave' => 30, 'leaveUnit' => 'work', 'eidExtraDays' => 2, 'bridgeDay' => 1, 'devBudget' => 10000, 'devDays' => 5, 'flexMinutes' => 120, 'remote' => 1,
            'ticketCap' => 8000, 'ticketFamily' => 1, 'bonusMaxMonths' => 5, 'hotelCap' => 600, 'perDiemCeo' => 500, 'perDiemDir' => 350, 'perDiemMgr' => 300, 'perDiemSpec' => 250, 'perDiemStaff' => 150,
            'comm' => 500, 'commMax' => 800, 'carAllowance2' => 600, 'carAllowance4' => 800, 'carAllowanceDaily' => 1000,
            'insuranceClass' => 'C', 'insuranceMgrs' => 'B', 'insuranceLeaders' => 'A', 'insuranceCeo' => 'A+',
        ]],
    ];

    /** The group's eight companies: official names, brand colours (from the logos) and logo files shipped with the seeders. */
    private const COMPANIES = [
        'IVC' => ['name' => 'وادي الأثر المالية', 'name_en' => 'Impact Valley Capital', 'color' => '#1A2363', 'color2' => '#4FCAF2', 'logo' => 'ivc.png'],
        'IMPACTB' => ['name' => 'استديو صناع الأثر', 'name_en' => 'Impact Venture Studio', 'color' => '#0E9FB8', 'color2' => '#22A94B', 'logo' => 'ivs.png'],
        'PFEL' => ['name' => 'الزمالات المهنية', 'name_en' => 'Professional Fellowships', 'color' => '#3B6B6E', 'color2' => '#DDA84C', 'logo' => 'pfel.png'],
        'PTRIPS' => ['name' => 'الرحلات المهنية', 'name_en' => 'Professional Trips', 'color' => '#D9485C', 'color2' => '#F28A55', 'logo' => 'ptrips.png'],
        'IRTH' => ['name' => 'إرث الحرف', 'name_en' => 'Irth Craft', 'color' => '#0B2240', 'color2' => '#8E8CD8', 'logo' => 'irth.png'],
        'AMAD' => ['name' => 'أمد الحرف', 'name_en' => 'Amad Craft', 'color' => '#0C2340', 'color2' => '#5B7BA8', 'logo' => 'amad.png'],
        'CFAC' => ['name' => 'المرافق المجتمعية', 'name_en' => 'Community Facilities', 'color' => '#3A5461', 'color2' => '#D9A23B', 'logo' => 'cfac.png'],
        'PEMP' => ['name' => 'المشاركة والتمكين', 'name_en' => 'Participation & Empowerment', 'color' => '#2F5A5C', 'color2' => '#E8652A', 'logo' => 'pemp.png'],
    ];

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('color', 9)->nullable()->after('logo_path');   // primary brand colour (#RRGGBB)
            $table->string('color2', 9)->nullable()->after('color');      // accent colour
        });
        Schema::table('tiers', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id'); // tasis | tamkeen | athar — stable keys for the policy tiers
        });

        // 1) the three classification tiers of the policy (created or refreshed by code)
        $ids = [];
        foreach (self::TIERS as $t) {
            $tier = Tier::where('code', $t['code'])->first() ?? Tier::where('name', $t['name'])->first();
            $benefits = array_merge(self::COMMON, $t['benefits']);
            if ($tier) {
                $tier->update(['code' => $t['code'], 'rank' => $t['rank'], 'name' => $t['name'], 'name_en' => $t['name_en'], 'color' => $t['color'], 'is_default' => $t['is_default'],
                    'benefits' => array_merge($tier->benefits ?? [], $benefits), 'version' => $tier->version + 1, 'effective_from' => now()->toDateString()]);
            } else {
                $tier = Tier::create(['code' => $t['code'], 'rank' => $t['rank'], 'name' => $t['name'], 'name_en' => $t['name_en'], 'color' => $t['color'], 'is_default' => $t['is_default'],
                    'benefits' => $benefits, 'version' => 1, 'effective_from' => now()->toDateString()]);
            }
            $ids[$t['code']] = $tier->id;
        }
        Tier::whereNotIn('id', $ids)->update(['is_default' => false]);

        // 2) every company starts in tier 1 (§49) unless it is already on one of the three tiers; old per-company packages go away
        Company::whereNotIn('tier_id', $ids)->orWhereNull('tier_id')->update(['tier_id' => $ids['tasis']]);
        Tier::whereNotIn('id', $ids)->delete();

        // 3) names, colours and logos of the group's companies
        Storage::disk('public')->makeDirectory('brand');
        foreach (self::COMPANIES as $code => $c) {
            $company = Company::where('code', $code)->first();
            if (! $company) {
                continue;
            }
            $src = database_path('seeders/brand/'.$c['logo']);
            $logo = $company->logo_path;
            if (is_file($src)) {
                $logo = 'brand/'.strtolower($code).'.png';
                Storage::disk('public')->put($logo, file_get_contents($src));
            }
            $company->update(['name' => $c['name'], 'name_en' => $c['name_en'], 'color' => $c['color'], 'color2' => $c['color2'], 'logo_path' => $logo]);
        }
        \App\Services\Audit::log('tiers', 0, 'policy_v2_tiers', null, array_keys($ids), 'اللائحة 2.0 §47–52: فئات التصنيف الثلاث وهوية الشركات');
    }

    public function down(): void
    {
        Schema::table('companies', fn (Blueprint $t) => $t->dropColumn(['color', 'color2']));
        Schema::table('tiers', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
