import 'package:flutter/material.dart';

import '../main.dart';

/// Branded splash shown while the session and metadata load.
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});
  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..forward();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [navy, deep])),
        child: Center(
          child: FadeTransition(
            opacity: CurvedAnimation(parent: _c, curve: Curves.easeOut),
            child: ScaleTransition(
              scale: Tween(begin: .85, end: 1.0).animate(CurvedAnimation(parent: _c, curve: Curves.easeOutBack)),
              child: Column(mainAxisSize: MainAxisSize.min, children: [
                Container(
                  padding: const EdgeInsets.all(22),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28), boxShadow: const [BoxShadow(color: Colors.black26, blurRadius: 30, offset: Offset(0, 12))]),
                  child: Image.asset('assets/logo-hc.png', width: 260, fit: BoxFit.contain, errorBuilder: (_, __, ___) => const Icon(Icons.business, size: 72, color: navy)),
                ),
                const SizedBox(height: 30),
                const SizedBox(width: 26, height: 26, child: CircularProgressIndicator(color: cyan, strokeWidth: 2.5)),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}
