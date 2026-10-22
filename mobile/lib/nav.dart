import 'package:flutter/material.dart';

import 'screens/approvals_screen.dart';
import 'screens/modules_screens.dart';

/// Opens the screen a notification, a push message or a to-do item points at (link.screen from the API).
/// Screens that live in the bottom bar go through [tab] (0 home · 1 attendance · 2 requests · 3 notifications).
void openScreen(BuildContext context, String? screen, {void Function(int)? tab, String title = ''}) {
  final Widget? page = switch (screen) {
    'announcements' => const AnnouncementsScreen(),
    'mail' => const MailScreen(),
    'reports' => title.contains('أسبوع') || title.contains('خطة') ? const WeeklyScreen() : const MonthlyReportScreen(),
    'violations' => const ViolationsScreen(),
    'documents' => const DocumentsScreen(),
    'custody' => const CustodyScreen(),
    'payroll' => const PayslipsScreen(),
    'development' => const DevelopmentScreen(),
    'evaluation' => const EvaluationScreen(),
    'letters' => const LettersScreen(),
    'insurance' => const InsuranceScreen(),
    'approvals' => const ApprovalsScreen(),
    _ => null,
  };
  if (page != null) {
    Navigator.push(context, MaterialPageRoute(builder: (_) => page));
    return;
  }
  tab?.call(switch (screen) { 'attendance' || 'presence' => 1, 'requests' => 2, 'dash' || 'dashboard' || '' || null => 0, _ => 3 });
}
