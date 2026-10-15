<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Recruitment pipeline, quarterly projects with weekly plans & obstacles, CSV imports with undo, report favourites. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_openings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
            $t->string('title', 160);
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('headcount')->default(1);
            $t->string('status', 10)->default('open');          // open | closed
            $t->boolean('public')->default(false);              // accepts applications from the public endpoint
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('opening_id')->nullable()->constrained('job_openings')->nullOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('job_title_id')->nullable()->constrained('job_titles')->nullOnDelete();
            $t->string('name', 120);
            $t->string('email', 150)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('stage', 20)->default('new');            // lookup recruit_stages
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('notes')->nullable();
            $t->string('cv_path')->nullable();
            $t->json('history')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->string('source', 20)->default('manual');        // manual | public
            $t->timestamps();
            $t->index(['company_id', 'stage']);
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->text('goal')->nullable();
            $t->foreignId('company_id')->constrained();
            $t->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('quarter', 7);                            // YYYY-Qn
            $t->json('members')->nullable();                     // employee ids
            $t->json('weeks');                                   // 12 × {n,start,plan,pct,status,note}
            $t->string('status', 10)->default('active');         // active | done | archived
            $t->timestamps();
        });

        Schema::create('obstacles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->foreignId('owner_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $t->string('severity', 6)->default('med');           // high | med | low
            $t->date('due_date')->nullable();
            $t->string('status', 8)->default('open');            // open | closed
            $t->text('decision')->nullable();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->timestamps();
        });

        Schema::create('imports', function (Blueprint $t) {
            $t->id();
            $t->string('template', 20);
            $t->string('file_name', 200)->nullable();
            $t->unsignedBigInteger('by')->nullable();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('valid')->default(0);
            $t->unsignedInteger('invalid')->default(0);
            $t->json('errors')->nullable();
            $t->json('created')->nullable();                     // [[table, id], …] for undo
            $t->string('status', 10)->default('done');           // done | undone
            $t->timestamps();
        });

        Schema::create('report_favorites', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('key', 30);
            $t->primary(['user_id', 'key']);
        });

        $now = now();
        $g = ['recruit_stages' => ['مراحل التوظيف', 'Recruitment stages', [['key' => 'terminal', 'label' => 'مرحلة نهائية', 'type' => 'bool']],
            [['new', 'جديد', 'New', ['terminal' => false]], ['screening', 'فرز', 'Screening', ['terminal' => false]], ['interview', 'مقابلة', 'Interview', ['terminal' => false]],
             ['offer', 'عرض', 'Offer', ['terminal' => false]], ['accepted', 'مقبول', 'Accepted', ['terminal' => true]], ['rejected', 'مرفوض', 'Rejected', ['terminal' => true]]]]];
        foreach ($g as $code => [$name, $en, $metaFields, $items]) {
            DB::table('lookup_groups')->updateOrInsert(['code' => $code], ['name' => $name, 'name_en' => $en, 'meta_fields' => json_encode($metaFields, JSON_UNESCAPED_UNICODE), 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($items as $i => [$c, $n, $ne, $meta]) {
                DB::table('lookups')->updateOrInsert(['group' => $code, 'code' => $c], ['name' => $n, 'name_en' => $ne, 'meta' => json_encode($meta), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        foreach (['report_favorites', 'imports', 'obstacles', 'projects', 'applications', 'job_openings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
