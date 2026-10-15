<?php

namespace Database\Seeders;

use App\Models\SettingDefinition;
use Illuminate\Database\Seeder;

/** Keeps setting_definitions in step with config/hc.php settingMeta (adds missing keys only; never overwrites UI edits). */
class ConfigSeeder extends Seeder
{
    public function run(): void
    {
        $i = SettingDefinition::max('sort') ?? 0;
        foreach (config('hc.settingMeta', []) as $key => [$label, $group, $type]) {
            if (in_array($key, ['requestTypeLabels', 'reqTypesOff', 'letterKinds', 'correctionKinds'], true)) {
                continue;
            }
            SettingDefinition::firstOrCreate(['key' => $key], ['label' => $label, 'group' => $group, 'type' => $type, 'builtin' => true, 'sort' => $i += 10]);
        }
    }
}
