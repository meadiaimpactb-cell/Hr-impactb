import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Design tokens — Impact Valley Capital identity (extracted from the logo).
class HcColors {
  static const navy = Color(0xFF262E6B);
  static const purple = Color(0xFF8A7AB6);
  static const periwinkle = Color(0xFF728CC6);
  static const deepBlue = Color(0xFF1C84C6);
  static const cyan = Color(0xFF4FC9F0);
  static const ok = Color(0xFF1F9D6B);
  static const warn = Color(0xFFE0962B);
  static const bad = Color(0xFFD6455D);

  // light
  static const bgLight = Color(0xFFF6F7FB);
  static const cardLight = Color(0xFFFFFFFF);
  static const lineLight = Color(0xFFE8EAF3);
  static const mutedLight = Color(0xFF6B7094);
  // dark (derived from navy)
  static const bgDark = Color(0xFF12163A);
  static const cardDark = Color(0xFF1B2150);
  static const lineDark = Color(0xFF2A3166);
  static const mutedDark = Color(0xFFA3A9CE);
}

/// Spacing scale (4/8 system).
class Sp {
  static const double s4 = 4, s8 = 8, s12 = 12, s16 = 16, s20 = 20, s24 = 24, s32 = 32, s40 = 40;
}

extension HcContext on BuildContext {
  bool get isDark => Theme.of(this).brightness == Brightness.dark;
  Color get card => isDark ? HcColors.cardDark : HcColors.cardLight;
  Color get line => isDark ? HcColors.lineDark : HcColors.lineLight;
  Color get muted => isDark ? HcColors.mutedDark : HcColors.mutedLight;
  Color get text => isDark ? Colors.white : HcColors.navy;
  TextTheme get t => Theme.of(this).textTheme;
}

ThemeData hcTheme(Brightness b, {Color? brand}) {
  final accent = brand ?? HcColors.deepBlue; // the employee's company colour (policy §47), the group blue by default
  final dark = b == Brightness.dark;
  final text = dark ? Colors.white : HcColors.navy;
  final muted = dark ? HcColors.mutedDark : HcColors.mutedLight;
  final base = GoogleFonts.ibmPlexSansArabicTextTheme(ThemeData(brightness: b).textTheme).apply(bodyColor: text, displayColor: text);
  final tt = base.copyWith(
    displayLarge: base.displayLarge?.copyWith(fontSize: 32, height: 40 / 32, fontWeight: FontWeight.w700, fontFeatures: const [FontFeature.tabularFigures()]),
    headlineMedium: base.headlineMedium?.copyWith(fontSize: 24, height: 32 / 24, fontWeight: FontWeight.w700),
    titleLarge: base.titleLarge?.copyWith(fontSize: 18, height: 26 / 18, fontWeight: FontWeight.w600),
    bodyLarge: base.bodyLarge?.copyWith(fontSize: 15, height: 24 / 15, fontWeight: FontWeight.w400),
    bodyMedium: base.bodyMedium?.copyWith(fontSize: 15, height: 24 / 15, fontWeight: FontWeight.w400),
    labelMedium: base.labelMedium?.copyWith(fontSize: 13, height: 18 / 13, fontWeight: FontWeight.w500, color: muted),
    labelSmall: base.labelSmall?.copyWith(fontSize: 11, height: 16 / 11, fontWeight: FontWeight.w600, letterSpacing: .4, color: muted),
  );
  final scheme = ColorScheme(
    brightness: b,
    primary: accent,
    onPrimary: Colors.white,
    secondary: HcColors.cyan,
    onSecondary: HcColors.navy,
    error: HcColors.bad,
    onError: Colors.white,
    surface: dark ? HcColors.cardDark : HcColors.cardLight,
    onSurface: text,
    outline: dark ? HcColors.lineDark : HcColors.lineLight,
  );
  return ThemeData(
    useMaterial3: true,
    brightness: b,
    colorScheme: scheme,
    scaffoldBackgroundColor: dark ? HcColors.bgDark : HcColors.bgLight,
    textTheme: tt,
    dividerColor: scheme.outline,
    appBarTheme: AppBarTheme(backgroundColor: dark ? HcColors.bgDark : HcColors.bgLight, foregroundColor: text, elevation: 0, scrolledUnderElevation: 0, centerTitle: false, titleTextStyle: tt.titleLarge),
    cardTheme: CardThemeData(color: scheme.surface, elevation: 0, margin: EdgeInsets.zero, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16), side: BorderSide(color: scheme.outline))),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: scheme.surface,
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 18),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: scheme.outline)),
      enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: scheme.outline)),
      focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: accent, width: 1.6)),
      errorBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: HcColors.bad)),
      labelStyle: tt.labelMedium,
      floatingLabelBehavior: FloatingLabelBehavior.always,
    ),
    filledButtonTheme: FilledButtonThemeData(style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52), backgroundColor: accent, foregroundColor: Colors.white, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)), textStyle: tt.bodyLarge?.copyWith(fontWeight: FontWeight.w700))),
    outlinedButtonTheme: OutlinedButtonThemeData(style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), foregroundColor: accent, side: BorderSide(color: accent, width: 1.4), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)), textStyle: tt.bodyLarge?.copyWith(fontWeight: FontWeight.w700))),
    textButtonTheme: TextButtonThemeData(style: TextButton.styleFrom(foregroundColor: accent, textStyle: tt.labelMedium?.copyWith(fontWeight: FontWeight.w600))),
    navigationBarTheme: NavigationBarThemeData(
      backgroundColor: scheme.surface,
      indicatorColor: Colors.transparent,
      height: 68,
      labelTextStyle: WidgetStateProperty.resolveWith((s) => tt.labelSmall?.copyWith(color: s.contains(WidgetState.selected) ? accent : muted, fontWeight: FontWeight.w600)),
      iconTheme: WidgetStateProperty.resolveWith((s) => IconThemeData(color: s.contains(WidgetState.selected) ? accent : muted, size: 24)),
    ),
    dividerTheme: DividerThemeData(color: scheme.outline, thickness: 1, space: 1),
    snackBarTheme: SnackBarThemeData(behavior: SnackBarBehavior.floating, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12))),
    bottomSheetTheme: BottomSheetThemeData(backgroundColor: scheme.surface, shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(24))), showDragHandle: true),
    tabBarTheme: TabBarThemeData(labelColor: accent, unselectedLabelColor: muted, indicatorColor: accent, dividerColor: scheme.outline, labelStyle: tt.labelMedium?.copyWith(fontWeight: FontWeight.w700), unselectedLabelStyle: tt.labelMedium),
    switchTheme: SwitchThemeData(thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? Colors.white : null), trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? accent : null)),
  );
}
