import 'package:flutter_test/flutter_test.dart';
import 'package:hc_mobile/screens/splash_screen.dart';
import 'package:flutter/material.dart';

void main() {
  testWidgets('splash renders the official HC logo', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: SplashScreen()));
    expect(find.byWidgetPredicate((w) => w is Image && w.image is AssetImage && (w.image as AssetImage).assetName == 'assets/logo-hc.png'), findsOneWidget);
  });
}
