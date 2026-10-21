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
                  child: Image.asset('assets/logo-mark.png', width: 96, height: 96, errorBuilder: (_, __, ___) => const Icon(Icons.business, size: 72, color: navy)),
                ),
                const SizedBox(height: 22),
                const Text('HC', style: TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w900, letterSpacing: 2)),
                const SizedBox(height: 6),
                const Text('منصة إدارة رأس المال البشري', style: TextStyle(color: Colors.white70, fontSize: 14)),
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
