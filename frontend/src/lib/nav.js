// The portal's pages (sidebar), shared by the layout and the permissions editor (pages hidden from a user).
export const ALL = ['super', 'hr_mgr', 'hr_staff', 'ceo', 'mgr', 'fin', 'custodian', 'emp']
export const NAV = [
  { g: 'الرئيسية', items: [
    { to: '/', ic: 'home', label: 'لوحة المعلومات', roles: ALL },
    { to: '/approvals', ic: 'approvals', label: 'صندوق الموافقات', approver: true },
    { to: '/requests', ic: 'requests', label: 'طلباتي', roles: ALL },
    { to: '/me', ic: 'user', label: 'ملفي', roles: ALL },
    { to: '/downloads', ic: 'download', label: 'مركز التنزيل', roles: ALL },
    { to: '/guide', ic: 'info', label: 'دليل الاستخدام', roles: ALL },
    { to: '/my-policy', ic: 'shield', label: 'لائحتي وإقراري', roles: ALL },
    { to: '/privacy', ic: 'eye', label: 'إشعار الخصوصية', roles: ALL },
  ] },
  { g: 'التواصل', items: [
    { to: '/notifications', ic: 'bell', label: 'مركز الإشعارات', roles: ALL },
    { to: '/announcements', ic: 'megaphone', label: 'الإعلانات', roles: ALL },
    { to: '/mail', ic: 'mail', label: 'البريد الداخلي', roles: ALL },
  ] },
  { g: 'الموظفون', items: [
    { to: '/employees', ic: 'users', label: 'الموظفون', approver: true },
    { to: '/documents', ic: 'folder', label: 'الوثائق والتنبيهات', roles: ALL },
    { to: '/letters', ic: 'signature', label: 'الخطابات الرسمية', roles: ALL },
    { to: '/insurance', ic: 'insurance', label: 'التأمين الطبي', roles: ALL },
    { to: '/recruitment', ic: 'user-check', label: 'التوظيف', roles: ['super', 'hr_mgr', 'hr_staff', 'ceo', 'fin', 'mgr'] },
  ] },
  { g: 'التشغيل اليومي', items: [
    { to: '/attendance', ic: 'clock', label: 'الحضور والانصراف', roles: ALL },
    { to: '/leaves', ic: 'leave', label: 'الإجازات', roles: ALL },
  ] },
  { g: 'الأداء والانضباط', items: [
    { to: '/reports', ic: 'report', label: 'التقارير الدورية', roles: ALL },
    { to: '/evaluation', ic: 'chart', label: 'التقييم والأوزان', roles: ALL },
    { to: '/development', ic: 'training', label: 'التطوير والتدريب', roles: ALL },
    { to: '/violations', ic: 'gavel', label: 'المخالفات والانضباط', roles: ALL },
    { to: '/incentives', ic: 'award', label: 'الحوافز والإشادات', roles: ALL },
    { to: '/improvement', ic: 'trend', label: 'خطط تحسين الأداء', roles: ALL },
    { to: '/terminations', ic: 'exit', label: 'إنهاء الخدمة والتصفية', approver: true },
    { to: '/fines-register', ic: 'money', label: 'سجل الغرامات', roles: ['super', 'hr_mgr', 'hr_staff', 'fin', 'ceo'] },
    { to: '/projects', ic: 'kanban', label: 'المشاريع', roles: ALL },
    { to: '/reports-center', ic: 'sheet', label: 'مركز التقارير', approver: true },
  ] },
  { g: 'المالية والعهد', items: [
    { to: '/payroll', ic: 'money', label: 'الرواتب والقسائم', roles: ALL },
    { to: '/custody', ic: 'briefcase', label: 'العهد', roles: ALL },
  ] },
  { g: 'الإدارة', items: [
    { to: '/users', ic: 'shield', label: 'المستخدمون والصلاحيات', roles: ['super', 'hr_mgr'] },
    { to: '/import', ic: 'upload', label: 'الاستيراد', roles: ['super', 'hr_mgr'] },
    { to: '/app-versions', ic: 'download', label: 'إصدارات التطبيق', roles: ['super', 'hr_mgr'] },
    { to: '/companies', ic: 'building', label: 'الشركات والتصنيفات', roles: ['super', 'hr_mgr'] },
    { to: '/lookups', ic: 'database', label: 'البيانات الأساسية', roles: ['super', 'hr_mgr'] },
    { to: '/flows', ic: 'workflow', label: 'أنواع الطلبات ومسارات الاعتماد', roles: ['super', 'hr_mgr'] },
    { to: '/integrations', ic: 'plug', label: 'التكامل مع أودو', roles: ['super', 'hr_mgr'] },
    { to: '/settings', ic: 'settings', label: 'الإعدادات والسياسات', roles: ['super', 'hr_mgr'] },
    { to: '/audit', ic: 'audit', label: 'سجل التدقيق', roles: ['super', 'hr_mgr'] },
    { to: '/policy-acks', ic: 'shield', label: 'إقرارات اللائحة', roles: ['super', 'hr_mgr', 'hr_staff'] },
  ] },
]

// every page with its group, for the per-user page list
export const PAGES = NAV.flatMap((g) => g.items.map((i) => ({ to: i.to, label: i.label, group: g.g })))
