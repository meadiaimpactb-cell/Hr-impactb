import 'package:flutter/foundation.dart' show defaultTargetPlatform;
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api.dart';
import '../l10n.dart';
import '../state.dart';
import '../theme.dart';
import '../widgets.dart';
import 'approvals_screen.dart';
import 'modules_screens.dart';
import '../app_update.dart';
import 'account_screens.dart';

class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    final e = s.employee;
    void go(Widget w) => Navigator.push(context, MaterialPageRoute(builder: (_) => w));

    return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 24), children: [
      // profile header
      Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [s.brandColor ?? HcColors.navy, s.brandColor2 ?? HcColors.deepBlue]), borderRadius: BorderRadius.circular(16)),
        child: Stack(children: [
          const Positioned(right: -30, top: -30, child: LogoPattern(opacity: .12, size: 150)),
          Row(children: [
            if (s.brandLogo != null) ...[
              // the logo sits on the company colours, so it is shown in white (no white box behind it)
              SizedBox(height: 44, child: ColorFiltered(colorFilter: const ColorFilter.mode(Colors.white, BlendMode.srcIn), child: Image.network(s.brandLogo!, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const SizedBox.shrink()))),
              const SizedBox(width: 10),
            ],
            HcAvatar(s.user?['name'] ?? '?', size: 56, url: s.user?['photo_url'] as String?),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.user?['name'] ?? '', style: context.t.titleLarge?.copyWith(color: Colors.white)),
                Text([s.user?['role_label'], e?['job_title']?['name']].whereType<String>().where((x) => x.isNotEmpty).join(' · '), style: context.t.labelMedium?.copyWith(color: Colors.white70), maxLines: 1, overflow: TextOverflow.ellipsis),
                if (e != null) Text('${e['emp_no'] ?? ''} · ${e['company']?['name'] ?? ''}', style: context.t.labelSmall?.copyWith(color: Colors.white60), maxLines: 1, overflow: TextOverflow.ellipsis),
              ]),
            ),
          ]),
        ]),
      ),
      const SizedBox(height: 20),
      if (s.isApprover)
        ListGroup(title: tr('الإدارة'), children: [
          ListRow(icon: Icons.task_alt_outlined, color: HcColors.warn, title: tr('الاعتمادات'), subtitle: tr('الطلبات التي تنتظر قرارك'), onTap: () => go(const ApprovalsScreen())),
        ]),
      ListGroup(title: tr('الخدمة الذاتية'), children: [
        ListRow(icon: Icons.beach_access_outlined, color: HcColors.periwinkle, title: tr('أرصدة الإجازات'), onTap: () => go(const _BalancesScreen())),
        if (e != null) ...[
          ListRow(icon: Icons.receipt_long_outlined, color: HcColors.ok, title: tr('قسائم الراتب'), onTap: () => go(const PayslipsScreen())),
          ListRow(icon: Icons.inventory_2_outlined, color: HcColors.purple, title: tr('عهدي'), onTap: () => go(const CustodyScreen())),
          ListRow(icon: Icons.folder_open_outlined, color: HcColors.deepBlue, title: tr('وثائقي'), onTap: () => go(const DocumentsScreen())),
          ListRow(icon: Icons.description_outlined, color: HcColors.periwinkle, title: tr('خطاباتي'), onTap: () => go(const LettersScreen())),
          ListRow(icon: Icons.health_and_safety_outlined, color: HcColors.bad, title: tr('التأمين الطبي'), onTap: () => go(const InsuranceScreen())),
        ],
      ]),
      if (e != null)
        ListGroup(title: tr('الأداء'), children: [
          ListRow(icon: Icons.assignment_turned_in_outlined, color: HcColors.deepBlue, title: tr('تقريري الشهري'), onTap: () => go(const MonthlyReportScreen())),
          ListRow(icon: Icons.event_note_outlined, color: HcColors.periwinkle, title: tr('التقرير الأسبوعي'), onTap: () => go(const WeeklyScreen())),
          ListRow(icon: Icons.insights_outlined, color: HcColors.ok, title: tr('تقييماتي'), onTap: () => go(const EvaluationScreen())),
          ListRow(icon: Icons.school_outlined, color: HcColors.purple, title: tr('التطوير والتدريب'), onTap: () => go(const DevelopmentScreen())),
          ListRow(icon: Icons.gavel_outlined, color: HcColors.warn, title: tr('مخالفاتي'), onTap: () => go(const ViolationsScreen())),
        ]),
      ListGroup(title: tr('التواصل'), children: [
        ListRow(icon: Icons.campaign_outlined, color: HcColors.cyan, title: tr('الإعلانات'), subtitle: tr('مقروء وغير مقروء، وتأكيد القراءة'), trailing: const _CountBadge('/announcements/pending-ack', list: true), onTap: () => go(const AnnouncementsScreen())),
        ListRow(icon: Icons.mail_outline, color: HcColors.deepBlue, title: tr('البريد الداخلي'), trailing: const _CountBadge('/mail/unread'), onTap: () => go(const MailScreen())),
        if (s.isApprover) ListRow(icon: Icons.notification_add_outlined, color: HcColors.warn, title: tr('تنبيه لفريقي'), subtitle: tr('يظهر لهم نافذةً منبثقة في الجوال والموقع'), onTap: () => _teamAlert(context)),
      ]),
      ListGroup(title: tr('الإعدادات'), children: [
        ListRow(icon: Icons.language_outlined, color: HcColors.periwinkle, title: tr('اللغة'), subtitle: s.locale == 'ar' ? 'العربية' : 'English', onTap: () => s.setLocale(s.locale == 'ar' ? 'en' : 'ar')),
        ListRow(icon: Icons.dark_mode_outlined, color: HcColors.navy, title: tr('الوضع الداكن'), trailing: Switch(value: s.dark, onChanged: s.setDark), onTap: () => s.setDark(!s.dark)),
        ListRow(icon: Icons.verified_user_outlined, color: HcColors.navy, title: tr('لائحتي وإقراري'), subtitle: tr('قراءة اللائحة وإقرارك بها'), onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const MyPolicyScreen()))),
        ListRow(icon: Icons.lock_reset_outlined, color: HcColors.deepBlue, title: tr('تغيير كلمة المرور'), onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const ChangePasswordScreen()))),
        ListRow(icon: Icons.fingerprint, color: HcColors.ok, title: tr('قفل التطبيق بالبصمة'), subtitle: tr('يُطلب تأكيد هويتك عند فتح التطبيق'), trailing: Switch(value: s.appLock, onChanged: s.setAppLock), onTap: () => s.setAppLock(!s.appLock)),
        // APK self-update is Android-only; iPhone updates come from TestFlight / the App Store
        if (defaultTargetPlatform == TargetPlatform.android) ListRow(icon: Icons.system_update_outlined, color: HcColors.deepBlue, title: tr('تحديث التطبيق'), subtitle: tr('يتحقق من آخر إصدار منشور على المنصة ويثبّته'), onTap: () => AppUpdate.run(context)),
      ]),
      ListGroup(children: [
        ListRow(icon: Icons.logout, color: HcColors.bad, titleColor: HcColors.bad, title: tr('تسجيل الخروج'), trailing: const SizedBox(width: 24), onTap: () => _logout(context, s)),
      ]),
      Center(child: Text('HC · ${tr('مجموعة وادي الأثر المالية')}', style: context.t.labelSmall)),
    ]);
  }

  Future<void> _teamAlert(BuildContext context) async {
    try {
      final p = await Api.get('/announcements/can-publish');
      final members = List.from(p['team_members'] ?? []);
      if (!context.mounted) return;
      if (members.isEmpty) return toast(context, tr('لا يتبعك موظفون لإرسال تنبيه لهم'), error: true);
      Navigator.push(context, MaterialPageRoute(builder: (_) => TeamAlertScreen(members: members)));
    } catch (e) {
      if (context.mounted) toast(context, errText(e), error: true);
    }
  }

  Future<void> _logout(BuildContext context, AppState s) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text(tr('تسجيل الخروج؟'), style: ctx.t.titleLarge),
        content: Text(tr('ستحتاج إلى كلمة المرور للدخول مرة أخرى'), style: ctx.t.labelMedium),
        actions: [TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(tr('تراجع'))), FilledButton(style: FilledButton.styleFrom(backgroundColor: HcColors.bad, minimumSize: const Size(110, 44)), onPressed: () => Navigator.pop(ctx, true), child: Text(tr('خروج')))],
      ),
    );
    if (ok == true) s.logout();
  }
}

class _BalancesScreen extends StatelessWidget {
  const _BalancesScreen();
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(tr('أرصدة الإجازات'))),
      body: FutureBuilder(
        future: Api.get('/leaves/balances'),
        builder: (ctx, snap) {
          if (snap.hasError) return EmptyView(errText(snap.error!));
          if (!snap.hasData) return const Padding(padding: EdgeInsets.all(16), child: SkeletonList());
          final list = (snap.data as Map)['balances'] as List;
          return ListView(padding: const EdgeInsets.all(16), children: [
            for (final b in list)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: HcCard(
                  child: Row(children: [
                    Container(width: 40, height: 40, alignment: Alignment.center, decoration: BoxDecoration(color: HcColors.periwinkle.withValues(alpha: .12), borderRadius: BorderRadius.circular(10)), child: const Icon(Icons.beach_access_outlined, color: HcColors.periwinkle, size: 20)),
                    const SizedBox(width: 12),
                    Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [Text(b['name'] ?? '', style: ctx.t.bodyLarge?.copyWith(fontWeight: FontWeight.w600)), Text('${tr('مستخدم')} ${b['used']} · ${tr('محجوز')} ${b['reserved']}', style: ctx.t.labelMedium)])),
                    Column(crossAxisAlignment: CrossAxisAlignment.end, children: [Text('${b['available']}', style: ctx.t.headlineMedium?.copyWith(color: HcColors.periwinkle)), Text(tr('يوم'), style: ctx.t.labelSmall)]),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

/// Counter next to a menu row (unread mail, announcements waiting), on the company identity colour.
class _CountBadge extends StatelessWidget {
  final String path;
  final bool list;
  const _CountBadge(this.path, {this.list = false});
  @override
  Widget build(BuildContext context) => FutureBuilder(
        future: Api.get(path),
        builder: (ctx, snap) {
          final n = !snap.hasData ? 0 : list ? (snap.data as List).length : ((snap.data as Map)['count'] ?? 0) as int;
          if (n == 0) return Icon(Icons.chevron_left, color: context.muted);
          return CountPill('$n');
        },
      );
}
