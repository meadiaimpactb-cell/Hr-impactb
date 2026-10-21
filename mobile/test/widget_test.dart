import 'package:flutter_test/flutter_test.dart';
import 'package:hc_mobile/screens/splash_screen.dart';
import 'package:flutter/material.dart';

void main() {
  testWidgets('splash renders', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: SplashScreen()));
    expect(find.text('HC'), findsOneWidget);
  });
}
