import { createI18n } from 'vue-i18n'

/**
 * Arabic text is the key (source language). English translations below.
 * Missing keys fall back to the Arabic key itself.
 */
const en = {
  'إدارة رأس المال البشري': 'Human Capital Management', 'مجموعة وادي الأثر المالية': 'Impact Valley Capital Group', 'العودة إلى صفحة التعريف وتحميل التطبيق': 'Back to the overview page and app download',
  'الرئيسية': 'Home', 'التواصل': 'Communication', 'الموظفون': 'Employees', 'التشغيل اليومي': 'Daily operations', 'الإدارة': 'Administration',
  'لوحة المعلومات': 'Dashboard', 'صندوق الموافقات': 'Approvals inbox', 'طلباتي': 'My requests', 'ملفي': 'My profile',
  'مركز الإشعارات': 'Notifications', 'الحضور والانصراف': 'Attendance', 'الإجازات': 'Leaves', 'الشركات والتصنيفات': 'Companies & tiers',
  'الإعدادات والسياسات': 'Settings & policies', 'سجل التدقيق': 'Audit log',
  'حفظ': 'Save', 'إلغاء': 'Cancel', 'إضافة': 'Add', 'تعديل': 'Edit', 'اعتماد': 'Approve', 'إرجاع': 'Return', 'إرسال': 'Submit',
  'إغلاق': 'Close', 'تأكيد': 'Confirm', 'بحث': 'Search', 'تصدير Excel': 'Export Excel', 'تفاصيل': 'Details', 'الملف': 'Profile',
  'الاسم': 'Name', 'الشركة': 'Company', 'الوظيفة': 'Job', 'الحالة': 'Status', 'التاريخ': 'Date', 'النوع': 'Type', 'المبلغ': 'Amount',
  'السبب': 'Reason', 'الموظف': 'Employee', 'المدير المباشر': 'Direct manager', 'الإدارة ': 'Department', 'التعاقد': 'Contract',
  'الالتحاق': 'Hire date', 'الطلب': 'Request', 'مقدّم الطلب': 'Requester', 'المرحلة': 'Stage', 'الانتظار': 'Waiting',
  'تسجيل الدخول': 'Sign in', 'اسم المستخدم أو البريد أو رقم الموظف': 'Username, email or employee no.', 'كلمة المرور': 'Password',
  'دخول': 'Sign in', 'خروج': 'Sign out', 'الحسابات التجريبية': 'Demo accounts', 'كل الشركات': 'All companies',
  'تسجيل حضور': 'Check in', 'تسجيل انصراف': 'Check out', 'يوم مكتمل': 'Day complete', 'طلب إجازة': 'Leave request',
  'طلب جديد': 'New request', 'إجراء سريع': 'Quick action', 'لا توجد بيانات': 'No data', 'بانتظار الاعتماد': 'Pending',
  'معتمد': 'Approved', 'مُرجَع': 'Returned', 'ملغى': 'Cancelled', 'نشط': 'Active', 'قيد الانضمام': 'Onboarding', 'قيد الخروج': 'Exiting',
  'منتهي الخدمة': 'Terminated', 'الوضع الليلي': 'Dark mode', 'الموظفون النشطون': 'Active employees', 'بانتظار اعتمادي': 'Awaiting my approval',
  'الحاضرون اليوم': 'Present today', 'بصمات معلّقة': 'Pending punches', 'يومي': 'My day', 'رصيد الإجازة السنوية': 'Annual leave balance',
  'طلباتي المعلّقة': 'My pending requests', 'حضوري هذا الشهر': 'My attendance this month', 'من في إجازة': 'On leave',
  'عرض الكل': 'View all', 'اعتماد المحدد': 'Approve selected', 'قراراتي السابقة': 'My past decisions', 'بانتظاري': 'Awaiting me',
  'الأرصدة': 'Balances', 'تقويم الفريق': 'Team calendar', 'إجازاتي': 'My leaves', 'الطلبات': 'Requests', 'اليوم': 'Today',
  'ملخص الشهر': 'Month summary', 'معلّق للمراجعة': 'Pending review', 'سجلي': 'My log', 'حاضر': 'Present', 'غياب': 'Absent',
  'متأخر': 'Late', 'إجازة': 'Leave', 'الحضور': 'Check-in', 'الانصراف': 'Check-out', 'التأخير': 'Late', 'الموقع': 'Location',
  'البيانات': 'Info', 'العقد والأجر': 'Contract & pay', 'المسار الوظيفي': 'Career', 'الملف الشامل': '360° profile', 'إضافة موظف': 'Add employee',
  'تعليم الكل كمقروء': 'Mark all as read',
  'المستخدمون والصلاحيات': 'Users & permissions', 'البيانات الأساسية': 'Master data', 'مسارات الاعتماد': 'Approval flows', 'أنواع الطلبات ومسارات الاعتماد': 'Request types & approval flows', 'التكامل مع أودو': 'Odoo integration', 'المالية والعهد': 'Finance & custody', 'الرواتب والقسائم': 'Payroll & payslips', 'العهد': 'Custody', 'القوائم العامة': 'Generic lists', 'الأداء والانضباط': 'Performance & discipline', 'التقارير الدورية': 'Periodic reports', 'التقييم والأوزان': 'Evaluation & weights', 'التطوير والتدريب': 'Development & training', 'المخالفات والانضباط': 'Violations & discipline', 'إنهاء الخدمة والتصفية': 'Termination & settlement', 'الإعلانات': 'Announcements', 'البريد الداخلي': 'Internal mail', 'الوثائق والتنبيهات': 'Documents & alerts', 'الخطابات الرسمية': 'Official letters', 'التأمين الطبي': 'Medical insurance', 'التوظيف': 'Recruitment', 'المشاريع': 'Projects', 'مركز التقارير': 'Reports centre', 'الاستيراد': 'Import', 'اللغة': 'Language', 'لا إشعارات': 'No notifications', 'تغيير كلمة المرور': 'Change password',
}

export const i18n = createI18n({
  legacy: false,
  locale: localStorage.getItem('hc_lang') || 'ar',
  fallbackLocale: 'ar',
  missingWarn: false,
  fallbackWarn: false,
  messages: { ar: {}, en },
})
