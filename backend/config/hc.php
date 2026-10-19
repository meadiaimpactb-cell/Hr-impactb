<?php

/*
 * Default labels & lists. Every key here can be overridden at runtime from the
 * settings table (same key) through the Settings screen — no code change needed.
 */
return [
    'requestTypeLabels' => [
        'leave' => 'إجازة', 'advance' => 'سلفة', 'correction' => 'تصحيح حضور', 'allowance' => 'تعديل بدل',
        'benefit' => 'استثناء مزايا', 'trip' => 'رحلة عمل', 'letter' => 'خطاب رسمي', 'balance_adj' => 'تعديل رصيد إجازة',
        'resignation' => 'استقالة', 'salary_change' => 'زيادة راتب',
    ],
    'approvalSlaDays' => 3,
    'letterKinds' => ['تعريف بالراتب', 'تعريف بالعمل بدون راتب', 'خطاب موجّه لجهة', 'شهادة خبرة'],
    'correctionKinds' => ['نسيان تسجيل الحضور', 'نسيان تسجيل الانصراف', 'تسجيل يوم كامل (عمل خارجي)', 'تصحيح وقت الحضور', 'تصحيح وقت الانصراف', 'إلغاء تأخير بعذر'],
    'benefitLabels' => [
        'annualLeave' => 'رصيد الإجازة السنوية', 'sick' => 'الإجازة المرضية', 'emergency' => 'الإجازة الاضطرارية', 'maternity' => 'إجازة الوضع',
        'marriage' => 'إجازة الزواج', 'bereavement' => 'إجازة الوفاة', 'unpaidMax' => 'حد الإجازة بدون راتب', 'carryOverMax' => 'حد الترحيل',
        'housingPct' => 'بدل السكن %', 'transport' => 'بدل النقل', 'comm' => 'بدل الاتصال', 'weeklyHours' => 'ساعات العمل الأسبوعية',
        'workDays' => 'أيام العمل', 'flexMinutes' => 'الساعة المرنة (دقائق)', 'remote' => 'العمل عن بُعد', 'devBudget' => 'ميزانية التطوير',
        'programsPerMonth' => 'البرامج الإلزامية شهريًا', 'insuranceClass' => 'فئة التأمين (التشغيليون والمساندة)', 'dependents' => 'التابعون',
        // policy v2.0 — §39, §52, §59, §63, §66, §67
        'leaveUnit' => 'وحدة رصيد الإجازة (calendar تقويمية / work أيام عمل)', 'eidExtraDays' => 'أيام الشركة الإضافية لكل عيد', 'bridgeDay' => 'يوم الوصل بين إجازتين (1 = نعم)',
        'devDays' => 'أيام التعلم المدفوعة سنويًا', 'ticketCap' => 'سقف تذاكر الإجازة (ريال)', 'ticketFamily' => 'تذاكر الأسرة المستحقة (1 = نعم)', 'bonusMaxMonths' => 'الحد الأعلى للمكافأة السنوية (أشهر)',
        'hotelCap' => 'سقف الفندق لليلة (ريال)', 'perDiemCeo' => 'البدل اليومي — الرئيس التنفيذي', 'perDiemDir' => 'البدل اليومي — مديرو الإدارات', 'perDiemMgr' => 'البدل اليومي — مديرو الأقسام والمشاريع',
        'perDiemSpec' => 'البدل اليومي — الأخصائيون والمنسقون', 'perDiemStaff' => 'البدل اليومي — بقية الموظفين', 'carAllowance2' => 'بدل السيارة الخاصة — مرتان أسبوعيًا', 'carAllowance4' => 'بدل السيارة الخاصة — أربع مرات',
        'carAllowanceDaily' => 'بدل السيارة الخاصة — يوميًا', 'transportPct' => 'بدل المواصلات % من الأساسي', 'transportMin' => 'الحد الأدنى لبدل المواصلات', 'commMax' => 'سقف بدل الاتصال للقيادات',
        'insuranceMgrs' => 'فئة التأمين (مديرو الأقسام والأخصائيون)', 'insuranceLeaders' => 'فئة التأمين (القيادات ومديرو الإدارات)', 'insuranceCeo' => 'فئة التأمين (الرئيس التنفيذي)',
        'newborn' => 'إجازة المولود', 'hajj' => 'إجازة الحج (الحد الأعلى)', 'siblingBereavement' => 'إجازة وفاة الأخ أو الأخت',
    ],
    /*
     * Odoo field mappings (overridable from the Integrations screen → setting "odooMappings").
     * 'odoo_field' => 'hc.dotted.path' | ['const' => …] | ['ref' => entity, 'path' => local_id_path] | ['lines' => path, 'fields' => …]
     * 'match'  : Odoo field used to find an existing record before creating one.
     * 'pull'   : hc_field => odoo_field for importing into HC.
     */
    'odooMappings' => [
        'company' => [
            'model' => 'res.company', 'match' => 'name',
            'fields' => ['name' => 'name', 'vat' => 'tax_no', 'company_registry' => 'legal_no'],
            'pull' => ['name' => 'name', 'tax_no' => 'vat', 'legal_no' => 'company_registry'],
        ],
        'employee' => [
            'model' => 'hr.employee', 'match' => 'barcode',
            'fields' => ['name' => 'name', 'barcode' => 'emp_no', 'work_email' => 'email', 'work_phone' => 'phone', 'job_title' => 'jobTitle.name',
                'identification_id' => 'national_id', 'company_id' => ['ref' => 'company', 'path' => 'company_id'], 'parent_id' => ['ref' => 'employee', 'path' => 'manager_id'],
                'active' => ['const' => true]],
            'pull' => ['name' => 'name', 'emp_no' => 'barcode', 'email' => 'work_email', 'phone' => 'work_phone', 'national_id' => 'identification_id', 'company_name' => 'company_id'],
            'pull_domain' => [['active', '=', true]],
        ],
        'payslip' => [ // posted as a draft journal entry per employee (works without hr_payroll); change model/fields to hr.payslip if installed
            'model' => 'account.move',
            'fields' => ['move_type' => ['const' => 'entry'], 'ref' => 'period', 'date' => 'published_at',
                'company_id' => ['ref' => 'company', 'path' => 'company_id'], 'narration' => 'employee.name'],
        ],
        'advance' => [
            'model' => 'account.move',
            'fields' => ['move_type' => ['const' => 'entry'], 'ref' => 'purpose', 'date' => 'created_at', 'narration' => 'employee.name',
                'company_id' => ['ref' => 'company', 'path' => 'employee.company_id']],
        ],
        'settlement' => [
            'model' => 'account.move',
            'fields' => ['move_type' => ['const' => 'entry'], 'ref' => 'reason', 'date' => 'last_working_day', 'narration' => 'employee.name',
                'company_id' => ['ref' => 'company', 'path' => 'company_id']],
        ],
        'expense_claim' => [
            'model' => 'hr.expense',
            'fields' => ['name' => 'item.ident', 'total_amount' => 'claimed', 'description' => 'details', 'date' => 'decided_at',
                'employee_id' => ['ref' => 'employee', 'path' => 'employee_id'], 'company_id' => ['ref' => 'company', 'path' => 'employee.company_id']],
        ],
    ],

    // CSV import templates: key => [name, headers(field => Arabic header)] — overridable via setting "importTemplates"
    'importTemplates' => [
        'companies' => ['name' => 'الشركات', 'headers' => ['code' => 'رمز الشركة', 'name' => 'الاسم عربي', 'name_en' => 'الاسم إنجليزي', 'legal_no' => 'السجل التجاري', 'tax_no' => 'الرقم الضريبي', 'website' => 'الموقع الإلكتروني', 'country' => 'الدولة', 'currency' => 'العملة', 'timezone' => 'المنطقة الزمنية', 'tier' => 'الباقة', 'odoo_id' => 'معرّف أودو']],
        'jobs' => ['name' => 'الوظائف', 'headers' => ['code' => 'رمز الوظيفة', 'name' => 'المسمى عربي', 'name_en' => 'المسمى إنجليزي', 'family' => 'العائلة الوظيفية', 'purpose' => 'الغرض', 'responsibilities' => 'المسؤوليات', 'qualifications' => 'المؤهلات', 'skills' => 'المهارات'], 'hint' => 'افصل عناصر القوائم بعلامة |'],
        'employees' => ['name' => 'الموظفون', 'headers' => ['emp_no' => 'رقم الموظف', 'name' => 'الاسم عربي', 'name_en' => 'الاسم إنجليزي', 'nationality' => 'الجنسية', 'national_id' => 'رقم الهوية أو الإقامة', 'birth_date' => 'تاريخ الميلاد', 'phone' => 'الجوال', 'email' => 'البريد', 'email_enabled' => 'تفعيل البريد نعم أو لا', 'company' => 'رمز الشركة', 'job' => 'رمز الوظيفة', 'dept' => 'الإدارة', 'hire_date' => 'تاريخ الالتحاق', 'contract' => 'نوع التعاقد', 'country' => 'الدولة', 'location' => 'موقع العمل', 'schedule' => 'جدول الدوام', 'punch' => 'إلزامية التحضير', 'eosb_saudi' => 'تطبيق قواعد نهاية الخدمة السعودية نعم أو لا', 'basic' => 'الأجر الأساسي'], 'hint' => 'نوع التعاقد: full أو part أو collab · السكن والنقل يُحسبان من الباقة'],
        'org' => ['name' => 'الهيكل التنظيمي', 'headers' => ['emp_no' => 'رقم الموظف', 'manager_no' => 'رقم المدير المباشر', 'effective' => 'تاريخ السريان']],
        'components' => ['name' => 'مكونات الأجر', 'headers' => ['emp_no' => 'رقم الموظف', 'key' => 'نوع المكون', 'amount' => 'القيمة', 'fixed' => 'ثابت أو متغير', 'currency' => 'العملة', 'effective' => 'تاريخ السريان']],
        'balances' => ['name' => 'أرصدة الإجازات', 'headers' => ['emp_no' => 'رقم الموظف', 'type' => 'نوع الإجازة', 'cutoff' => 'تاريخ القطع', 'days' => 'الرصيد بالأيام', 'note' => 'ملاحظة']],
        'custody' => ['name' => 'العهد القائمة', 'headers' => ['emp_no' => 'رقم الموظف', 'type' => 'نوع العهدة', 'ident' => 'الوصف', 'serial' => 'الرقم التسلسلي أو رقم الخط', 'package' => 'الباقة', 'value' => 'القيمة', 'handed' => 'تاريخ التسليم', 'status' => 'الحالة']],
        'advances' => ['name' => 'السلف القائمة', 'headers' => ['emp_no' => 'رقم الموظف', 'amount' => 'المبلغ الأصلي', 'installments' => 'عدد الأقساط', 'paid' => 'المسدد', 'remaining' => 'المتبقي', 'start' => 'تاريخ البداية']],
        'empDocs' => ['name' => 'وثائق الموظفين', 'headers' => ['emp_no' => 'رقم الموظف', 'type' => 'نوع الوثيقة', 'number' => 'رقم الوثيقة', 'issue' => 'تاريخ الإصدار', 'expiry' => 'تاريخ الانتهاء', 'issuer' => 'جهة الإصدار']],
        'compDocs' => ['name' => 'وثائق الشركات', 'headers' => ['company' => 'رمز الشركة', 'type' => 'نوع الوثيقة', 'number' => 'رقم الوثيقة', 'issue' => 'تاريخ الإصدار', 'expiry' => 'تاريخ الانتهاء', 'follow' => 'مسؤول المتابعة']],
    ],

    // key => [label, group, type(number|bool|list|json|text|secret)] — seeded into setting_definitions, editable from the UI afterwards
    'settingMeta' => [
        'odooEnabled' => ['تفعيل التكامل مع أودو', 'التكامل', 'bool'],
        'odooUrl' => ['رابط خادم أودو', 'التكامل', 'text'],
        'odooDb' => ['قاعدة بيانات أودو', 'التكامل', 'text'],
        'odooUser' => ['مستخدم أودو', 'التكامل', 'text'],
        'odooApiKey' => ['مفتاح API لأودو', 'التكامل', 'secret'],
        'odooWebhookToken' => ['رمز استقبال تحديثات أودو (Webhook)', 'التكامل', 'secret'],
        'odooSyncEntities' => ['الكيانات المتزامنة مع أودو', 'التكامل', 'list'],
        'odooAutoSync' => ['إرسال تلقائي كل 5 دقائق', 'التكامل', 'bool'],
        'odooMappings' => ['تعيين حقول أودو (يُحرَّر من شاشة التكامل)', 'التكامل', 'json'],
        'roleLabels' => ['أسماء مراحل الاعتماد (الأدوار)', 'الطلبات', 'json'],
        'graceMinutes' => ['حد التأخير المسموح (دقائق)', 'الحضور', 'number'],
        'lateFactor' => ['معامل خصم التأخير', 'الحضور', 'number'],
        'flexMinutes' => ['الساعة المرنة (دقائق)', 'الحضور', 'number'],
        'dailyNoteEnabled' => ['إلزام المنجز اليومي للجميع', 'الحضور', 'bool'],
        'dailyNoteMin' => ['أدنى أحرف المنجز اليومي', 'الحضور', 'number'],
        'approvalSlaDays' => ['مهلة الاعتماد قبل التمييز بالأحمر (أيام)', 'الطلبات', 'number'],
        'maxAbsentPerTeam' => ['أقصى غائبين في الفريق يوميًا', 'الإجازات', 'number'],
        'leaveAdvanceAllowed' => ['السماح برصيد إجازة سالب', 'الإجازات', 'bool'],
        'advanceCapSalaries' => ['سقف السلفة (عدد رواتب)', 'السلف', 'number'],
        'advanceMaxInstallments' => ['الحد الأقصى للأقساط', 'السلف', 'number'],
        'maxDeductionPct' => ['سقف الخصم الشهري % من الأجر', 'الرواتب', 'number'],
        'closeDays' => ['أيام إقفال الشهر', 'التقارير', 'number'],
        'monthlyReportDueDay' => ['يوم استحقاق التقرير الشهري', 'التقارير', 'number'],
        'weeklyReportEnabled' => ['تفعيل التقرير الأسبوعي', 'التقارير', 'bool'],
        'objectionDays' => ['مدة الاعتراض والتظلم (أيام)', 'الانضباط', 'number'],
        'noticeDaysMonthly' => ['مدة الإشعار للأجر الشهري (أيام)', 'إنهاء الخدمة', 'number'],
        'noticeDaysOther' => ['مدة الإشعار لغير الشهري (أيام)', 'إنهاء الخدمة', 'number'],
        'eosbHalfYears' => ['سنوات شريحة نصف الأجر', 'إنهاء الخدمة', 'number'],
        'probationDays' => ['فترة التجربة (أيام)', 'العقود', 'number'],
        'noticeDaysEmployer' => ['مدة إشعار صاحب العمل (أيام)', 'إنهاء الخدمة', 'number'],
        'advanceMinAmount' => ['الحد الأدنى للسلفة (ريال)', 'السلف', 'number'],
        'custodyCashCap' => ['سقف العهدة النقدية الواحدة (ريال)', 'العهد', 'number'],
        'custodySettleDays' => ['مهلة تسوية العهدة النقدية (أيام عمل)', 'العهد', 'number'],
        'custodySettleMaxDays' => ['الحد الأقصى لتسوية العهدة من الصرف (أيام)', 'العهد', 'number'],
        'reportLatePenalty' => ['خصم تأخر التقرير الشهري (درجات)', 'التقارير', 'number'],
        'reportMissingPenalty' => ['خصم عدم رفع التقرير الشهري (درجات)', 'التقارير', 'number'],
        'dailyHours' => ['ساعات العمل اليومية لحساب قيمة الدقيقة', 'الحضور', 'number'],
        'violationStatementDays' => ['مهلة إفادة الموظف على المخالفة (أيام)', 'المخالفات', 'number'],
        'violationObjectionDays' => ['مهلة التظلم من الجزاء (أيام)', 'المخالفات', 'number'],
        'violationChargeDays' => ['أقصى مدة من كشف المخالفة إلى الاتهام (أيام)', 'المخالفات', 'number'],
        'bonusFundingFactor' => ['معامل تمويل وعاء المكافأة السنوية (0–1)', 'المكافآت', 'number'],
        'presenceCheckEnabled' => ['طلب التحضير أثناء الدوام (§28)', 'الحضور', 'bool'],
        'presenceCheckMinutes' => ['مهلة الاستجابة لطلب التحضير (دقيقة)', 'الحضور', 'number'],
        'locationTrackingEnabled' => ['تسجيل الموقع من الحضور حتى الانصراف (§28)', 'الحضور', 'bool'],
        'trackIntervalMinutes' => ['الفاصل بين نقاط تسجيل الموقع (دقيقة)', 'الحضور', 'number'],
        'docAlertDays' => ['مواعيد تنبيه الوثائق (أيام)', 'الوثائق', 'list'],
        'accessLogEnabled' => ['سجل الاطلاع على الملفات', 'الخصوصية', 'bool'],
        'reqTypesOff' => ['أنواع طلبات معطّلة', 'الطلبات', 'list'],
        'letterKinds' => ['أنواع الخطابات', 'الطلبات', 'list'],
        'correctionKinds' => ['أنواع تصحيح الحضور', 'الطلبات', 'list'],
        'requestTypeLabels' => ['أسماء أنواع الطلبات', 'الطلبات', 'json'],
        'benefitLabels' => ['أسماء بنود الباقات', 'الباقات', 'json'],
    ],
];
