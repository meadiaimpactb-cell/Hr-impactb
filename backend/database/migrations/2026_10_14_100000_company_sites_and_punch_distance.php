<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Official company sites for attendance geofencing: a site can carry its address and the Google Maps link it was
 * created from, and be marked as the company's main site. Every punch stores the measured distance to the nearest
 * site (check-in and check-out) so HR sees exactly how far from the company the employee was.
 * Seeds the group's head office (مركز بلال، الرياض) from the link provided by HR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $t) {
            if (! Schema::hasColumn('locations', 'address')) $t->string('address', 200)->nullable()->after('name');
            if (! Schema::hasColumn('locations', 'map_url')) $t->string('map_url', 500)->nullable()->after('address');
            if (! Schema::hasColumn('locations', 'is_main')) $t->boolean('is_main')->default(false)->after('scope');
        });
        Schema::table('attendance_records', function (Blueprint $t) {
            if (! Schema::hasColumn('attendance_records', 'distance_m')) $t->unsignedInteger('distance_m')->nullable()->after('accuracy');
            if (! Schema::hasColumn('attendance_records', 'out_distance_m')) $t->unsignedInteger('out_distance_m')->nullable()->after('out_lng');
            if (! Schema::hasColumn('attendance_records', 'out_geo_fail')) $t->boolean('out_geo_fail')->default(false)->after('out_distance_m');
        });
        if (! DB::table('locations')->where('lat', '>', 24.586)->where('lat', '<', 24.587)->where('lng', '>', 46.596)->where('lng', '<', 46.598)->exists()) {
            DB::table('locations')->insert(['name' => 'المقر الرئيسي — مركز بلال، الرياض', 'address' => 'مركز بلال (معارض ومكاتب)، الرياض', 'map_url' => 'https://maps.app.goo.gl/qLTRswZPZxa1qdbW6',
                'company_id' => null, 'lat' => 24.5865124, 'lng' => 46.5969644, 'radius' => 150, 'scope' => 'point', 'is_main' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn(['address', 'map_url', 'is_main']));
        Schema::table('attendance_records', fn (Blueprint $t) => $t->dropColumn(['distance_m', 'out_distance_m', 'out_geo_fail']));
    }
};
