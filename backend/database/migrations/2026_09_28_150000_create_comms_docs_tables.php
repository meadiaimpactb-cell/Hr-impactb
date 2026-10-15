<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Documents & expiry alerts, announcements, internal mail, letters (templates as data), e-signature, insurance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 10);                              // employee | company
            $t->unsignedBigInteger('owner_id');                    // employee_id or company_id
            $t->string('type', 40);                                // lookup document_types
            $t->string('number', 80)->nullable();
            $t->date('issue_date')->nullable();
            $t->date('expiry_date')->nullable();
            $t->string('issuer', 120)->nullable();
            $t->unsignedBigInteger('follow_user_id')->nullable();  // who follows renewal
            $t->string('attachment_path')->nullable();
            $t->boolean('renewed')->default(false);
            $t->json('renewals')->nullable();
            $t->json('alerted')->nullable();                       // thresholds already notified for current expiry
            $t->timestamps();
            $t->index(['scope', 'owner_id']);
            $t->index('expiry_date');
        });

        Schema::create('announcements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sender_id')->constrained('users');
            $t->string('title', 200);
            $t->text('body');
            $t->json('audience');                                  // {scope, companies, jobs, contract, user_ids}
            $t->boolean('require_ack')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->string('status', 12)->default('published');
            $t->string('attachment_path')->nullable();
            $t->timestamps();
        });

        Schema::create('announcement_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('read_at')->nullable();
            $t->timestamp('ack_at')->nullable();
            $t->string('device', 80)->nullable();
            $t->string('reaction', 10)->nullable();
            $t->timestamps();
            $t->unique(['announcement_id', 'user_id']);
        });

        Schema::create('mail_threads', function (Blueprint $t) {
            $t->id();
            $t->string('subject', 200);
            $t->json('participants');                              // user ids
            $t->json('read_by')->nullable();                       // user ids who read the last message
            $t->timestamp('last_at')->nullable();
            $t->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
            $t->foreignId('from_user_id')->constrained('users');
            $t->text('body');
            $t->string('attachment_path')->nullable();
            $t->timestamps();
        });

        Schema::create('letter_templates', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 40);                                // lookup letter_kinds code
            $t->string('lang', 2)->default('ar');
            $t->string('title', 160);
            $t->text('body');                                      // placeholders {{employee}} {{emp_no}} {{company}} {{job}} {{hire_date}} {{gross}} {{to}} {{date}} {{hijri}} {{last_day}}
            $t->boolean('includes_salary')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['kind', 'lang']);
        });

        Schema::create('letters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $t->foreignId('company_id')->constrained();
            $t->string('kind', 40);
            $t->string('to', 160)->nullable();
            $t->string('lang', 2)->default('ar');
            $t->string('no', 30)->unique();
            $t->string('title', 160);
            $t->text('body');
            $t->boolean('includes_salary')->default(false);
            $t->date('issued_at');
            $t->unsignedBigInteger('issued_by')->nullable();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->string('hash', 64)->nullable();
            $t->unsignedBigInteger('signed_doc_id')->nullable();
            $t->timestamps();
        });

        Schema::create('signed_docs', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30);                                // custody_ack | letter | settlement | announcement_ack
            $t->unsignedBigInteger('ref_id');
            $t->string('title', 200);
            $t->string('hash', 64);                                // sha256 of the signed text
            $t->foreignId('signed_by')->constrained('users');
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->text('signature')->nullable();                     // data URL snapshot at signing time
            $t->string('device', 120)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['type', 'ref_id']);
        });

        Schema::table('users', fn (Blueprint $t) => $t->text('signature')->nullable());

        Schema::create('insurance_policies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained();
            $t->string('provider', 120);
            $t->string('policy_no', 80);
            $t->json('classes');                                   // ['A','B','C']
            $t->date('from');
            $t->date('to');
            $t->decimal('cost', 12, 2)->default(0);
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->json('log')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('prefix', 10)->primary();
            $t->unsignedInteger('next')->default(1);
        });

        $now = now();
        $groups = [
            'document_types' => ['أنواع الوثائق', 'Document types', [['key' => 'scope', 'label' => 'النطاق (employee/company)', 'type' => 'text'], ['key' => 'mandatory', 'label' => 'إلزامية', 'type' => 'bool']],
                [['id', 'الهوية / الإقامة', 'ID / Iqama', ['scope' => 'employee', 'mandatory' => true]], ['passport', 'جواز السفر', 'Passport', ['scope' => 'employee', 'mandatory' => false]],
                 ['contract', 'عقد العمل', 'Employment contract', ['scope' => 'employee', 'mandatory' => true]], ['degree', 'الشهادة العلمية', 'Degree certificate', ['scope' => 'employee', 'mandatory' => false]],
                 ['license', 'رخصة القيادة', 'Driving licence', ['scope' => 'employee', 'mandatory' => false]], ['ins_card', 'بطاقة التأمين', 'Insurance card', ['scope' => 'employee', 'mandatory' => false]],
                 ['cr', 'السجل التجاري', 'Commercial registration', ['scope' => 'company', 'mandatory' => true]], ['zakat', 'شهادة الزكاة والضريبة', 'Zakat & tax certificate', ['scope' => 'company', 'mandatory' => true]],
                 ['gosi', 'شهادة التأمينات', 'GOSI certificate', ['scope' => 'company', 'mandatory' => true]], ['baladi', 'رخصة بلدي', 'Municipal licence', ['scope' => 'company', 'mandatory' => true]],
                 ['muqeem', 'مقيم', 'Muqeem', ['scope' => 'company', 'mandatory' => false]], ['chamber', 'الغرفة التجارية', 'Chamber of commerce', ['scope' => 'company', 'mandatory' => false]], ['nitaqat', 'نطاقات', 'Nitaqat', ['scope' => 'company', 'mandatory' => false]]]],
            'insurance_classes' => ['فئات التأمين', 'Insurance classes', [], [['A', 'الفئة A', 'Class A', []], ['B', 'الفئة B', 'Class B', []], ['C', 'الفئة C', 'Class C', []]]],
        ];
        foreach ($groups as $code => [$name, $en, $metaFields, $items]) {
            DB::table('lookup_groups')->updateOrInsert(['code' => $code], ['name' => $name, 'name_en' => $en, 'meta_fields' => json_encode($metaFields, JSON_UNESCAPED_UNICODE), 'builtin' => true, 'created_at' => $now, 'updated_at' => $now]);
            foreach ($items as $i => [$c, $n, $ne, $meta]) {
                DB::table('lookups')->updateOrInsert(['group' => $code, 'code' => $c], ['name' => $n, 'name_en' => $ne, 'meta' => json_encode($meta), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        // letter kinds get stable codes + default templates
        $kinds = [
            ['salary', 'تعريف بالراتب', 'Salary certificate', true, 'إلى من يهمه الأمر،', "تشهد {{company}} بأن السيد/ة {{employee}} (الرقم الوظيفي {{emp_no}}) يعمل لدينا بوظيفة {{job}} منذ {{hire_date}}، ويتقاضى راتبًا شهريًا إجماليًا قدره {{gross}} ريال سعودي.\n\nوقد أُعطي هذا التعريف بناءً على طلبه لتقديمه إلى {{to}} دون أدنى مسؤولية على الشركة تجاه الغير."],
            ['employment', 'تعريف بالعمل بدون راتب', 'Employment certificate', false, 'إلى من يهمه الأمر،', "تشهد {{company}} بأن السيد/ة {{employee}} (الرقم الوظيفي {{emp_no}}) يعمل لدينا بوظيفة {{job}} منذ {{hire_date}} ولا يزال على رأس العمل.\n\nوقد أُعطي هذا التعريف بناءً على طلبه لتقديمه إلى {{to}} دون أدنى مسؤولية على الشركة تجاه الغير."],
            ['entity', 'خطاب موجّه لجهة', 'Letter to an entity', false, 'السادة / {{to}} المحترمين', "تحية طيبة وبعد،\n\nنفيدكم بأن السيد/ة {{employee}} يعمل لدى {{company}} بوظيفة {{job}} منذ {{hire_date}}، ونرجو التكرم بالتعاون معه فيما يخص طلبه لديكم.\n\nوتفضلوا بقبول فائق الاحترام."],
            ['experience', 'شهادة خبرة', 'Experience certificate', false, 'شهادة خبرة', "تشهد {{company}} بأن السيد/ة {{employee}} عمل لدينا بوظيفة {{job}} خلال الفترة من {{hire_date}} حتى {{last_day}}، وقد أبدى خلال عمله كفاءة والتزامًا.\n\nوقد أُعطيت له هذه الشهادة بناءً على طلبه."],
            ['thanks', 'خطاب شكر', 'Letter of appreciation', false, 'خطاب شكر وتقدير', "تتقدم {{company}} بخالص الشكر والتقدير للسيد/ة {{employee}} على ما بذله من جهد متميز في وظيفته {{job}}، متمنين له دوام التوفيق."],
        ];
        DB::table('lookups')->where('group', 'letter_kinds')->delete();
        foreach ($kinds as $i => [$code, $name, $en, $salary, $title, $body]) {
            DB::table('lookups')->updateOrInsert(['group' => 'letter_kinds', 'code' => $code], ['name' => $name, 'name_en' => $en, 'meta' => json_encode([]), 'sort' => ($i + 1) * 10, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('letter_templates')->updateOrInsert(['kind' => $code, 'lang' => 'ar'], ['title' => $title, 'body' => $body, 'includes_salary' => $salary, 'version' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('letter_templates')->updateOrInsert(['kind' => $code, 'lang' => 'en'], ['title' => 'To whom it may concern', 'body' => "This is to certify that {{employee}} (employee no. {{emp_no}}) has been employed by {{company}} as {{job}} since {{hire_date}}".($salary ? ' with a total monthly salary of SAR {{gross}}' : '').".\n\nThis certificate is issued upon the employee's request for submission to {{to}}, without any liability on the company towards third parties.", 'includes_salary' => $salary, 'version' => 1, 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ([['docAlertDays', 'مواعيد تنبيه الوثائق (أيام)', 'الوثائق', 'list'], ['reactionsEnabled', 'تفاعلات الإعلانات', 'التواصل', 'bool'], ['tripFinanceCap', 'سقف الرحلة الذي يستلزم اعتماد المالية', 'الطلبات', 'number']] as $i => [$k, $l, $g, $t]) {
            DB::table('setting_definitions')->updateOrInsert(['key' => $k], ['label' => $l, 'group' => $g, 'type' => $t, 'builtin' => true, 'sort' => 950 + $i, 'created_at' => $now, 'updated_at' => $now]);
        }
        \Illuminate\Support\Facades\Cache::forget('hc.lookups');
    }

    public function down(): void
    {
        foreach (['document_sequences', 'insurance_policies', 'signed_docs', 'letters', 'letter_templates', 'mail_messages', 'mail_threads', 'announcement_receipts', 'announcements', 'documents'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('signature'));
    }
};
