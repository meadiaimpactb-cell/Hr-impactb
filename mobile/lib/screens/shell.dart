import 'package:flutter/material.dart';

import '../announce.dart';
import '../api.dart';
import '../nav.dart';
import '../presence.dart';
import '../push.dart';
import '../l10n.dart';
import '../theme.dart';
import 'attendance_screen.dart';
import 'home_screen.dart';
import 'more_screen.dart';
import 'notifications_screen.dart';
import 'requests_screen.dart';

class Shell extends StatefulWidget {
  const Shell({super.key});
  @override
  State<Shell> createState() => _ShellState();
}

class _ShellState extends State<Shell> with WidgetsBindingObserver {
  int _i = 0;
  int unread = 0;

  void go(int i) => setState(() => _i = i);

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    refreshUnread();
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      await Presence.check(context);
      if (mounted) Announce.check(context);
    });
    // tapping a push notification opens the screen it is about (announcement, mail, approval, report…)
    Push.onOpen = (data) {
      if (!mounted) return;
      Presence.check(context);
      final screen = '${data['screen'] ?? ''}';
      if (screen == 'announcements') {
        Announce.check(context);
      }
      openScreen(context, screen, tab: go, title: '${data['title'] ?? ''}');
    };
    // a message while the app is open: refresh the badges, pop up an announcement, otherwise a short banner
    Push.onForeground = (data, title, body) {
      if (!mounted) return;
      refreshUnread();
      if (data['screen'] == 'announcements') {
        Announce.check(context);
      } else if (title.isNotEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(body.isEmpty ? title : '$title\n$body', maxLines: 3, overflow: TextOverflow.ellipsis),
          action: SnackBarAction(label: tr('فتح'), onPressed: () => openScreen(context, '${data['screen'] ?? ''}', tab: go, title: title)),
          duration: const Duration(seconds: 6),
        ));
      }
    };
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    Presence.stop();
    super.dispose();
  }

  /// §28: every time the app comes back to the foreground, look for a presence check and refresh the badge.
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      refreshUnread();
      Presence.check(context).then((_) {
        if (mounted) Announce.check(context);
      });
    }
  }

  Future<void> refreshUnread() async {
    try {
      final r = await Api.get('/notifications/unread');
      if (mounted) setState(() => unread = (r['count'] ?? 0) as int);
    } catch (_) {}
  }

  Widget _dest(IconData outline, IconData filled, String label, int idx, {int badge = 0}) {
    final active = _i == idx;
    Widget icon = Icon(active ? filled : outline);
    if (badge > 0) icon = Badge(label: Text('$badge'), backgroundColor: HcColors.bad, child: icon);
    return NavigationDestination(
      icon: Column(mainAxisSize: MainAxisSize.min, children: [
        AnimatedContainer(duration: const Duration(milliseconds: 250), curve: Curves.easeOut, width: active ? 18 : 0, height: 3, margin: const EdgeInsets.only(bottom: 4), decoration: BoxDecoration(color: HcColors.deepBlue, borderRadius: BorderRadius.circular(2))),
        icon,
      ]),
      label: label,
    );
  }

  @override
  Widget build(BuildContext context) {
    final pages = [HomeScreen(onNavigate: go), const AttendanceScreen(), const RequestsScreen(), NotificationsScreen(onChanged: refreshUnread), const MoreScreen()];
    return Scaffold(
      body: SafeArea(bottom: false, child: IndexedStack(index: _i, children: pages)),
      bottomNavigationBar: Container(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: context.line))),
        child: NavigationBar(
          selectedIndex: _i,
          onDestinationSelected: (i) {
            go(i);
            if (i == 3) refreshUnread();
          },
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          destinations: [
            _dest(Icons.home_outlined, Icons.home_rounded, tr('الرئيسية'), 0),
            _dest(Icons.schedule_outlined, Icons.schedule_rounded, tr('حضوري'), 1),
            _dest(Icons.assignment_outlined, Icons.assignment_rounded, tr('طلباتي'), 2),
            _dest(Icons.notifications_outlined, Icons.notifications_rounded, tr('الإشعارات'), 3, badge: unread),
            _dest(Icons.grid_view_outlined, Icons.grid_view_rounded, tr('المزيد'), 4),
          ],
        ),
      ),
    );
  }
}
