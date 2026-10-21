import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:provider/provider.dart';

import 'push.dart';
import 'screens/login_screen.dart';
import 'screens/shell.dart';
import 'screens/splash_screen.dart';
import 'state.dart';
import 'theme.dart';

// Legacy colour names still referenced by a few screens.
const navy = HcColors.navy;
const deep = HcColors.deepBlue;
const cyan = HcColors.cyan;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await Push.init(); // optional — silently disabled until Firebase is configured
  runApp(ChangeNotifierProvider(create: (_) => AppState()..boot(), child: const HcApp()));
}

class HcApp extends StatelessWidget {
  const HcApp({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.watch<AppState>();
    return MaterialApp(
      title: 'HC',
      debugShowCheckedModeBanner: false,
      theme: hcTheme(Brightness.light, brand: s.brandColor),
      darkTheme: hcTheme(Brightness.dark, brand: s.brandColor),
      themeMode: s.dark ? ThemeMode.dark : ThemeMode.light,
      locale: Locale(s.locale),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: AnimatedSwitcher(
        duration: const Duration(milliseconds: 250),
        switchInCurve: Curves.easeOut,
        child: !s.ready
            ? const SplashScreen()
            : s.user == null
                ? const LoginScreen()
                : s.locked
                    ? const LoginScreen(lockMode: true)
                    : const Shell(),
      ),
    );
  }
}
