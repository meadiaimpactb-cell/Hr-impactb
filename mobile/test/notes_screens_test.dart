// Layout check of the screens added for the owner's notes, on the smallest and largest iPhones, against a running API.
// Run: flutter test test/notes_screens_test.dart --dart-define=API_BASE=http://127.0.0.1:8000/api/v1
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hc_mobile/announce.dart';
import 'package:hc_mobile/api.dart';
import 'package:hc_mobile/screens/announcements_screen.dart';
import 'package:hc_mobile/screens/new_request_screen.dart';
import 'package:hc_mobile/screens/reports_screens.dart';
import 'package:hc_mobile/state.dart';
import 'package:hc_mobile/theme.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

void main() {
  for (final size in const [Size(320, 568), Size(440, 956)]) {
    testWidgets('notes screens fit at ${size.width.toInt()}pt', (tester) async {
      HttpOverrides.global = null;
      SharedPreferences.setMockInitialValues({});
      tester.view.physicalSize = size * 3;
      tester.view.devicePixelRatio = 3;
      addTearDown(tester.view.reset);

      final s = AppState();
      Map<String, dynamic>? week;
      List members = [];
      await tester.runAsync(() async {
        await s.login('ahmed', 'hc2026');
        s.ready = true;
        final weeks = (await Api.get('/reports/weekly/mine')) as List;
        if (weeks.isNotEmpty) {
          week = Map<String, dynamic>.from(weeks.first as Map);
          week!['plan_status'] = null;
          week!['status'] = 'missing';
        }
        final mgr = AppState();
        await mgr.login('majed', 'hc2026');
        members = List.from((await Api.get('/announcements/can-publish'))['team_members'] ?? []);
        await Api.post('/announcements', {'title': 'اختبار النافذة المنبثقة', 'body': 'نص طويل ' * 40, 'popup': true, 'require_ack': size.width < 400, 'audience': {'scope': 'team', 'employee_ids': [8]}});
        await s.login('ahmed', 'hc2026'); // back to the employee's token
      });

      Future<void> show(Widget w, String name) async {
        await tester.pumpWidget(ChangeNotifierProvider.value(value: s, child: MaterialApp(theme: hcTheme(Brightness.light), home: Directionality(textDirection: TextDirection.rtl, child: w))));
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 1500)));
        await tester.pump(const Duration(milliseconds: 300));
        final err = tester.takeException();
        if (err is FlutterError) debugPrint(err.toStringDeep());
        expect(err, isNull, reason: '$name at ${size.width}');
      }

      await show(const WeeklyScreen(), 'weekly list');
      if (week != null) {
        await show(WeekScreen(week: week!), 'week plan');
        await tester.tap(find.byIcon(Icons.add).first);
        await tester.pump();
        expect(tester.takeException(), isNull);
      }
      await show(const MonthlyReportScreen(), 'monthly report');
      await show(const AnnouncementsScreen(), 'announcements');
      await show(TeamAlertScreen(members: members), 'team alert');
      await show(const NewRequestScreen(type: 'leave'), 'leave request');
      // the searchable list opens with its search box
      final pickers = find.byIcon(Icons.arrow_drop_down);
      if (pickers.evaluate().isNotEmpty) {
        await tester.tap(pickers.first);
        await tester.pumpAndSettle(const Duration(milliseconds: 100), EnginePhase.sendSemanticsUpdate, const Duration(seconds: 3));
        expect(find.byIcon(Icons.search), findsOneWidget);
        expect(tester.takeException(), isNull);
        await tester.tapAt(const Offset(10, 10)); // close the sheet
        await tester.pumpAndSettle(const Duration(milliseconds: 100), EnginePhase.sendSemanticsUpdate, const Duration(seconds: 3));
      }
      // the announcement pop-up (read confirmation on the small phone, plain on the large one)
      BuildContext? ctx;
      await tester.pumpWidget(ChangeNotifierProvider.value(value: s, child: MaterialApp(theme: hcTheme(Brightness.light), home: Directionality(textDirection: TextDirection.rtl, child: Scaffold(body: Builder(builder: (c) { ctx = c; return const SizedBox.expand(); }))))));
      await tester.runAsync(() async {
        Announce.check(ctx!).ignore(); // runs on the real event loop so the request completes
        await Future<void>.delayed(const Duration(milliseconds: 1500));
      });
      await tester.pump(const Duration(milliseconds: 400));
      expect(find.byType(AlertDialog), findsOneWidget);
      expect(tester.takeException(), isNull);
      final btn = find.text('أؤكد قراءتي واطلاعي').evaluate().isNotEmpty ? find.text('أؤكد قراءتي واطلاعي') : find.text('تمت القراءة');
      await tester.tap(btn);
      await tester.pump(const Duration(milliseconds: 400));
      expect(tester.takeException(), isNull);
      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(minutes: 10));
    });
  }
}
