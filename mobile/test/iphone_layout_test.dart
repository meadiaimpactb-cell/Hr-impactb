// Layout check on every iPhone screen size (notch / Dynamic Island / home bar insets) against a running API.
// Run: flutter test test/iphone_layout_test.dart --dart-define=API_BASE=http://127.0.0.1:8000/api/v1
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hc_mobile/main.dart';
import 'package:hc_mobile/state.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

class _Device {
  final String name;
  final Size size;
  final EdgeInsets pad;
  final double text;
  const _Device(this.name, this.size, this.pad, [this.text = 1.0]);
}

const _devices = [
  _Device('iPhone SE (1st)', Size(320, 568), EdgeInsets.only(top: 20)),
  _Device('iPhone SE (2nd/3rd)', Size(375, 667), EdgeInsets.only(top: 20)),
  _Device('iPhone 13 mini', Size(375, 812), EdgeInsets.only(top: 50, bottom: 34)),
  _Device('iPhone 14', Size(390, 844), EdgeInsets.only(top: 47, bottom: 34)),
  _Device('iPhone 16', Size(393, 852), EdgeInsets.only(top: 59, bottom: 34)),
  _Device('iPhone 17 Pro', Size(402, 874), EdgeInsets.only(top: 62, bottom: 34)),
  _Device('iPhone Pro Max', Size(440, 956), EdgeInsets.only(top: 62, bottom: 34)),
  _Device('iPhone 14 large text', Size(390, 844), EdgeInsets.only(top: 47, bottom: 34), 1.3),
  _Device('iPad', Size(820, 1180), EdgeInsets.only(top: 24, bottom: 20)),
];

void main() {
  for (final d in _devices) {
    testWidgets('all tabs fit on ${d.name}', (tester) async {
      HttpOverrides.global = null; // real network to the local API
      SharedPreferences.setMockInitialValues({});
      tester.view.physicalSize = d.size * 3;
      tester.view.devicePixelRatio = 3;
      tester.view.padding = FakeViewPadding(top: d.pad.top * 3, bottom: d.pad.bottom * 3);
      tester.view.viewPadding = FakeViewPadding(top: d.pad.top * 3, bottom: d.pad.bottom * 3);
      tester.platformDispatcher.textScaleFactorTestValue = d.text;
      addTearDown(tester.view.reset);
      addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);

      final s = AppState();
      await tester.runAsync(() async {
        await s.login('ahmed', 'hc2026');
        s.ready = true;
      });
      await tester.pumpWidget(ChangeNotifierProvider.value(value: s, child: const HcApp()));
      Future<void> settle() async {
        await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 1500)));
        await tester.pump(const Duration(milliseconds: 400));
      }

      await settle();
      final tabs = find.byType(NavigationDestination);
      expect(tabs, findsWidgets);
      final n = tabs.evaluate().length;
      for (var i = 0; i < n; i++) {
        await tester.tap(find.byType(NavigationDestination).at(i));
        await settle();
        final err = tester.takeException();
        if (err is FlutterError) debugPrint(err.toStringDeep());
        expect(err, isNull, reason: 'tab $i on ${d.name}');
      }
      // let the app's delayed refreshes run out before the tree is torn down
      await tester.pumpWidget(const SizedBox());
      await tester.pump(const Duration(minutes: 10));
    });
  }
}
