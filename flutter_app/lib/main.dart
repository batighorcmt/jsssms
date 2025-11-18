import 'dart:convert';
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:intl/intl.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
// student_mgmt page import removed (not used in simplified dashboard)

// Minimal Notification Service shim
class NotificationService {
  // Navigator key used by MaterialApp to allow navigation from background handlers
  static final GlobalKey<NavigatorState> navigatorKey =
      GlobalKey<NavigatorState>();

  static final StreamController<String> _controller =
      StreamController<String>.broadcast();
  static Stream<String> get notificationStream => _controller.stream;

  // Local notifications plugin and channel for foreground display
  static final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();
  static final AndroidNotificationChannel _channel =
      const AndroidNotificationChannel(
    'jsssms_channel_high',
    'JSSSMS Notifications',
    description: 'Notifications for JSSSMS app',
    importance: Importance.max,
  );

  static void addNotification(String message) {
    _controller.add(message);
  }

  // Initialize any notification-related subsystems. Kept minimal so app won't
  // crash if Firebase or other services are not configured in this project.
  static Future<void> init() async {
    // Try to initialize Firebase & notification subsystems. Use guarded
    // try/catch so the app still runs if platform Firebase files are absent.
    try {
      await Firebase.initializeApp();

      // Initialize local notifications
      const androidInit = AndroidInitializationSettings('@mipmap/ic_launcher');
      const iosInit = DarwinInitializationSettings();
      await _localNotifications.initialize(
        const InitializationSettings(android: androidInit, iOS: iosInit),
      );

      // Create Android channel
      try {
        await _localNotifications
            .resolvePlatformSpecificImplementation<
                AndroidFlutterLocalNotificationsPlugin>()
            ?.createNotificationChannel(_channel);
      } catch (_) {}

      // iOS: allow alerts/sounds in foreground
      try {
        await FirebaseMessaging.instance
            .setForegroundNotificationPresentationOptions(
          alert: true,
          badge: true,
          sound: true,
        );
      } catch (_) {}

      // Register background message handler
      try {
        FirebaseMessaging.onBackgroundMessage(
            _firebaseMessagingBackgroundHandler);
      } catch (_) {}

      // Request push permission (iOS & Android 13+)
      try {
        await FirebaseMessaging.instance.requestPermission(
          alert: true,
          badge: true,
          sound: true,
        );
      } catch (_) {}

      // Obtain FCM token and persist + register with server
      try {
        final token = await FirebaseMessaging.instance.getToken();
        if (token != null && token.isNotEmpty) {
          // Persist token locally and attempt server registration
          final prefs = await SharedPreferences.getInstance();
          await prefs.setString('device_token', token);
          // Helpful debug log (remove for production if desired)
          // ignore: avoid_print
          print('FCM token: $token');
          try {
            await ApiService.saveDeviceToken(token);
          } catch (_) {}
        }
      } catch (_) {}

      // Foreground messages -> show local notification
      FirebaseMessaging.onMessage.listen((RemoteMessage msg) async {
        try {
          if (msg.notification != null) {
            await _showLocalNotification(msg);
          }
          final ttl = msg.notification?.title ?? 'New notification';
          addNotification(ttl);
          await _recordInbox(
            title: msg.notification?.title,
            body: msg.notification?.body,
            data: msg.data,
          );
        } catch (_) {}
      });

      // Tapped notification when app opened
      FirebaseMessaging.onMessageOpenedApp.listen((RemoteMessage msg) async {
        final ttl = msg.notification?.title ?? 'Opened notification';
        addNotification(ttl);
        await _recordInbox(
          title: msg.notification?.title,
          body: msg.notification?.body,
          data: msg.data,
        );
        // Optionally navigate using navigatorKey when needed
      });
    } catch (_) {
      // If Firebase not configured on this machine, leave shim behavior.
    }
  }

  static Future<void> _showLocalNotification(RemoteMessage msg) async {
    final notif = msg.notification;
    final androidDetails = AndroidNotificationDetails(
      _channel.id,
      _channel.name,
      channelDescription: _channel.description,
      importance: Importance.max,
      priority: Priority.high,
      playSound: true,
      enableVibration: true,
      visibility: NotificationVisibility.public,
    );
    final details = NotificationDetails(
      android: androidDetails,
      iOS: const DarwinNotificationDetails(
        presentAlert: true,
        presentBadge: true,
        presentSound: true,
      ),
    );
    await _localNotifications.show(
      msg.hashCode,
      notif?.title,
      notif?.body,
      details,
      payload: msg.data['payload']?.toString(),
    );
  }

  static const String _inboxKey = 'notifications_inbox_v1';
  static List<Map<String, dynamic>> _inboxCache = [];

  static Future<void> _recordInbox({
    String? title,
    String? body,
    Map<String, dynamic>? data,
  }) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (_inboxCache.isEmpty) {
        final raw = prefs.getString(_inboxKey);
        if (raw != null && raw.isNotEmpty) {
          final list = jsonDecode(raw);
          if (list is List) {
            _inboxCache = list
                .whereType<Map>()
                .map((m) => m.map((k, v) => MapEntry(k.toString(), v)))
                .cast<Map<String, dynamic>>()
                .toList();
          }
        }
      }
      final item = <String, dynamic>{
        'title': title ?? '',
        'body': body ?? '',
        'data': data ?? <String, dynamic>{},
        'at': DateTime.now().toIso8601String(),
      };
      _inboxCache.insert(0, item);
      if (_inboxCache.length > 50) {
        _inboxCache = _inboxCache.sublist(0, 50);
      }
      await prefs.setString(_inboxKey, jsonEncode(_inboxCache));
    } catch (_) {}
  }

  static Future<List<Map<String, dynamic>>> loadInbox() async {
    try {
      if (_inboxCache.isNotEmpty) return _inboxCache;
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_inboxKey);
      if (raw == null || raw.isEmpty) return [];
      final list = jsonDecode(raw);
      if (list is List) {
        _inboxCache = list
            .whereType<Map>()
            .map((m) => m.map((k, v) => MapEntry(k.toString(), v)))
            .cast<Map<String, dynamic>>()
            .toList();
        return _inboxCache;
      }
    } catch (_) {}
    return [];
  }

  static Future<void> clearInbox() async {
    _inboxCache = [];
    try {
      final prefs = await SharedPreferences.getInstance();
      await prefs.remove(_inboxKey);
    } catch (_) {}
  }

  // Attempt to register a saved device token with server if available in prefs.
  // This is a no-op when no token provider (FCM) is configured; keeps callers
  // like LoginScreen safe.
  static Future<void> registerCurrentToken() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      var token = prefs.getString('device_token') ?? '';
      if (token.isEmpty) {
        try {
          token = await FirebaseMessaging.instance.getToken() ?? '';
          if (token.isNotEmpty) {
            await prefs.setString('device_token', token);
          }
        } catch (_) {}
      }
      if (token.isNotEmpty) {
        try {
          await ApiService.saveDeviceToken(token);
        } catch (_) {}
      }
    } catch (_) {}
  }

  // Convenience to navigate to a notification screen if needed
  static void openNotifications() {
    try {
      navigatorKey.currentState?.push(
          MaterialPageRoute(builder: (_) => const NotificationListPage()));
    } catch (_) {}
  }
}

// Placeholder for Notification List Page
class NotificationListPage extends StatefulWidget {
  const NotificationListPage({super.key});
  @override
  State<NotificationListPage> createState() => _NotificationListPageState();
}

class _NotificationListPageState extends State<NotificationListPage> {
  List<Map<String, dynamic>> _items = [];
  late final StreamSubscription _sub;

  @override
  void initState() {
    super.initState();
    _load();
    _sub = NotificationService.notificationStream.listen((_) => _load());
  }

  Future<void> _load() async {
    final list = await NotificationService.loadInbox();
    if (!mounted) return;
    setState(() => _items = list);
  }

  @override
  void dispose() {
    _sub.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          IconButton(
              onPressed: () async {
                await NotificationService.clearInbox();
                if (mounted) setState(() => _items = []);
              },
              tooltip: 'Clear',
              icon: const Icon(Icons.clear_all))
        ],
      ),
      body: _items.isEmpty
          ? const Center(child: Text('No notifications yet.'))
          : ListView.separated(
              itemCount: _items.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (ctx, i) {
                final it = _items[i];
                final title = (it['title'] ?? '').toString();
                final body = (it['body'] ?? '').toString();
                final at = (it['at'] ?? '').toString();
                return ListTile(
                  leading: const Icon(Icons.notifications_active),
                  title: Text(title.isEmpty ? '(No title)' : title),
                  subtitle: Text(body),
                  trailing: Text(
                    _formatTime(at),
                    style: const TextStyle(fontSize: 12, color: Colors.grey),
                  ),
                  onTap: () {
                    // Optionally handle deep-links from it['data']
                  },
                );
              },
            ),
    );
  }

  String _formatTime(String iso) {
    try {
      final dt = DateTime.parse(iso);
      return DateFormat('MMM d, h:mm a').format(dt);
    } catch (_) {
      return '';
    }
  }
}

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await NotificationService.init();
  runApp(const JssApp());
}

// Top-level background message handler. Must be a top-level or static function.
Future<void> _firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  try {
    await Firebase.initializeApp();
  } catch (_) {}

  // Try to show a local notification for background messages
  try {
    final fln = FlutterLocalNotificationsPlugin();
    const channel = AndroidNotificationChannel(
      'jsssms_channel',
      'JSSSMS Notifications',
      description: 'Notifications for JSSSMS app',
      importance: Importance.defaultImportance,
    );
    try {
      await fln
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(channel);
    } catch (_) {}

    await fln.initialize(
      const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher')),
    );

    await fln.show(
      message.hashCode,
      message.notification?.title,
      message.notification?.body,
      NotificationDetails(
          android: AndroidNotificationDetails(channel.id, channel.name,
              channelDescription: channel.description,
              importance: channel.importance)),
      payload: message.data['payload']?.toString(),
    );
  } catch (_) {}
}

class JssApp extends StatelessWidget {
  const JssApp({super.key});
  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      navigatorKey: NotificationService.navigatorKey, // Set the navigatorKey
      debugShowCheckedModeBanner: false,
      title: 'Batighor School Management',
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: Colors.indigo),
        useMaterial3: true,
        fontFamily: 'Segoe UI',
      ),
      home: const SplashGate(),
    );
  }
}

// Missing widget definition added: SplashGate wraps the splash state logic
class SplashGate extends StatefulWidget {
  const SplashGate({super.key});
  @override
  State<SplashGate> createState() => _SplashGateState();
}

/// Splash gate: shows Splash.gif then routes to Login or Dashboard
class _SplashGateState extends State<SplashGate> {
  bool _ready = false;
  String? _token;
  String? _userName;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    // Ensure splash is visible for a short minimum duration so it isn't
    // skipped on fast devices. This also gives SharedPreferences time to
    // initialize and keeps the UX smooth.
    const minSplash = Duration(milliseconds: 1200);
    final start = DateTime.now();
    // Precache key assets to reduce first-frame jank
    try {
      await precacheImage(
          const AssetImage('assets/images/Splash.gif'), context);
      await precacheImage(const AssetImage('assets/images/icon.png'), context);
      await precacheImage(
          const AssetImage('assets/images/loading.gif'), context);
    } catch (_) {
      // ignore precache failures (asset may be large or missing in dev)
    }

    final prefs = await SharedPreferences.getInstance();
    _token = prefs.getString('token');
    _userName = prefs.getString('user_name');
    final took = DateTime.now().difference(start);
    if (took < minSplash) {
      await Future.delayed(minSplash - took);
    }
    if (mounted) setState(() => _ready = true);
  }

  @override
  Widget build(BuildContext context) {
    if (!_ready) {
      return Scaffold(
        backgroundColor: Colors.white,
        body: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                  height: 160,
                  width: 160,
                  child: Image.asset('assets/images/Splash.gif',
                      fit: BoxFit.contain)),
              const SizedBox(height: 20),
              const Text('Batighor School Management',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.w600)),
            ],
          ),
        ),
      );
    }
    return _token == null
        ? const LoginScreen()
        : DashboardScreen(token: _token!, userName: _userName);
  }
}

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _userCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _busy = false;
  String? _error;
  bool _passObscure = true;
  static const baseUrl = 'https://jss.batighorbd.com/api';

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final url = Uri.parse('$baseUrl/auth/login.php');
      final resp = await http.post(url,
          headers: {'Content-Type': 'application/json'},
          body: jsonEncode(
              {'username': _userCtrl.text.trim(), 'password': _passCtrl.text}));
      final data = jsonDecode(resp.body);
      if (resp.statusCode == 200 && data['success'] == true) {
        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('token', data['data']['token']);
        final user = data['data']['user'] ?? {};
        final userName = (user['name']?.toString().isNotEmpty == true)
            ? user['name'].toString()
            : user['username']?.toString() ?? '';
        await prefs.setString('user_name', userName);
        await prefs.setString(
            'user_username', user['username']?.toString() ?? '');
        final uid = (user['user_id']?.toString().isNotEmpty == true)
            ? user['user_id'].toString()
            : user['id']?.toString() ?? '';
        await prefs.setString('user_id', uid);
        final ic = (user['is_controller'] ?? data['data']['is_controller']);
        if (ic != null) {
          final s = ic.toString().toLowerCase();
          final isCtrl =
              ic == true || ic == 1 || s == '1' || s == 'true' || s == 'yes';
          await prefs.setBool('is_controller', isCtrl);
          await prefs.setString('is_controller_user_id', uid);
        } else {
          await prefs.remove('is_controller');
        }
        final roleRaw = user['role']?.toString() ?? '';
        final role = roleRaw.toLowerCase();
        await prefs.setString('user_role', role);
        const allowedRoles = ['teacher', 'super_admin'];
        if (!allowedRoles.contains(role)) {
          setState(() {
            _busy = false;
            _error = 'Only teachers or super admins can log in';
          });
          return;
        }
        // Super admin treated as controller for duty features
        if (role == 'super_admin') {
          await prefs.setBool('is_controller', true);
          await prefs.setString('is_controller_user_id', uid);
        }
        if (!mounted) return;
        // Ensure device token is registered now that we have auth
        try {
          await NotificationService.registerCurrentToken();
        } catch (_) {}
        Navigator.of(context).pushReplacement(MaterialPageRoute(
            builder: (_) => DashboardScreen(
                  token: data['data']['token'],
                  userName: userName,
                )));
      } else {
        setState(() {
          _error = data['error']?.toString() ?? 'Login failed';
        });
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      setState(() {
        _busy = false;
      });
    }
  }

  @override
  void dispose() {
    _userCtrl.dispose();
    _passCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Container(
        decoration: const BoxDecoration(
          gradient: LinearGradient(
              colors: [Color(0xFF4F46E5), Color(0xFF6366F1), Color(0xFF818CF8)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight),
        ),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 370),
            child: Card(
              elevation: 10,
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(26)),
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Form(
                  key: _formKey,
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    SizedBox(
                        height: 90,
                        width: 90,
                        child: Image.asset('assets/images/icon.png',
                            fit: BoxFit.contain)),
                    const SizedBox(height: 14),
                    Text('Batighor School Management',
                        textAlign: TextAlign.center,
                        style: Theme.of(context)
                            .textTheme
                            .titleMedium
                            ?.copyWith(fontWeight: FontWeight.bold)),
                    const SizedBox(height: 24),
                    TextFormField(
                      controller: _userCtrl,
                      decoration: const InputDecoration(
                          labelText: 'Username',
                          prefixIcon: Icon(Icons.person_outline)),
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _passCtrl,
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          tooltip:
                              _passObscure ? 'Show password' : 'Hide password',
                          icon: Icon(_passObscure
                              ? Icons.visibility_outlined
                              : Icons.visibility_off_outlined),
                          onPressed: () => setState(() {
                            _passObscure = !_passObscure;
                          }),
                        ),
                      ),
                      obscureText: _passObscure,
                      validator: (v) =>
                          v == null || v.isEmpty ? 'Required' : null,
                    ),
                    const SizedBox(height: 20),
                    if (_error != null)
                      Text(_error!, style: const TextStyle(color: Colors.red)),
                    const SizedBox(height: 10),
                    SizedBox(
                        width: double.infinity,
                        child: ElevatedButton(
                          onPressed: _busy ? null : _login,
                          style: ElevatedButton.styleFrom(
                              padding: const EdgeInsets.symmetric(vertical: 14),
                              shape: RoundedRectangleBorder(
                                  borderRadius: BorderRadius.circular(14))),
                          child: _busy
                              ? SizedBox(
                                  height: 36,
                                  width: 36,
                                  child:
                                      Image.asset('assets/images/loading.gif'))
                              : const Text('Login'),
                        )),
                  ]),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class DashboardScreen extends StatefulWidget {
  final String token;
  final String? userName;
  const DashboardScreen({super.key, required this.token, this.userName});
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  String? _userName;
  String? _userUsername; // For mobile number
  String? _userRole;
  String? _userSchool;
  String? _userLocation;
  int _notificationCount = 0;
  bool _isController = false;

  @override
  void initState() {
    super.initState();
    _userName = widget.userName;
    _loadUserInfo();
    _listenForNotifications();
  }

  void _listenForNotifications() {
    NotificationService.notificationStream.listen((_) {
      // For simplicity, just increment. A real app might fetch a count.
      if (mounted) {
        setState(() {
          _notificationCount++;
        });
      }
    });
  }

  Future<void> _loadUserInfo() async {
    final prefs = await SharedPreferences.getInstance();
    if (!mounted) return;

    final role = prefs.getString('user_role');

    setState(() {
      _userName = prefs.getString('user_name') ?? widget.userName;
      _userUsername = prefs.getString('user_username');
      _userRole = role;
      // Static for now as per screenshot
      _userSchool = "JOREPUKURIA SECONDARY SCHOOL";
      _userLocation = "Gangni, Meherpur";
      // Determine controller flag from prefs (support legacy string values)
      bool? prefCtrl;
      try {
        prefCtrl = prefs.getBool('is_controller');
      } catch (_) {
        prefCtrl = null;
      }
      if (prefCtrl == null) {
        // Try legacy string storage
        final s = prefs.getString('is_controller');
        if (s != null) {
          final ls = s.toLowerCase();
          prefCtrl = (ls == '1' || ls == 'true' || ls == 'yes');
        }
      }
      _isController = (prefCtrl ?? false) || (role == 'super_admin');
    });

    // If pref was not present, try server probe (helps existing installs)
    final hadPref = prefs.containsKey('is_controller');
    if (!hadPref) {
      final uid = prefs.getString('user_id') ?? '';
      if (uid.isNotEmpty) {
        try {
          final serverCtrl = await ApiService.isController(uid);
          await prefs.setBool('is_controller', serverCtrl);
          if (mounted)
            setState(() =>
                _isController = serverCtrl || (_userRole == 'super_admin'));
        } catch (_) {
          // ignore network failures; default remains as-is
        }
      }
    }
  }

  Future<void> _logout() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.clear(); // Clear all data on logout
    if (!mounted) return;
    Navigator.of(context).pushAndRemoveUntil(
        MaterialPageRoute(builder: (_) => const LoginScreen()), (r) => false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Column(
          children: [
            _buildHeader(context),
            // year selector removed per request
            Expanded(
              child: _buildGrid(context),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildHeader(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16.0),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('Dashboard',
                  style: TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF00539C))),
              Row(
                children: [
                  Stack(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.notifications_outlined,
                            color: Color(0xFF00539C), size: 28),
                        tooltip: 'View Notifications',
                        onPressed: () {
                          setState(() => _notificationCount = 0);
                          Navigator.of(context).push(
                            MaterialPageRoute(
                                builder: (_) => const NotificationListPage()),
                          );
                        },
                      ),
                      if (_notificationCount > 0)
                        Positioned(
                          right: 8,
                          top: 8,
                          child: Container(
                            padding: const EdgeInsets.all(2),
                            decoration: BoxDecoration(
                              color: Colors.red,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            constraints: const BoxConstraints(
                              minWidth: 16,
                              minHeight: 16,
                            ),
                            child: Text(
                              '$_notificationCount',
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 10,
                              ),
                              textAlign: TextAlign.center,
                            ),
                          ),
                        ),
                    ],
                  ),
                  IconButton(
                    icon: const Icon(Icons.logout,
                        color: Color(0xFF00539C), size: 28),
                    onPressed: _logout,
                    tooltip: 'Logout',
                  ),
                ],
              )
            ],
          ),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: const Color(0xFF3B82F6),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                  color: Colors.blue.withOpacity(0.2),
                  spreadRadius: 2,
                  blurRadius: 8,
                  offset: const Offset(0, 4),
                ),
              ],
            ),
            child: Row(
              children: [
                const CircleAvatar(
                  radius: 30,
                  backgroundColor: Colors.white,
                  child: Icon(Icons.person, size: 40, color: Color(0xFF3B82F6)),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        _userName ?? 'Loading...',
                        style: const TextStyle(
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                            color: Colors.white),
                      ),
                      Text(
                        _userRole?.replaceAll('_', ' ').toUpperCase() ?? '',
                        style: TextStyle(fontSize: 14, color: Colors.blue[100]),
                      ),
                      if (_userUsername != null &&
                          _userUsername!.isNotEmpty) ...[
                        const SizedBox(height: 4),
                        _buildInfoRow(Icons.phone_android, _userUsername!),
                      ],
                      const SizedBox(height: 8),
                      _buildInfoRow(Icons.school_outlined, _userSchool ?? ''),
                      const SizedBox(height: 4),
                      _buildInfoRow(
                          Icons.location_on_outlined, _userLocation ?? ''),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildInfoRow(IconData icon, String text) {
    return Row(
      children: [
        Icon(icon, color: Colors.white.withOpacity(0.8), size: 16),
        const SizedBox(width: 8),
        Expanded(
          child: Text(
            text,
            style: TextStyle(color: Colors.white.withOpacity(0.9)),
            overflow: TextOverflow.ellipsis,
          ),
        ),
      ],
    );
  }

  // Year selector removed per user request.

  Widget _buildGrid(BuildContext context) {
    final items = [
      {
        'title': "Today's Duty",
        'icon': Icons.event_available,
        'color': const Color(0xFFE0F2FE),
        'onTap': () {
          Navigator.push(
              context, MaterialPageRoute(builder: (context) => DutiesScreen()));
        }
      },
      {
        'title': 'Mark Entry',
        'icon': Icons.edit,
        'color': const Color(0xFFEEF2FF),
        'onTap': () {
          Navigator.push(context,
              MaterialPageRoute(builder: (context) => MarksEntryScreen()));
        }
      },
      {
        'title': 'Find Seat',
        'icon': Icons.event_seat,
        'color': const Color(0xFFE0E7FF),
        'onTap': () {
          Navigator.push(context,
              MaterialPageRoute(builder: (context) => SeatPlanScreen()));
        }
      },
    ];

    // Add controller / super_admin only items
    if (_isController || _userRole == 'super_admin') {
      items.addAll([
        {
          'title': 'Room Duty Allocation',
          'icon': Icons.meeting_room,
          'color': const Color(0xFFFFF4E6),
          'onTap': () {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (context) => RoomDutyAllocationScreen()));
          }
        },
        {
          'title': 'Exam Attendance Report',
          'icon': Icons.assignment_turned_in,
          'color': const Color(0xFFE6FFFA),
          'onTap': () {
            Navigator.push(
                context,
                MaterialPageRoute(
                    builder: (context) => AttendanceReportScreen()));
          }
        },
      ]);
    }

    return GridView.builder(
      padding: const EdgeInsets.all(16),
      gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
        crossAxisCount: 2,
        crossAxisSpacing: 16,
        mainAxisSpacing: 16,
        childAspectRatio: 1.1,
      ),
      itemCount: items.length,
      itemBuilder: (context, index) {
        final item = items[index];
        return _buildGridItem(
          item['title'] as String,
          item['icon'] as IconData,
          item['color'] as Color,
          item['onTap'] as VoidCallback,
        );
      },
    );
  }

  Widget _buildGridItem(
      String title, IconData icon, Color color, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        decoration: BoxDecoration(
          color: color,
          borderRadius: BorderRadius.circular(16),
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Icon(icon,
                size: 56, color: const Color(0xFF1E293B).withOpacity(0.8)),
            const SizedBox(height: 12),
            Text(
              title,
              textAlign: TextAlign.center,
              style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w500,
                  color: Color(0xFF1E293B)),
            ),
          ],
        ),
      ),
    );
  }
}

class DutiesScreen extends StatefulWidget {
  const DutiesScreen({super.key});
  @override
  State<DutiesScreen> createState() => _DutiesScreenState();
}

class _DutiesScreenState extends State<DutiesScreen> {
  static const double _bulkBarHeight = 56.0;
  String _bulkNext = 'present'; // next bulk action mode
  List<dynamic> _plans = [];
  List<dynamic> _rooms = [];
  List<dynamic> _students = [];
  String? _selectedPlanId;
  List<String> _examDates = [];
  String? _selectedDate;
  String? _selectedRoomId;
  bool _loadingPlans = true;
  bool _loadingDates = false;
  bool _loadingRooms = false;
  bool _loadingStudents = false;
  bool _bulkSaving = false;
  bool _isController = false; // loaded from preferences
  bool _noDutyMessage =
      false; // show message when teacher selects non-today date

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    final prefs = await SharedPreferences.getInstance();
    _isController = prefs.getBool('is_controller') ?? false;
    await _loadPlans();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
    });
    try {
      // Show plans irrespective of date; rooms/students remain date-scoped
      _plans = await ApiService.getSeatPlans('');
      // Keep selection blank by default; user will choose step-by-step
      _selectedPlanId = null;
      _examDates = [];
      _selectedDate = null;
      _rooms = [];
      _selectedRoomId = null;
      _students.clear();
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load plans: $e')));
    } finally {
      setState(() {
        _loadingPlans = false;
      });
    }
  }

  Future<void> _loadDatesForPlan() async {
    if (_selectedPlanId == null) return;
    setState(() {
      _loadingDates = true;
      _examDates = [];
      _selectedDate = null;
      _rooms = [];
      _selectedRoomId = null;
      _students.clear();
    });
    try {
      final dates = await ApiService.getPlanDates(_selectedPlanId!);
      _examDates = dates;
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load dates: $e')));
    } finally {
      setState(() => _loadingDates = false);
    }
  }

  Future<void> _loadRoomsForTeacher() async {
    if (_selectedPlanId == null || _selectedDate == null) return;
    // Restrict ordinary teachers to today's date only
    final today = DateTime.now().toIso8601String().substring(0, 10);
    if (!_isController && _selectedDate != today) {
      setState(() {
        _rooms = [];
        _selectedRoomId = null;
        _students.clear();
        _loadingRooms = false;
        _loadingStudents = false;
        _noDutyMessage = true;
      });
      return; // do not fetch rooms for other dates
    } else {
      _noDutyMessage = false; // reset if valid date
    }
    setState(() {
      _loadingRooms = true;
      _rooms = [];
      _selectedRoomId = null;
      _students.clear();
    });
    try {
      final allRooms =
          await ApiService.getRooms(_selectedPlanId!, _selectedDate!);
      final dutyMap =
          await ApiService.getDutiesForPlan(_selectedPlanId!, _selectedDate!);
      final prefs = await SharedPreferences.getInstance();
      final userId = prefs.getString('user_id') ?? '';
      // Filter rooms assigned to this teacher only
      _rooms = allRooms.where((r) {
        final roomId = (r['id']).toString();
        return dutyMap[roomId] == userId;
      }).toList();
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load rooms: $e')));
    } finally {
      setState(() => _loadingRooms = false);
    }
  }

  Future<void> _loadStudents() async {
    if (_selectedPlanId == null ||
        _selectedDate == null ||
        _selectedRoomId == null) return;
    setState(() {
      _loadingStudents = true;
      _students = [];
    });
    try {
      // Reuse existing attendance endpoint
      _students = await ApiService.getAttendance(_selectedDate!,
          int.parse(_selectedPlanId!), int.parse(_selectedRoomId!));
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load students: $e')));
    } finally {
      setState(() {
        _loadingStudents = false;
      });
    }
  }

  Map<String, int> _computeStats() {
    int total = _students.length;
    int male = 0, female = 0, present = 0, absent = 0;
    for (final s in _students) {
      final g = (s['gender'] ?? '').toString().toLowerCase();
      if (g == 'male')
        male++;
      else if (g == 'female') female++;
      final st = (s['status'] ?? '').toString();
      if (st == 'present')
        present++;
      else if (st == 'absent') absent++;
    }
    return {
      'total': total,
      'male': male,
      'female': female,
      'present': present,
      'absent': absent
    };
  }

  Map<String, int> _classCounts() {
    final Map<String, int> counts = {};
    for (final s in _students) {
      final cname = (s['class_name'] ?? '').toString();
      if (cname.isEmpty) continue;
      counts[cname] = (counts[cname] ?? 0) + 1;
    }
    return counts;
  }

  Future<void> _markSingle(dynamic student, String status) async {
    final sid = student['student_id'];
    if (sid == null) return;
    setState(() {
      student['status'] = status;
    });
    try {
      await ApiService.submitAttendance(_selectedDate!,
          int.parse(_selectedPlanId!), int.parse(_selectedRoomId!), [
        {'student_id': sid, 'status': status}
      ]);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Save failed: $e')));
    } finally {
      setState(() {});
    }
  }

  Future<void> _bulkMark(String mode) async {
    if (_students.isEmpty) return;
    setState(() {
      _bulkSaving = true;
    });
    for (final s in _students) {
      s['status'] = mode;
    }
    try {
      final entries = _students
          .map((s) => {'student_id': s['student_id'], 'status': mode})
          .toList();
      await ApiService.submitAttendance(_selectedDate!,
          int.parse(_selectedPlanId!), int.parse(_selectedRoomId!), entries);
    } catch (e) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Bulk save failed: $e')));
    } finally {
      setState(() {
        _bulkSaving = false;
        // flip next mode after operation completes
        _bulkNext = mode == 'present' ? 'absent' : 'present';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final stats = _computeStats();
    final classes = _classCounts();
    return Scaffold(
      appBar: AppBar(
        title: const Text("Today's Duties"),
      ),
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildFilters(),
            const SizedBox(height: 8),
            _buildStatsBar(stats, classes),
            const SizedBox(height: 8),
            Expanded(
              child: Stack(
                children: [
                  // Scrollable list with bottom padding so content isn't hidden under the overlay bar
                  Positioned.fill(
                      child: _noDutyMessage
                          ? const Center(
                              child: Text('আজকের জন্য আপনার কোনো দায়িত্ব নেই।'))
                          : _buildStudentsTable()),
                  // Fixed bottom-center bulk toggle bar
                  if (_students.isNotEmpty)
                    Align(
                      alignment: Alignment.bottomCenter,
                      child: SafeArea(
                        top: false,
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 8.0),
                          child: _buildBulkToggleBar(),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
      // No floatingActionButton; bulk actions are provided by the fixed bottom toggle bar
    );
  }

  Widget _buildFilters() {
    return Card(
      elevation: 2,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: [
            // Row 1: Seat Plan full width
            Row(children: [
              Expanded(
                child: _loadingPlans
                    ? const _LoadingBox(label: 'Seat Plan')
                    : DropdownButtonFormField<String>(
                        isExpanded: true,
                        decoration:
                            const InputDecoration(labelText: 'Seat Plan'),
                        value: _selectedPlanId,
                        items: _plans
                            .map((p) => DropdownMenuItem(
                                  value: p['id'].toString(),
                                  child:
                                      Text('${p['plan_name']} (${p['shift']})'),
                                ))
                            .toList(),
                        onChanged: (v) {
                          setState(() {
                            _selectedPlanId = v;
                            _selectedDate = null;
                            _selectedRoomId = null;
                            _examDates = [];
                            _rooms = [];
                            _students.clear();
                          });
                          if (_selectedPlanId != null) _loadDatesForPlan();
                        },
                      ),
              ),
            ]),
            const SizedBox(height: 12),
            // Row 2: Date and Room (2 columns)
            Row(children: [
              Expanded(
                child: _loadingDates
                    ? const _LoadingBox(label: 'Date')
                    : DropdownButtonFormField<String>(
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Date'),
                        value: _selectedDate,
                        items: _examDates
                            .map((d) => DropdownMenuItem(
                                  value: d,
                                  child: Text(() {
                                    try {
                                      return DateFormat('dd-MM-yyyy')
                                          .format(DateTime.parse(d));
                                    } catch (_) {
                                      return d;
                                    }
                                  }()),
                                ))
                            .toList(),
                        onChanged: (v) {
                          setState(() {
                            _selectedDate = v;
                            _selectedRoomId = null;
                            _rooms = [];
                            _students.clear();
                          });
                          if (_selectedDate != null) _loadRoomsForTeacher();
                        },
                      ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: _loadingRooms
                    ? const _LoadingBox(label: 'Room')
                    : DropdownButtonFormField<String>(
                        isExpanded: true,
                        decoration: const InputDecoration(labelText: 'Room'),
                        value: _selectedRoomId,
                        items: _rooms
                            .map((r) => DropdownMenuItem(
                                  value: r['id'].toString(),
                                  child: Text(r['title'] != null &&
                                          r['title'].toString().isNotEmpty
                                      ? '${r['room_no']} - ${r['title']}'
                                      : '${r['room_no']}'),
                                ))
                            .toList(),
                        onChanged: (v) {
                          setState(() {
                            _selectedRoomId = v;
                          });
                          _loadStudents();
                        },
                      ),
              ),
            ]),
          ],
        ),
      ),
    );
  }

  Widget _buildStatsBar(Map<String, int> stats, Map<String, int> classCounts) {
    if (_loadingStudents)
      return const SizedBox(
          height: 48, child: Center(child: CircularProgressIndicator()));
    return SingleChildScrollView(
      scrollDirection: Axis.horizontal,
      child: Row(children: [
        _statChip('Total', stats['total']!, Colors.indigo),
        _statChip('Male', stats['male']!, Colors.blue),
        _statChip('Female', stats['female']!, Colors.pink),
        _statChip('Present', stats['present']!, Colors.green),
        _statChip('Absent', stats['absent']!, Colors.red),
        ...classCounts.entries.map((e) => _classChip(e.key, e.value)),
      ]),
    );
  }

  Widget _statChip(String label, int value, Color color) {
    return Container(
      margin: const EdgeInsets.only(right: 8),
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration:
          BoxDecoration(color: color, borderRadius: BorderRadius.circular(8)),
      child: Row(children: [
        Text(label, style: const TextStyle(color: Colors.white, fontSize: 12)),
        const SizedBox(width: 4),
        Text(value.toString(),
            style: const TextStyle(
                color: Colors.white, fontWeight: FontWeight.bold)),
      ]),
    );
  }

  Widget _classChip(String label, int value) {
    return Container(
      margin: const EdgeInsets.only(right: 6),
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
          color: Colors.grey.shade200,
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: Colors.grey.shade400)),
      child: Row(children: [
        Text(label, style: const TextStyle(fontSize: 12)),
        const SizedBox(width: 4),
        Text(value.toString(),
            style: const TextStyle(fontWeight: FontWeight.bold))
      ]),
    );
  }

  Widget _buildStudentsTable() {
    if (_loadingStudents) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_students.isEmpty) {
      return const Center(child: Text('No students loaded'));
    }
    return ListView.separated(
      padding: EdgeInsets.only(
          bottom: _students.isNotEmpty ? (_bulkBarHeight + 16) : 0),
      itemCount: _students.length,
      separatorBuilder: (_, __) => const Divider(height: 1),
      itemBuilder: (context, index) {
        final s = _students[index];
        final status = (s['status'] ?? '').toString();
        final seatInfo = s['seat'] != null
            ? 'Seat C${s['seat']['col_no']} B${s['seat']['bench_no']}${s['seat']['position']}'
            : '';
        return ListTile(
          dense: true,
          title: Text('${s['roll_no'] ?? ''}  ${s['student_name'] ?? ''}',
              maxLines: 2, overflow: TextOverflow.ellipsis),
          subtitle: Text(
              '${s['class_name'] ?? ''}${seatInfo.isNotEmpty ? ' • $seatInfo' : ''}'),
          trailing: ToggleButtons(
            isSelected: [status == 'present', status == 'absent'],
            constraints: const BoxConstraints(minHeight: 36, minWidth: 64),
            onPressed: (i) {
              _markSingle(s, i == 0 ? 'present' : 'absent');
            },
            borderRadius: BorderRadius.circular(8),
            selectedColor: Colors.white,
            fillColor: status == 'present'
                ? Colors.green
                : (status == 'absent' ? Colors.red : Colors.indigo),
            children: const [Text('Present'), Text('Absent')],
          ),
        );
      },
    );
  }

  Widget _buildBulkToggleBar() {
    final isPresentMode = _bulkNext == 'present';
    final color = isPresentMode ? Colors.green : Colors.redAccent;
    final icon = isPresentMode ? Icons.playlist_add_check : Icons.block;
    final label = isPresentMode ? 'Mark All Present' : 'Mark All Absent';
    return SizedBox(
      height: _bulkBarHeight,
      child: Center(
        child: SizedBox(
          height: 40,
          child: ElevatedButton.icon(
            style: ElevatedButton.styleFrom(
              backgroundColor: color,
              padding: const EdgeInsets.symmetric(horizontal: 22, vertical: 10),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
            ),
            onPressed: _bulkSaving ? null : () => _bulkMark(_bulkNext),
            icon: Icon(icon, color: Colors.white),
            label: _bulkSaving
                ? const Text('Saving...', style: TextStyle(color: Colors.white))
                : Text(label, style: const TextStyle(color: Colors.white)),
          ),
        ),
      ),
    );
  }
}

class _LoadingBox extends StatelessWidget {
  final String label;
  const _LoadingBox({required this.label});
  @override
  Widget build(BuildContext context) {
    return InputDecorator(
      decoration:
          InputDecoration(labelText: label, border: const OutlineInputBorder()),
      child: const SizedBox(
          height: 24,
          child: Center(child: CircularProgressIndicator(strokeWidth: 2))),
    );
  }
}

class MarksEntryScreen extends StatefulWidget {
  @override
  _MarksEntryScreenState createState() => _MarksEntryScreenState();
}

class _MarksEntryScreenState extends State<MarksEntryScreen> {
  List<dynamic> _exams = [];
  String? _selectedExam; // exam id
  String? _selectedExamClassId; // class id derived from exam
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _fetchDropdownData();
  }

  Future<void> _fetchDropdownData() async {
    setState(() {
      _isLoading = true;
    });
    try {
      final exams = await ApiService.getExams();
      setState(() {
        _exams = exams;
        _isLoading = false;
      });
    } catch (e) {
      setState(() {
        _isLoading = false;
      });
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Failed to load data: $e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Marks Entry - Select Criteria'),
      ),
      body: _isLoading
          ? Center(
              child: Image.asset('assets/images/loading.gif',
                  width: 100, height: 100))
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _buildDropdown(
                    hint: 'Select Exam',
                    value: _selectedExam,
                    items: _exams.map((exam) {
                      final label = (exam['label'] ??
                              "${exam['name']} - ${exam['class_name']}")
                          .toString();
                      return DropdownMenuItem(
                        value: exam['id'].toString(),
                        child: Text(label),
                      );
                    }).toList(),
                    onChanged: (value) {
                      setState(() {
                        _selectedExam = value;
                        final selected = _exams.firstWhere(
                            (e) => e['id'].toString() == value,
                            orElse: () => {});
                        _selectedExamClassId = selected['class_id']?.toString();
                      });
                    },
                  ),
                  SizedBox(height: 32),
                  ElevatedButton(
                    onPressed:
                        (_selectedExam != null && _selectedExamClassId != null)
                            ? () {
                                final selected = _exams.firstWhere(
                                    (e) => e['id'].toString() == _selectedExam,
                                    orElse: () => {});
                                final label = selected['label']?.toString();
                                Navigator.push(
                                  context,
                                  MaterialPageRoute(
                                    builder: (context) => SubjectsScreen(
                                      examId: _selectedExam!,
                                      classId: _selectedExamClassId!,
                                      sectionId: '',
                                      examLabel: label,
                                    ),
                                  ),
                                );
                              }
                            : null,
                    child: Text('Fetch Subjects'),
                    style: ElevatedButton.styleFrom(
                      padding: EdgeInsets.symmetric(vertical: 16),
                    ),
                  ),
                ],
              ),
            ),
    );
  }

  Widget _buildDropdown({
    required String hint,
    required String? value,
    required List<DropdownMenuItem<String>> items,
    required ValueChanged<String?> onChanged,
  }) {
    return DropdownButtonFormField<String>(
      decoration: InputDecoration(
        labelText: hint,
        border: OutlineInputBorder(),
      ),
      initialValue: value,
      items: items,
      onChanged: onChanged,
    );
  }
}

class SubjectsScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;
  final String? examLabel;

  SubjectsScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      this.examLabel});

  @override
  _SubjectsScreenState createState() => _SubjectsScreenState();
}

class _SubjectsScreenState extends State<SubjectsScreen> {
  late Future<List<dynamic>> _subjectsFuture;

  @override
  void initState() {
    super.initState();
    _subjectsFuture =
        ApiService.getSubjectsForTeacher(widget.examId, widget.classId);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Select Subject'),
      ),
      body: FutureBuilder<List<dynamic>>(
        future: _subjectsFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 100, height: 100));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData || snapshot.data!.isEmpty) {
            return Center(
                child: Text('No subjects found for the selected criteria.'));
          } else {
            final subjects = snapshot.data!;
            return ListView.builder(
              itemCount: subjects.length,
              itemBuilder: (context, index) {
                final subject = subjects[index];
                return Card(
                  margin:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
                  child: ListTile(
                    title: Text(subject['subject_name']),
                    subtitle: Text('Code: ${subject['subject_code']}'),
                    trailing: Icon(Icons.arrow_forward_ios),
                    onTap: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (context) => StudentListMarksScreen(
                            examId: widget.examId,
                            classId: widget.classId,
                            sectionId: widget.sectionId,
                            subjectId: subject['subject_id'].toString(),
                            subjectName:
                                subject['subject_name']?.toString() ?? '',
                            examLabel: widget.examLabel,
                          ),
                        ),
                      );
                    },
                  ),
                );
              },
            );
          }
        },
      ),
    );
  }
}

class StudentListMarksScreen extends StatefulWidget {
  final String examId;
  final String classId;
  final String sectionId;
  final String subjectId;
  final String? examLabel;
  final String? subjectName;

  StudentListMarksScreen(
      {required this.examId,
      required this.classId,
      required this.sectionId,
      required this.subjectId,
      this.examLabel,
      this.subjectName});

  @override
  _StudentListMarksScreenState createState() => _StudentListMarksScreenState();
}

class _StudentListMarksScreenState extends State<StudentListMarksScreen> {
  late Future<Map<String, dynamic>> _dataFuture;
  final Map<String, TextEditingController> _cqControllers = {};
  final Map<String, TextEditingController> _mcqControllers = {};
  final Map<String, TextEditingController> _prControllers = {};
  Map<String, dynamic> _meta = {};
  final Map<String, bool> _rowSavedOk = {};
  // Debouncers removed; saving now happens on focus loss only
  final Map<String, int> _totals = {}; // cache of totals per student
  bool _showTotals =
      false; // user toggle for total column visibility (default OFF for speed)

  bool _allowMarksEntry() {
    // Try different common keys the API might provide
    final dateStr =
        (_meta['exam_date'] ?? _meta['date'] ?? _meta['examDate'] ?? '')
            .toString()
            .trim();
    if (dateStr.isEmpty) return true; // if not provided, allow by default
    DateTime? examDate;
    try {
      examDate = DateTime.parse(dateStr);
    } catch (_) {
      // Try dd-MM-yyyy
      try {
        examDate = DateFormat('dd-MM-yyyy').parse(dateStr);
      } catch (_) {}
    }
    if (examDate == null) return true;
    final now = DateTime.now();
    // Compare by date-only (ignore time)
    final today = DateTime(now.year, now.month, now.day);
    final exam = DateTime(examDate.year, examDate.month, examDate.day);
    return today.isAfter(exam) || today.isAtSameMomentAs(exam);
  }

  @override
  void initState() {
    super.initState();
    _dataFuture = ApiService.getStudentsForMarking(
        widget.examId, widget.classId, widget.subjectId);
  }

  @override
  void dispose() {
    _cqControllers.values.forEach((c) => c.dispose());
    _mcqControllers.values.forEach((c) => c.dispose());
    _prControllers.values.forEach((c) => c.dispose());
    super.dispose();
  }

  void _recomputeTotal(String studentId) {
    if (!_showTotals) return; // skip computation when totals are hidden
    final cq =
        double.tryParse(_cqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final mcq =
        double.tryParse(_mcqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final pr =
        double.tryParse(_prControllers[studentId]?.text.trim() ?? '') ?? 0;
    _totals[studentId] = (cq + mcq + pr).toInt();
  }

  void _saveSingleStudentFireAndForget(String studentId) {
    final cq =
        double.tryParse(_cqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final mcq =
        double.tryParse(_mcqControllers[studentId]?.text.trim() ?? '') ?? 0;
    final pr =
        double.tryParse(_prControllers[studentId]?.text.trim() ?? '') ?? 0;

    // Fire-and-forget save to keep UI snappy
    ApiService.submitMarks(widget.examId, widget.classId, widget.subjectId, [
      {
        'student_id': int.tryParse(studentId) ?? 0,
        'creative': cq,
        'objective': mcq,
        'practical': pr,
      }
    ]).then((_) {
      if (!mounted) return;
      setState(() {
        _rowSavedOk[studentId] = true;
        _recomputeTotal(
            studentId); // update cached total after save (if visible)
      });
    }).catchError((e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed to save marks: $e')),
      );
    });
  }

  void _validateAndClamp(TextEditingController c, int max, String partLabel) {
    String t = c.text.trim();
    double v = double.tryParse(t) ?? 0;
    if (v < 0) {
      c.text = '0';
      c.selection =
          TextSelection.fromPosition(TextPosition(offset: c.text.length));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Enter $partLabel value between 0 and $max')),
        );
      }
    } else if (v > max) {
      c.text = max.toString();
      c.selection =
          TextSelection.fromPosition(TextPosition(offset: c.text.length));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Enter $partLabel value between 0 and $max')),
        );
      }
    }
  }

  // _partField removed; inline editors are used inside DataTable cells

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Enter Marks'),
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _dataFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(
                child: Image.asset('assets/images/loading.gif',
                    width: 100, height: 100));
          } else if (snapshot.hasError) {
            return Center(child: Text('Error: ${snapshot.error}'));
          } else if (!snapshot.hasData ||
              (snapshot.data!['students'] as List).isEmpty) {
            return Center(child: Text('No students found.'));
          } else {
            final data = snapshot.data!;
            _meta = Map<String, dynamic>.from(data['meta'] ?? {});
            final allowEntry = _allowMarksEntry();
            final students =
                (data['students'] as List).cast<Map<String, dynamic>>();
            final cqMax = (_meta['creativeMax'] ?? 0) as int;
            final mcqMax = (_meta['objectiveMax'] ?? 0) as int;
            final prMax = (_meta['practicalMax'] ?? 0) as int;

            List<DataColumn> cols = [
              DataColumn(label: Center(child: Text('Roll'))),
              DataColumn(label: Center(child: Text('Name'))),
            ];
            if (cqMax > 0)
              cols.add(DataColumn(label: Center(child: Text('CQ/$cqMax'))));
            if (mcqMax > 0)
              cols.add(DataColumn(label: Center(child: Text('MCQ/$mcqMax'))));
            if (prMax > 0)
              cols.add(DataColumn(label: Center(child: Text('PR/$prMax'))));
            if (_showTotals) {
              cols.add(DataColumn(label: Center(child: Text('Total'))));
            }

            final rows = students.map((student) {
              final studentId = student['student_id'].toString();
              _cqControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['creative']?.toString() ?? ''));
              _mcqControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['objective']?.toString() ?? ''));
              _prControllers.putIfAbsent(
                  studentId,
                  () => TextEditingController(
                      text: student['practical']?.toString() ?? ''));

              if (!_totals.containsKey(studentId)) {
                _recomputeTotal(studentId); // initial computation
              }
              final total = _totals[studentId] ?? 0;

              List<DataCell> cells = [
                DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Text('${student['roll_no'] ?? ''}'))),
                DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Text(student['name'] ?? ''))),
              ];
              if (cqMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus && allowEntry) {
                        _validateAndClamp(
                            _cqControllers[studentId]!, cqMax, 'CQ');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _cqControllers[studentId],
                      enabled: allowEntry,
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                    ),
                  ),
                )));
              }
              if (mcqMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus && allowEntry) {
                        _validateAndClamp(
                            _mcqControllers[studentId]!, mcqMax, 'MCQ');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _mcqControllers[studentId],
                      enabled: allowEntry,
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                    ),
                  ),
                )));
              }
              if (prMax > 0) {
                cells.add(DataCell(SizedBox(
                  width: 60,
                  child: Focus(
                    onFocusChange: (hasFocus) {
                      if (!hasFocus && allowEntry) {
                        _validateAndClamp(
                            _prControllers[studentId]!, prMax, 'PR');
                        _saveSingleStudentFireAndForget(studentId);
                      }
                    },
                    child: TextField(
                      controller: _prControllers[studentId],
                      enabled: allowEntry,
                      keyboardType: TextInputType.number,
                      textAlign: TextAlign.center,
                      decoration: const InputDecoration(
                          isDense: true, border: OutlineInputBorder()),
                    ),
                  ),
                )));
              }
              if (_showTotals) {
                cells.add(DataCell(Container(
                    padding: EdgeInsets.zero,
                    child: Center(child: Text(total.toString())))));
              }

              return DataRow(cells: cells);
            }).toList();

            final headerText =
                (widget.examLabel != null && widget.examLabel!.isNotEmpty)
                    ? widget.examLabel!
                    : 'Exam: ${widget.examId}  Class: ${widget.classId}';

            return Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(headerText,
                              style:
                                  const TextStyle(fontWeight: FontWeight.w600)),
                          if ((widget.subjectName ?? '').isNotEmpty)
                            Text('Subject: ${widget.subjectName}',
                                style: const TextStyle(color: Colors.black54)),
                          if (!allowEntry)
                            Padding(
                              padding: const EdgeInsets.only(top: 8.0),
                              child: Row(
                                children: [
                                  const Icon(Icons.lock_clock,
                                      color: Colors.redAccent),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    child: Text(
                                      'Marks entry will open on the exam date.',
                                      style: const TextStyle(
                                          color: Colors.redAccent,
                                          fontWeight: FontWeight.w500),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          Row(
                            children: [
                              const Text('Show Total'),
                              Switch(
                                value: _showTotals,
                                onChanged: (v) => setState(() {
                                  _showTotals = v;
                                }),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
                Expanded(
                  child: SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(minWidth: 20),
                      child: SingleChildScrollView(
                        child: Padding(
                          padding: const EdgeInsets.only(left: 12.0),
                          child: DataTable(
                            columns: cols,
                            rows: rows,
                            // Fix: use MaterialStateProperty instead of non-existent WidgetStateProperty
                            headingRowColor: MaterialStateProperty.resolveWith(
                                (_) => Colors.grey.shade100),
                            columnSpacing: 16,
                            horizontalMargin: 8,
                            headingRowHeight: 40,
                            dataRowMinHeight: 36,
                            dataRowMaxHeight: 44,
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            );
          }
        },
      ),
    );
  }
}

class RoomDutyAllocationScreen extends StatefulWidget {
  @override
  _RoomDutyAllocationScreenState createState() =>
      _RoomDutyAllocationScreenState();
}

class AttendanceReportScreen extends StatefulWidget {
  @override
  State<AttendanceReportScreen> createState() => _AttendanceReportScreenState();
}

class _AttendanceReportScreenState extends State<AttendanceReportScreen> {
  // Scroll controllers to sync header/body and left/right panes
  final ScrollController _hHeader = ScrollController();
  final ScrollController _hBody = ScrollController();
  final ScrollController _vLeft = ScrollController();
  final ScrollController _vBody = ScrollController();
  bool _syncing = false;
  List<dynamic> _plans = [];
  String? _selectedPlanId;
  List<String> _dates = [];
  String? _selectedDate;

  bool _loadingInit = true;
  bool _loadingDates = false;
  bool _loadingReport = false;

  List<dynamic> _rooms = [];
  Map<String, Map<String, int>> _byRoomClassP = {};
  Map<String, Map<String, int>> _byRoomClassA = {};
  Map<String, int> _roomTotalP = {};
  Map<String, int> _roomTotalA = {};
  List<String> _classList = [];

  @override
  void initState() {
    super.initState();
    _loadPlans();
    // Link scroll controllers for synced scrolling
    _hHeader.addListener(() {
      if (_syncing) return;
      _syncing = true;
      _hBody.jumpTo(_hHeader.position.pixels);
      _syncing = false;
    });
    _hBody.addListener(() {
      if (_syncing) return;
      _syncing = true;
      _hHeader.jumpTo(_hBody.position.pixels);
      _syncing = false;
    });
    _vLeft.addListener(() {
      if (_syncing) return;
      _syncing = true;
      if (_vBody.hasClients) _vBody.jumpTo(_vLeft.position.pixels);
      _syncing = false;
    });
    _vBody.addListener(() {
      if (_syncing) return;
      _syncing = true;
      if (_vLeft.hasClients) _vLeft.jumpTo(_vBody.position.pixels);
      _syncing = false;
    });
  }

  @override
  void dispose() {
    _hHeader.dispose();
    _hBody.dispose();
    _vLeft.dispose();
    _vBody.dispose();
    super.dispose();
  }

  Future<void> _loadPlans() async {
    setState(() => _loadingInit = true);
    try {
      final plans = await ApiService.getSeatPlans('');
      if (!mounted) return;
      setState(() {
        _plans = plans;
        _selectedPlanId = null;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Failed to load plans: $e')));
      }
    } finally {
      if (mounted) setState(() => _loadingInit = false);
    }
  }

  Future<void> _loadDates() async {
    final pid = _selectedPlanId;
    if (pid == null) return;
    setState(() {
      _loadingDates = true;
      _dates = [];
      _selectedDate = null;
    });
    try {
      final d = await ApiService.getPlanDates(pid);
      if (!mounted) return;
      setState(() {
        _dates = d;
        _selectedDate = null;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Failed to load dates: $e')));
      }
    } finally {
      if (mounted) setState(() => _loadingDates = false);
    }
  }

  Future<void> _loadReport() async {
    final pid = _selectedPlanId;
    final dt = _selectedDate;
    if (pid == null || dt == null) return;
    setState(() {
      _loadingReport = true;
      _rooms = [];
      _byRoomClassP = {};
      _byRoomClassA = {};
      _roomTotalP = {};
      _roomTotalA = {};
      _classList = [];
    });
    try {
      final rooms = await ApiService.getRooms(pid, dt);
      final classSet = <String>{};
      _rooms = rooms;
      await Future.wait(rooms.map((room) async {
        final roomId = room['id'].toString();
        final roomNo = (room['room_no'] ?? '').toString();
        final list = await ApiService.getAttendanceForReport(
            dt, int.parse(pid), int.parse(roomId));
        int tp = 0, ta = 0;
        final pMap = <String, int>{};
        final aMap = <String, int>{};
        for (final s in list) {
          final cls = (s['class_name'] ?? '-').toString();
          classSet.add(cls);
          final st = (s['status'] ?? '').toString();
          if (st == 'present') {
            pMap[cls] = (pMap[cls] ?? 0) + 1;
            tp++;
          } else if (st == 'absent') {
            aMap[cls] = (aMap[cls] ?? 0) + 1;
            ta++;
          }
        }
        _byRoomClassP[roomNo] = pMap;
        _byRoomClassA[roomNo] = aMap;
        _roomTotalP[roomNo] = tp;
        _roomTotalA[roomNo] = ta;
      }));
      final classes = classSet.toList()
        ..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
      if (!mounted) return;
      setState(() {
        _classList = classes;
      });
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Failed to load report: $e')));
      }
    } finally {
      if (mounted) setState(() => _loadingReport = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Exam Attendance Report')),
      body: Column(
        children: [
          _buildFilters(),
          Expanded(child: _buildReportTable()),
        ],
      ),
    );
  }

  Widget _buildFilters() {
    return Card(
      margin: const EdgeInsets.all(8),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            _loadingInit
                ? const _LoadingBox(label: 'Seat Plan')
                : DropdownButtonFormField<String>(
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Seat Plan'),
                    value: _selectedPlanId,
                    items: _plans
                        .map<DropdownMenuItem<String>>((p) => DropdownMenuItem(
                              value: p['id'].toString(),
                              child: Text('${p['plan_name']} (${p['shift']})'),
                            ))
                        .toList(),
                    onChanged: (v) {
                      setState(() {
                        _selectedPlanId = v;
                        _selectedDate = null;
                      });
                      _loadDates();
                    },
                  ),
            const SizedBox(height: 12),
            _loadingDates
                ? const _LoadingBox(label: 'Date')
                : DropdownButtonFormField<String>(
                    isExpanded: true,
                    decoration: const InputDecoration(labelText: 'Date'),
                    value: _selectedDate,
                    items: _dates
                        .map((d) => DropdownMenuItem(
                              value: d,
                              child: Text(DateFormat('dd/MM/yyyy')
                                  .format(DateTime.parse(d))),
                            ))
                        .toList(),
                    onChanged: (v) {
                      setState(() => _selectedDate = v);
                      _loadReport();
                    },
                  ),
          ],
        ),
      ),
    );
  }

  Widget _buildReportTable() {
    if (_loadingReport) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_rooms.isEmpty || _classList.isEmpty) {
      return const Center(child: Text('Select Seat Plan and Date.'));
    }
    const double leftColWidth = 100;
    const double cellWidth = 64;
    const double headerRowHeight = 32;
    const double header2RowHeight = 28;

    // Compute grand totals by class
    final Map<String, int> totalPByClass = {
      for (final c in _classList)
        c: _rooms.fold<int>(0, (sum, r) {
          final roomNo = (r['room_no'] ?? '').toString();
          return sum + ((_byRoomClassP[roomNo] ?? const {})[c] ?? 0);
        })
    };
    final Map<String, int> totalAByClass = {
      for (final c in _classList)
        c: _rooms.fold<int>(0, (sum, r) {
          final roomNo = (r['room_no'] ?? '').toString();
          return sum + ((_byRoomClassA[roomNo] ?? const {})[c] ?? 0);
        })
    };
    final int grandP = _rooms.fold<int>(0,
        (sum, r) => sum + (_roomTotalP[(r['room_no'] ?? '').toString()] ?? 0));
    final int grandA = _rooms.fold<int>(0,
        (sum, r) => sum + (_roomTotalA[(r['room_no'] ?? '').toString()] ?? 0));

    final double totalHeaderHeight = headerRowHeight + header2RowHeight;

    Widget buildHeaderRight() {
      return SingleChildScrollView(
        controller: _hHeader,
        scrollDirection: Axis.horizontal,
        child: Column(
          children: [
            // Row 1: Class names spanning 2 sub-columns each; Totals spanning 2
            SizedBox(
              height: headerRowHeight,
              child: Row(
                children: [
                  ..._classList.map((c) => Container(
                        alignment: Alignment.center,
                        width: cellWidth * 2,
                        decoration: BoxDecoration(
                          color: Colors.grey.shade100,
                          border: Border(
                            right: BorderSide(color: Colors.grey.shade300),
                            bottom: BorderSide(color: Colors.grey.shade300),
                          ),
                        ),
                        child: Text(c,
                            style:
                                const TextStyle(fontWeight: FontWeight.w600)),
                      )),
                  // Totals header (spanning 2 columns visually)
                  Container(
                    alignment: Alignment.center,
                    width: cellWidth * 2,
                    decoration: BoxDecoration(
                      color: Colors.grey.shade100,
                      border: Border(
                        bottom: BorderSide(color: Colors.grey.shade300),
                      ),
                    ),
                    child: const Text('Totals',
                        style: TextStyle(fontWeight: FontWeight.w600)),
                  ),
                ],
              ),
            ),
            // Row 2: P/A under each class + P/A under totals
            SizedBox(
              height: header2RowHeight,
              child: Row(
                children: [
                  ..._classList.expand((_) => [
                        Container(
                          alignment: Alignment.center,
                          width: cellWidth,
                          decoration: BoxDecoration(
                            color: Colors.grey.shade50,
                            border: Border(
                              right: BorderSide(color: Colors.grey.shade200),
                              bottom: BorderSide(color: Colors.grey.shade300),
                            ),
                          ),
                          child: const Text('P'),
                        ),
                        Container(
                          alignment: Alignment.center,
                          width: cellWidth,
                          decoration: BoxDecoration(
                            color: Colors.grey.shade50,
                            border: Border(
                              right: BorderSide(color: Colors.grey.shade200),
                              bottom: BorderSide(color: Colors.grey.shade300),
                            ),
                          ),
                          child: const Text('A'),
                        ),
                      ]),
                  Container(
                    alignment: Alignment.center,
                    width: cellWidth,
                    decoration: BoxDecoration(
                      color: Colors.grey.shade50,
                      border: Border(
                        right: BorderSide(color: Colors.grey.shade200),
                        bottom: BorderSide(color: Colors.grey.shade300),
                      ),
                    ),
                    child: const Text('P'),
                  ),
                  Container(
                    alignment: Alignment.center,
                    width: cellWidth,
                    decoration: BoxDecoration(
                      color: Colors.grey.shade50,
                      border: Border(
                        bottom: BorderSide(color: Colors.grey.shade300),
                      ),
                    ),
                    child: const Text('A'),
                  ),
                ],
              ),
            ),
          ],
        ),
      );
    }

    Widget buildBodyRight() {
      return SingleChildScrollView(
        controller: _hBody,
        scrollDirection: Axis.horizontal,
        child: SizedBox(
          width: (_classList.length * 2 + 2) * cellWidth,
          child: ListView.builder(
            controller: _vBody,
            itemCount: _rooms.length + 1, // +1 for totals row
            itemBuilder: (context, index) {
              if (index == _rooms.length) {
                // Totals row
                return Container(
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    border:
                        Border(top: BorderSide(color: Colors.grey.shade300)),
                  ),
                  child: Row(
                    children: [
                      ..._classList.expand((c) => [
                            SizedBox(
                              width: cellWidth,
                              height: 36,
                              child: Center(
                                  child: Text(
                                      (totalPByClass[c] ?? 0).toString(),
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w600,
                                          color: Colors.green))),
                            ),
                            SizedBox(
                              width: cellWidth,
                              height: 36,
                              child: Center(
                                  child: Text(
                                      (totalAByClass[c] ?? 0).toString(),
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w600,
                                          color: Colors.red))),
                            ),
                          ]),
                      SizedBox(
                        width: cellWidth,
                        height: 36,
                        child: Center(
                            child: Text(grandP.toString(),
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                    color: Colors.green))),
                      ),
                      SizedBox(
                        width: cellWidth,
                        height: 36,
                        child: Center(
                            child: Text(grandA.toString(),
                                style: const TextStyle(
                                    fontWeight: FontWeight.w700,
                                    color: Colors.red))),
                      ),
                    ],
                  ),
                );
              }
              final room = _rooms[index];
              final roomNo = (room['room_no'] ?? '').toString();
              final pMap = _byRoomClassP[roomNo] ?? const {};
              final aMap = _byRoomClassA[roomNo] ?? const {};
              return Row(
                children: [
                  ..._classList.expand((c) => [
                        SizedBox(
                          width: cellWidth,
                          height: 36,
                          child: Center(
                              child: Text((pMap[c] ?? 0).toString(),
                                  style: const TextStyle(color: Colors.green))),
                        ),
                        SizedBox(
                          width: cellWidth,
                          height: 36,
                          child: Center(
                              child: Text((aMap[c] ?? 0).toString(),
                                  style: const TextStyle(color: Colors.red))),
                        ),
                      ]),
                  SizedBox(
                    width: cellWidth,
                    height: 36,
                    child: Center(
                        child: Text((_roomTotalP[roomNo] ?? 0).toString(),
                            style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                color: Colors.green))),
                  ),
                  SizedBox(
                    width: cellWidth,
                    height: 36,
                    child: Center(
                        child: Text((_roomTotalA[roomNo] ?? 0).toString(),
                            style: const TextStyle(
                                fontWeight: FontWeight.w600,
                                color: Colors.red))),
                  ),
                ],
              );
            },
          ),
        ),
      );
    }

    Widget buildLeftPane() {
      return SizedBox(
        width: leftColWidth,
        child: Column(
          children: [
            // Top-left fixed header cell spanning two header rows
            Container(
              height: totalHeaderHeight,
              alignment: Alignment.centerLeft,
              padding: const EdgeInsets.symmetric(horizontal: 8),
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                border: Border(
                  right: BorderSide(color: Colors.grey.shade300),
                  bottom: BorderSide(color: Colors.grey.shade300),
                ),
              ),
              child: const Text('Room',
                  style: TextStyle(fontWeight: FontWeight.w600)),
            ),
            Expanded(
              child: ListView.builder(
                controller: _vLeft,
                itemCount: _rooms.length + 1, // +1 for totals row
                itemBuilder: (context, index) {
                  if (index == _rooms.length) {
                    return Container(
                      alignment: Alignment.centerLeft,
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      height: 36,
                      color: Colors.grey.shade100,
                      child: const Text('Total',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                    );
                  }
                  final room = _rooms[index];
                  final roomTitle = () {
                    final no = (room['room_no'] ?? '').toString();
                    final title = (room['title'] ?? '').toString();
                    return title.isNotEmpty ? '$no - $title' : no;
                  }();
                  return Container(
                    alignment: Alignment.centerLeft,
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    height: 36,
                    child: Text(roomTitle),
                  );
                },
              ),
            ),
          ],
        ),
      );
    }

    return Padding(
      padding: const EdgeInsets.all(8.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          buildLeftPane(),
          Expanded(
            child: Column(
              children: [
                SizedBox(height: totalHeaderHeight, child: buildHeaderRight()),
                Expanded(child: buildBodyRight()),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _RoomDutyAllocationScreenState extends State<RoomDutyAllocationScreen> {
  List<dynamic> _plans = [];
  String? _selectedPlanId;
  List<String> _examDates = [];
  String? _selectedDate;
  List<dynamic> _rooms = [];
  List<dynamic> _teachers = [];
  Map<String, String> _dutyMap = {}; // room_id -> teacher_user_id

  bool _loadingPlans = true;
  bool _loadingDates = false;
  bool _loadingRoomsAndDuties = false;
  bool _saving = false;
  bool _isController = false;
  String? _userRole;

  @override
  void initState() {
    super.initState();
    _loadInitialData();
  }

  Future<void> _loadInitialData() async {
    setState(() => _loadingPlans = true);
    try {
      final prefs = await SharedPreferences.getInstance();
      _isController = prefs.getBool('is_controller') ?? false;
      _userRole = prefs.getString('user_role');
      final plans = await ApiService.getSeatPlans('');
      final teachers = await ApiService.getTeachers();
      if (mounted) {
        setState(() {
          _plans = plans;
          _teachers = teachers;
          // Keep seat plan field blank initially; don't auto-load dates
          _selectedPlanId = null;
        });
      }
    } catch (e) {
      _showError('Failed to load initial data: $e');
    } finally {
      if (mounted) setState(() => _loadingPlans = false);
    }
  }

  Future<void> _loadDatesForPlan() async {
    if (_selectedPlanId == null) return;
    setState(() {
      _loadingDates = true;
      _examDates = [];
      _selectedDate = null;
      _rooms = [];
      _dutyMap = {};
    });
    try {
      final dates = await ApiService.getPlanDates(_selectedPlanId!);
      if (mounted) {
        setState(() {
          _examDates = dates;
          // Do not auto-select date; wait for user selection
          _selectedDate = null;
        });
      }
    } catch (e) {
      _showError('Failed to load dates: $e');
    } finally {
      if (mounted) setState(() => _loadingDates = false);
    }
  }

  Future<void> _loadRoomsAndDuties() async {
    if (_selectedPlanId == null || _selectedDate == null) return;
    setState(() => _loadingRoomsAndDuties = true);
    try {
      final rooms = await ApiService.getRooms(_selectedPlanId!, _selectedDate!);
      final duties =
          await ApiService.getDutiesForPlan(_selectedPlanId!, _selectedDate!);
      if (mounted) {
        setState(() {
          _rooms = rooms;
          _dutyMap = duties;
        });
      }
    } catch (e) {
      _showError('Failed to load rooms/duties: $e');
    } finally {
      if (mounted) setState(() => _loadingRoomsAndDuties = false);
    }
  }

  Future<void> _saveDuties() async {
    if (_selectedPlanId == null || _selectedDate == null) return;
    setState(() => _saving = true);
    try {
      // Filter out unassigned rooms
      final Map<String, String> dutiesToSave = {};
      _dutyMap.forEach((roomId, teacherId) {
        if (teacherId.isNotEmpty) {
          dutiesToSave[roomId] = teacherId;
        }
      });

      await ApiService.saveDuties(
          _selectedPlanId!, _selectedDate!, dutiesToSave);
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('Duties saved successfully')));
      }
    } catch (e) {
      final raw = e.toString();
      String friendly = raw;
      final lower = raw.toLowerCase();
      if (lower.contains('401') || lower.contains('unauthorized')) {
        friendly = 'অননুমোদিত (401). আবার লগইন করুন.';
      } else if (lower.contains('403') || lower.contains('forbidden')) {
        friendly = 'নিষিদ্ধ (403). আপনার রোল এই কাজের অনুমতি পায়নি.';
      } else if (lower.contains('timeout')) {
        friendly = 'সময় শেষ হয়েছে। নেটওয়ার্ক পরীক্ষা করে আবার চেষ্টা করুন.';
      }
      _showError('Failed to save duties: $friendly');
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _showError(String message) {
    if (mounted) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Room Duty Allocation'),
      ),
      body: Column(
        children: [
          _buildFilters(),
          Expanded(
            child: _loadingPlans
                ? Center(child: CircularProgressIndicator())
                : _buildDutyTable(),
          ),
        ],
      ),
      floatingActionButton: (_rooms.isNotEmpty &&
              !_saving &&
              (_isController || _userRole == 'super_admin'))
          ? FloatingActionButton.extended(
              onPressed: _saveDuties,
              icon: Icon(Icons.save),
              label: Text('Save Duties'),
            )
          : null,
    );
  }

  Widget _buildFilters() {
    return Card(
      margin: EdgeInsets.all(8),
      child: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            DropdownButtonFormField<String>(
              isExpanded: true,
              decoration: InputDecoration(labelText: 'Seat Plan'),
              value: _selectedPlanId,
              items: _plans
                  .map((p) => DropdownMenuItem(
                        value: p['id'].toString(),
                        child: Text('${p['plan_name']} (${p['shift']})'),
                      ))
                  .toList(),
              onChanged: (v) {
                if (v != null) {
                  setState(() => _selectedPlanId = v);
                  _loadDatesForPlan();
                }
              },
            ),
            const SizedBox(height: 12),
            _loadingDates
                ? _LoadingBox(label: 'Date')
                : DropdownButtonFormField<String>(
                    isExpanded: true,
                    decoration: InputDecoration(labelText: 'Date'),
                    value: _selectedDate,
                    items: _examDates
                        .map((d) => DropdownMenuItem(
                              value: d,
                              child: Text(() {
                                try {
                                  return DateFormat('dd-MM-yyyy')
                                      .format(DateTime.parse(d));
                                } catch (_) {
                                  return d;
                                }
                              }()),
                            ))
                        .toList(),
                    onChanged: (v) {
                      if (v != null) {
                        setState(() => _selectedDate = v);
                        _loadRoomsAndDuties();
                      }
                    },
                  ),
          ],
        ),
      ),
    );
  }

  Widget _buildDutyTable() {
    if (_loadingRoomsAndDuties) {
      return Center(child: CircularProgressIndicator());
    }
    if (_rooms.isEmpty) {
      return Center(child: Text('No rooms found for the selected criteria.'));
    }
    return ListView.builder(
      padding: EdgeInsets.all(8),
      itemCount: _rooms.length,
      itemBuilder: (context, index) {
        final room = _rooms[index];
        final roomId = room['id'].toString();
        final assignedTeacherId = _dutyMap[roomId] ?? '';

        return Card(
          margin: EdgeInsets.symmetric(vertical: 4),
          child: ListTile(
            title: Text(
                'Room: ${room['room_no']} ${room['title'] != null ? '- ' + room['title'] : ''}'),
            subtitle: DropdownButton<String>(
              isExpanded: true,
              value: assignedTeacherId.isEmpty ? null : assignedTeacherId,
              hint: Text('-- Select Teacher --'),
              items: [
                DropdownMenuItem<String>(
                  value: '',
                  child: Text('-- Unassigned --'),
                ),
                ..._teachers.map((t) => DropdownMenuItem(
                      value: t['user_id'].toString(),
                      child: Text(t['display_name']),
                    )),
              ],
              onChanged: (value) {
                final previous = _dutyMap[roomId] ?? '';
                // Empty value means unassign this room
                if (value == null || value.isEmpty) {
                  setState(() {
                    _dutyMap[roomId] = '';
                  });
                  return;
                }
                // Check if selected teacher already assigned to another room
                final already = _dutyMap.entries.firstWhere(
                  (e) => e.value == value && e.key != roomId,
                  orElse: () => const MapEntry('', ''),
                );
                if (already.key.isNotEmpty) {
                  // Show dialog and do NOT move teacher; keep previous assignment
                  showDialog(
                    context: context,
                    builder: (ctx) => AlertDialog(
                      title: const Text('Teacher Already Assigned'),
                      content: Builder(builder: (_) {
                        final existing =
                            _rooms.cast<Map<String, dynamic>?>().firstWhere(
                                  (r) => (r?['id']).toString() == already.key,
                                  orElse: () => null,
                                );
                        final roomNo = (existing != null
                                ? (existing['room_no'] ?? '')
                                : '')
                            .toString();
                        final label = roomNo.isNotEmpty ? roomNo : already.key;
                        return Text(
                            'This teacher is already assigned to Room $label. Remove that assignment first if you want to reassign.');
                      }),
                      actions: [
                        TextButton(
                            onPressed: () => Navigator.of(ctx).pop(),
                            child: const Text('OK')),
                      ],
                    ),
                  );
                  // Revert UI selection
                  setState(() {
                    _dutyMap[roomId] = previous;
                  });
                } else {
                  setState(() {
                    _dutyMap[roomId] = value;
                  });
                }
              },
            ),
          ),
        );
      },
    );
  }
}

class SeatPlanScreen extends StatefulWidget {
  const SeatPlanScreen({super.key});
  @override
  State<SeatPlanScreen> createState() => _SeatPlanScreenState();
}

class _SeatPlanScreenState extends State<SeatPlanScreen> {
  List<dynamic> _plans = [];
  String? _selectedPlanId;
  String _search = '';
  List<dynamic> _results = [];
  bool _loadingPlans = true;
  bool _searching = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPlans();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
      _error = null;
    });
    try {
      // Always show all active plans; keep selection blank by default
      _plans = await ApiService.getSeatPlans('');
      _selectedPlanId = null;
    } catch (e) {
      _error = e.toString();
    } finally {
      if (mounted) {
        setState(() {
          _loadingPlans = false;
        });
      }
    }
  }

  Future<void> _doSearch() async {
    if (_selectedPlanId == null || _search.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('একটি প্ল্যান নির্বাচন করে সার্চ দিন')));
      return;
    }
    setState(() {
      _searching = true;
      _results = [];
      _error = null;
    });
    try {
      _results = await ApiService.searchSeatPlan(
          int.parse(_selectedPlanId!), _search.trim());
      // If query is purely digits, keep only exact roll matches (ignoring leading zeros)
      final q = _search.trim();
      if (RegExp(r'^\d+$').hasMatch(q)) {
        String normalize(String s) {
          if (s == '0') return '0';
          return s.replaceFirst(RegExp(r'^0+'), '');
        }

        final nq = normalize(q);
        _results = _results.where((r) {
          final roll = (r['roll_no'] ?? '').toString();
          return normalize(roll) == nq;
        }).toList();
      }
      if (_results.isEmpty && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text('"$_search" এর জন্য কোন সিট পাওয়া যায়নি')));
      }
    } catch (e) {
      _error = e.toString();
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text('সার্চ ব্যর্থ: $e')));
      }
    } finally {
      if (mounted) {
        setState(() {
          _searching = false;
        });
      }
    }
  }

  String _sideLabel(String? code) {
    if (code == null) return '';
    return code == 'R' ? 'Right' : (code == 'L' ? 'Left' : code);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Find Exam Seat')),
      body: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(12.0),
          child: Column(
            children: [
              Card(
                elevation: 2,
                child: Padding(
                  padding: const EdgeInsets.all(12.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Search Student Seat',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                      const SizedBox(height: 8),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          _loadingPlans
                              ? const _LoadingBox(label: 'Seat Plans')
                              : DropdownButtonFormField<String>(
                                  isExpanded: true,
                                  decoration: const InputDecoration(
                                      labelText: 'Seat Plan'),
                                  value: _selectedPlanId,
                                  items: [
                                    const DropdownMenuItem<String>(
                                        value: '', child: Text('')),
                                    ..._plans
                                        .map((p) => DropdownMenuItem<String>(
                                              value: p['id']?.toString(),
                                              child: Text(
                                                  '${p['plan_name']} (${p['shift']})'),
                                            ))
                                  ],
                                  onChanged: (v) {
                                    setState(() {
                                      if (v == null || v.isEmpty) {
                                        _selectedPlanId = null;
                                        _results = [];
                                      } else {
                                        _selectedPlanId = v;
                                      }
                                    });
                                  },
                                ),
                          const SizedBox(height: 12),
                          TextFormField(
                            decoration: const InputDecoration(
                                labelText: 'Roll or Name'),
                            onChanged: (v) => setState(() => _search = v),
                            onFieldSubmitted: (_) => _doSearch(),
                          ),
                          const SizedBox(height: 12),
                          SizedBox(
                            height: 48,
                            width: double.infinity,
                            child: ElevatedButton.icon(
                              onPressed: (_searching ||
                                      _selectedPlanId == null ||
                                      _search.trim().isEmpty)
                                  ? null
                                  : _doSearch,
                              icon: const Icon(Icons.search),
                              label: _searching
                                  ? const Text('Searching...')
                                  : const Text('Search'),
                            ),
                          ),
                        ],
                      )
                    ],
                  ),
                ),
              ),
              const SizedBox(height: 8),
              _buildResults(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildResults() {
    if (_searching) {
      return const Center(heightFactor: 5, child: CircularProgressIndicator());
    }
    if (_error != null) {
      return Center(child: Text('Error: $_error'));
    }
    if (_results.isEmpty) {
      return const Center(
          heightFactor: 5, child: Text('Select a plan and search'));
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          children: [
            Chip(
              label: Text('Total: ${_results.length}'),
              backgroundColor: Colors.indigo.shade50,
            ),
          ],
        ),
        const SizedBox(height: 8),
        ListView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: _results.length,
          itemBuilder: (context, i) {
            final r = _results[i];
            final roll = r['roll_no']?.toString() ?? '';
            final name = r['student_name']?.toString() ?? '';
            final cls = r['class_name']?.toString() ?? '';
            final room = r['room_no']?.toString() ?? '';
            final col = (r['col_no'] ?? '').toString();
            final bench = (r['bench_no'] ?? '').toString();
            final side = _sideLabel(r['position']?.toString());
            return Card(
              elevation: 2,
              margin: const EdgeInsets.symmetric(vertical: 6),
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(14)),
              child: Padding(
                padding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        CircleAvatar(
                          backgroundColor: Colors.indigo.shade600,
                          foregroundColor: Colors.white,
                          child:
                              Text(roll, style: const TextStyle(fontSize: 12)),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(name,
                                  style: const TextStyle(
                                      fontSize: 15,
                                      fontWeight: FontWeight.w600)),
                              const SizedBox(height: 4),
                              Text('Class: $cls  •  Room: $room',
                                  style: const TextStyle(
                                      fontSize: 12, color: Colors.black54)),
                            ],
                          ),
                        )
                      ],
                    ),
                    const SizedBox(height: 10),
                    Wrap(
                      spacing: 8,
                      runSpacing: -4,
                      children: [
                        Chip(
                          label: Text('Column $col'),
                          backgroundColor: Colors.blue.shade50,
                        ),
                        Chip(
                          label: Text('Bench $bench'),
                          backgroundColor: Colors.green.shade50,
                        ),
                        Chip(
                          label: Text('Side $side'),
                          backgroundColor: Colors.orange.shade50,
                        ),
                      ],
                    )
                  ],
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}

class ApiService {
  static const String _remoteBase = 'https://jss.batighorbd.com/api';
  static const String _localFallback =
      'http://10.0.2.2/jsssms/api'; // Android emulator -> Windows host XAMPP

  static Future<String> _resolveBaseUrl() async {
    // Allow overriding base URL from SharedPreferences for development
    final prefs = await SharedPreferences.getInstance();
    final manual = prefs.getString('api_base_url');
    if (manual != null && manual.trim().isNotEmpty) return manual.trim();
    return _remoteBase;
  }

  // Simple in-memory caches to speed up app usage within a session
  static final Map<String, List<dynamic>> _seatPlansCache = {}; // key: date
  static final Map<String, List<dynamic>> _roomsCache = {}; // key: planId|date
  static List<dynamic>? _teachersCache; // global list
  static final Map<String, List<String>> _planDatesCache = {}; // key: planId

  static Future<String> _getToken() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString('token') ?? '';
  }

  // Expose resolved base and site root for building links (e.g., profile pages)
  static Future<String> getBaseUrl() => _resolveBaseUrl();
  static Future<String> getSiteRoot() async {
    final base = await _resolveBaseUrl();
    if (base.endsWith('/api')) return base.substring(0, base.length - 4);
    return base;
  }

  static Future<dynamic> _get(String endpoint) async {
    final token = await _getToken();
    final base = await _resolveBaseUrl();

    Future<dynamic> attempt(String baseUrl) async {
      final uri = Uri.parse('$baseUrl/$endpoint');
      final r = await http.get(uri, headers: {
        'Authorization': 'Bearer $token'
      }).timeout(const Duration(seconds: 12));
      if (r.statusCode != 200) {
        throw Exception('GET $endpoint failed (${r.statusCode})');
      }
      final ct = (r.headers['content-type'] ?? '').toLowerCase();
      final body = r.body;
      // Guard against HTML/text responses (e.g., 404 page, login page)
      if (!ct.contains('application/json') && body.trim().startsWith('<')) {
        final trimmed = body.trim().replaceAll('\n', ' ');
        final snippet = trimmed.substring(0, trimmed.length.clamp(0, 160));
        throw Exception(
            'Non-JSON response from $endpoint at $baseUrl: $snippet');
      }
      dynamic decoded;
      try {
        decoded = jsonDecode(body);
      } catch (_) {
        throw Exception('Invalid JSON response from $endpoint at $baseUrl');
      }
      if (decoded is Map && decoded['success'] == true) {
        return decoded['data'];
      }
      final err = (decoded is Map && decoded.containsKey('error'))
          ? decoded['error']
          : 'Unknown server error';
      throw Exception('API Error from $endpoint at $baseUrl: $err');
    }

    // Try primary base first; if it yields non-JSON/HTML, attempt local fallback once
    try {
      return await attempt(base);
    } catch (e) {
      final msg = e.toString();
      // Only try fallback if current base is remote and error looks like non-JSON/HTML or 404-like
      if (base == _remoteBase &&
          (msg.contains('Non-JSON response') ||
              msg.contains('Invalid JSON') ||
              msg.contains('failed (404)'))) {
        try {
          return await attempt(_localFallback);
        } catch (_) {
          // rethrow original error for clarity
          throw Exception(msg);
        }
      }
      rethrow;
    }
  }

  static Future<dynamic> _post(
      String endpoint, Map<String, dynamic> body) async {
    final token = await _getToken();
    final base = await _resolveBaseUrl();

    Future<dynamic> attempt(String baseUrl) async {
      final uri = Uri.parse('$baseUrl/$endpoint');
      http.Response response;
      try {
        response = await http
            .post(
              uri,
              headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer $token',
              },
              body: jsonEncode(body),
            )
            .timeout(const Duration(seconds: 15));
      } on TimeoutException {
        throw Exception(
            'Request timeout while posting to $endpoint at $baseUrl');
      } catch (e) {
        throw Exception('Network error posting to $endpoint at $baseUrl: $e');
      }

      if (response.statusCode == 200) {
        final ct = (response.headers['content-type'] ?? '').toLowerCase();
        final bodyText = response.body;
        if (!ct.contains('application/json') &&
            bodyText.trim().startsWith('<')) {
          final trimmed = bodyText.trim().replaceAll('\n', ' ');
          final snippet = trimmed.substring(0, trimmed.length.clamp(0, 160));
          throw Exception(
              'Non-JSON response from $endpoint at $baseUrl: $snippet');
        }
        dynamic data;
        try {
          data = jsonDecode(bodyText);
        } catch (_) {
          throw Exception('Invalid JSON response from $endpoint at $baseUrl');
        }
        if (data is Map && data['success'] == true) {
          return data; // full success payload
        }
        // If server follows bootstrap.php error schema
        final err = (data is Map && data.containsKey('error'))
            ? data['error']
            : 'Unknown server error';
        throw Exception('API Error from $endpoint at $baseUrl: $err');
      }

      // Non-200: attempt to parse body for richer diagnostics
      String serverError = '';
      try {
        final j = jsonDecode(response.body);
        if (j is Map) {
          final parts = <String>[];
          if (j['error'] != null) parts.add(j['error'].toString());
          if (j['code'] != null) parts.add('code=${j['code']}');
          serverError = parts.join(' | ');
        }
      } catch (_) {
        // ignore parse failures
      }

      final code = response.statusCode;
      final tokenInfo =
          token.isEmpty ? 'token=EMPTY' : 'token.len=${token.length}';
      String hint = '';
      if (code == 401) {
        hint =
            'Unauthorized (401). Please login again. টোকেন মেয়াদ শেষ বা অবৈধ.';
      } else if (code == 403) {
        hint = 'Forbidden (403). আপনার রোল এই অপারেশনের জন্য অনুমোদিত নয়.';
      }
      final msg = [
        'POST $endpoint failed at $baseUrl',
        'status=$code',
        tokenInfo,
        if (serverError.isNotEmpty) 'server="${serverError}"',
        if (hint.isNotEmpty) hint,
      ].join(' | ');
      throw Exception(msg);
    }

    try {
      return await attempt(base);
    } catch (e) {
      final msg = e.toString();
      if (base == _remoteBase &&
          (msg.contains('Non-JSON response') ||
              msg.contains('Invalid JSON') ||
              msg.contains('failed (404)'))) {
        try {
          return await attempt(_localFallback);
        } catch (_) {
          throw Exception(msg);
        }
      }
      rethrow;
    }
  }

  static Future<bool> isController(String userId) async {
    final base = await _resolveBaseUrl();
    final uri = Uri.parse('$base/is_controller.php?user_id=$userId');
    // Try public GET first to avoid CORS issues with Authorization header (Flutter web)
    try {
      final r1 = await http.get(uri).timeout(const Duration(seconds: 10));
      if (r1.statusCode == 200) {
        final json = jsonDecode(r1.body);
        final obj =
            (json is Map && json.containsKey('data') && json['data'] is Map)
                ? json['data']
                : json;
        final v = (obj is Map) ? obj['is_controller'] : null;
        if (v != null) {
          if (v is bool) return v;
          if (v is num) return v != 0;
          final s = v.toString().toLowerCase();
          return s == '1' || s == 'true' || s == 'yes';
        }
      }
    } catch (_) {
      // ignore and try with auth
    }

    // Fallback: try with Authorization header
    try {
      final token = await _getToken();
      final r2 = await http.get(uri, headers: {
        'Authorization': 'Bearer $token',
      }).timeout(const Duration(seconds: 10));
      if (r2.statusCode == 200) {
        final json = jsonDecode(r2.body);
        final obj =
            (json is Map && json.containsKey('data') && json['data'] is Map)
                ? json['data']
                : json;
        final v = (obj is Map) ? obj['is_controller'] : null;
        if (v != null) {
          if (v is bool) return v;
          if (v is num) return v != 0;
          final s = v.toString().toLowerCase();
          return s == '1' || s == 'true' || s == 'yes';
        }
      }
    } catch (_) {
      // swallow
    }

    // Default to false if undetermined
    return false;
  }

  static Future<List<dynamic>> getDuties() async {
    final data = await _get('teacher/duties.php?days=7');
    return data['duties'] as List<dynamic>;
  }

  // Seat plans: always return ACTIVE plans only. Tries multiple endpoints for compatibility.
  // Expects array items with keys like {id, plan_name, shift, status|is_active}.
  static Future<List<dynamic>> getSeatPlans(String date) async {
    const cacheKey = '__all_active__';
    if (_seatPlansCache.containsKey(cacheKey))
      return _seatPlansCache[cacheKey]!;

    Future<List<dynamic>> tryFetch(String endpoint) async {
      try {
        final data = await _get(endpoint);
        return (data['plans'] ?? []) as List<dynamic>;
      } catch (_) {
        return [];
      }
    }

    final today = DateTime.now().toIso8601String().substring(0, 10);
    final endpoints = <String>[
      // New lightweight endpoint for active plans list
      'exam/plans_list.php',
      // Legacy/date-scoped endpoints as fallbacks
      'exam/seat_plans.php?status=active',
      'exam/seat_plans.php',
      if (date.isNotEmpty) 'exam/seat_plans.php?date=$date',
      'exam/seat_plans.php?date=$today',
    ];

    List<dynamic> plans = [];
    for (final ep in endpoints) {
      plans = await tryFetch(ep);
      if (plans.isNotEmpty) break;
    }

    bool isActive(dynamic p) {
      final m = (p is Map) ? p : {};
      final v = m['status'];
      if (v is bool) return v;
      if (v is num) return v != 0;
      final s = v?.toString().toLowerCase();
      if (s == 'active') return true;
      if (s == '1' || s == 'true' || s == 'yes') return true;
      // Fallback to alternative key often used
      final alt = m['is_active'];
      if (alt is bool) return alt;
      if (alt is num) return alt != 0;
      final a = alt?.toString().toLowerCase();
      return a == '1' || a == 'true' || a == 'yes';
    }

    plans = plans.where(isActive).toList();
    _seatPlansCache[cacheKey] = plans;
    return plans;
  }

  // New: rooms for a seat plan; if teacher, API should scope to assignments
  static Future<List<dynamic>> getRooms(String planId, String date) async {
    final key = '$planId|$date';
    if (_roomsCache.containsKey(key)) return _roomsCache[key]!;
    final data = await _get('exam/rooms.php?plan_id=$planId&date=$date');
    final rooms = (data['rooms'] ?? []) as List<dynamic>;
    _roomsCache[key] = rooms;
    return rooms;
  }

  // Seat plan search (finder) - expects endpoint returning {results:[...]}
  // Each result item should contain: roll_no, student_name, class_name, room_no, col_no, bench_no, position
  static Future<List<dynamic>> searchSeatPlan(int planId, String query) async {
    if (query.trim().isEmpty) return [];
    final ep =
        'exam/seat_plan_search.php?plan_id=$planId&find=${Uri.encodeComponent(query.trim())}';
    try {
      final data = await _get(ep);
      return (data['results'] ?? []) as List<dynamic>;
    } catch (e) {
      // Gracefully surface a clearer error if server redirects to login or 404
      final msg = e.toString();
      if (msg.contains('login.php') || msg.contains('Redirect')) {
        throw Exception(
            'Search endpoint unavailable. Please update server APIs.');
      }
      rethrow;
    }
  }

  // API methods for Room Duty Allocation
  static Future<List<dynamic>> getTeachers() async {
    if (_teachersCache != null) return _teachersCache!;
    final data = await _get('teachers.php');
    _teachersCache = data['teachers'] as List<dynamic>;
    return _teachersCache!;
  }

  static Future<List<String>> getPlanDates(String planId) async {
    if (_planDatesCache.containsKey(planId)) return _planDatesCache[planId]!;
    final data = await _get('exam/plan_dates.php?plan_id=$planId');
    final dates = (data['dates'] as List).map((d) => d.toString()).toList();
    _planDatesCache[planId] = dates;
    return dates;
  }

  static Future<Map<String, String>> getDutiesForPlan(
      String planId, String date) async {
    final data = await _get('exam/duties.php?plan_id=$planId&date=$date');
    return Map<String, String>.from((data['duties'] as Map)
        .map((k, v) => MapEntry(k.toString(), v.toString())));
  }

  static Future<void> saveDuties(
      String planId, String date, Map<String, String> duties) async {
    await _post('exam/duties.php?plan_id=$planId&date=$date', {
      'duties': duties,
    });
  }

  // Optional bulk helper (not used directly; we reuse submitAttendance)
  static Future<void> bulkMarkAttendance(String date, int planId, int roomId,
      String mode, List<dynamic> students) async {
    final entries = students
        .map((s) => {'student_id': s['student_id'], 'status': mode})
        .toList();
    await submitAttendance(date, planId, roomId, entries);
  }

  static Future<List<dynamic>> getAttendance(
      String date, int planId, int roomId) async {
    final data = await _get(
        'exam/attendance_get.php?date=$date&plan_id=$planId&room_id=$roomId');
    return data['students'] as List<dynamic>;
  }

  // Controller-capable attendance fetch for any room (used by report screen)
  static Future<List<dynamic>> getAttendanceForReport(
      String date, int planId, int roomId) async {
    final attempts = <String>[
      'exam/attendance_get.php?date=$date&plan_id=$planId&room_id=$roomId&as=controller',
      'exam/attendance_get.php?date=$date&plan_id=$planId&room_id=$roomId&controller=1',
      'exam/attendance_get_admin.php?date=$date&plan_id=$planId&room_id=$roomId',
    ];
    for (final ep in attempts) {
      try {
        final data = await _get(ep);
        final students = data['students'];
        if (students is List) return students.cast<dynamic>();
      } catch (e) {
        // If unauthorized (403), try next variant; otherwise keep trying next
        // No rethrow here to allow graceful fallback
      }
    }
    // Fallback to teacher-scoped endpoint if others unavailable
    return await getAttendance(date, planId, roomId);
  }

  static Future<void> submitAttendance(String date, int planId, int roomId,
      List<Map<String, dynamic>> entries) async {
    await _post('exam/attendance_submit.php', {
      'date': date,
      'plan_id': planId,
      'room_id': roomId,
      'entries': entries,
    });
  }

  static Future<List<dynamic>> getExams() async {
    final data = await _get('marks/get_exams.php');
    return data['exams'];
  }

  static Future<List<dynamic>> getClasses() async {
    final data = await _get('marks/get_classes.php');
    return data['classes'];
  }

  static Future<List<dynamic>> getSections() async {
    final data = await _get('marks/get_sections.php');
    return data['sections'];
  }

  // Sections by Class (uses /ajax endpoint at site root)
  static Future<List<dynamic>> getSectionsByClass(int classId) async {
    final root = await getSiteRoot();
    final uri = Uri.parse('$root/ajax/get_sections.php?class_id=$classId');
    final r = await http.get(uri).timeout(const Duration(seconds: 10));
    if (r.statusCode != 200) {
      throw Exception('Failed to load sections for class $classId');
    }
    try {
      final list = jsonDecode(r.body);
      return (list as List).cast<dynamic>();
    } catch (_) {
      throw Exception('Invalid sections JSON for class $classId');
    }
  }

  // Groups by Class (uses /ajax endpoint at site root)
  static Future<List<String>> getGroupsByClass(int classId) async {
    final root = await getSiteRoot();
    final uri = Uri.parse('$root/ajax/get_groups.php?class_id=$classId');
    final r = await http.get(uri).timeout(const Duration(seconds: 10));
    if (r.statusCode != 200) {
      throw Exception('Failed to load groups for class $classId');
    }
    try {
      final list = jsonDecode(r.body);
      return (list as List).map((e) => e.toString()).toList();
    } catch (_) {
      throw Exception('Invalid groups JSON for class $classId');
    }
  }

  static Future<List<dynamic>> getSubjectsForTeacher(
      String examId, String classId) async {
    final data =
        await _get('marks/subjects.php?exam_id=$examId&class_id=$classId');
    return data['subjects'];
  }

  static Future<Map<String, dynamic>> getStudentsForMarking(
      String examId, String classId, String subjectId) async {
    final data = await _get(
        'marks/get_students_for_marking.php?exam_id=$examId&class_id=$classId&subject_id=$subjectId');
    return {'meta': data['meta'] ?? {}, 'students': data['students'] ?? []};
  }

  static Future<void> submitMarks(String examId, String classId,
      String subjectId, List<Map<String, dynamic>> marks) async {
    await _post('marks/submit.php', {
      'exam_id': examId,
      'class_id': classId,
      'subject_id': subjectId,
      'marks': marks,
    });
  }

  // ===== Students (Super Admin) =====
  static Future<Map<String, dynamic>> studentCounts() async {
    final data = await _get('students/counts.php');
    return {
      'total': data['total'] ?? 0,
      'by_class': (data['by_class'] ?? []) as List<dynamic>,
    };
  }

  static Future<Map<String, dynamic>> listStudents({
    int page = 1,
    int perPage = 20,
    int? classId,
    int? sectionId,
    String? group,
    String? q,
  }) async {
    final params = <String, String>{
      'page': page.toString(),
      'per_page': perPage.toString(),
    };
    if (classId != null) params['class_id'] = classId.toString();
    if (sectionId != null) params['section_id'] = sectionId.toString();
    if (group != null && group.isNotEmpty) params['group'] = group;
    if (q != null && q.isNotEmpty) params['q'] = q;
    final ep = 'students/list.php?' +
        params.entries
            .map((e) => '${e.key}=${Uri.encodeQueryComponent(e.value)}')
            .join('&');
    final data = await _get(ep);
    return data as Map<String, dynamic>;
  }

  static Future<Map<String, dynamic>> getStudent(
      {int? id, String? studentId}) async {
    final qp = id != null
        ? 'id=$id'
        : (studentId != null
            ? 'student_id=${Uri.encodeQueryComponent(studentId)}'
            : '');
    final data = await _get('students/get.php?' + qp);
    return (data['student'] ?? {}) as Map<String, dynamic>;
  }

  static Future<int> createStudent(Map<String, dynamic> student) async {
    final data = await _post('students/create.php', student);
    final id = (data['data']?['id']) ?? (data['id']);
    return (id is int) ? id : int.tryParse(id.toString()) ?? 0;
  }

  static Future<bool> updateStudent(int id, Map<String, dynamic> patch) async {
    final payload = {'id': id, ...patch};
    final data = await _post('students/update.php', payload);
    final updated = (data['data']?['updated']) ?? (data['updated']);
    if (updated is int) return updated > 0;
    return int.tryParse(updated.toString()) != 0;
  }

  // Register/update this device's FCM token with the server for push notifications
  static Future<void> saveDeviceToken(String token) async {
    final platform = 'android'; // Adjust via Platform.isIOS if needed
    final payload = {'token': token, 'platform': platform};
    try {
      await _post('devices/register.php', payload);
      return;
    } catch (_) {
      // Fallback: some server setups expect api_token in URL query rather than
      // Authorization header. Try posting to endpoint with ?api_token=token
      try {
        final base = await _resolveBaseUrl();
        final auth = await _getToken();
        final uri = Uri.parse(
            '$base/devices/register.php?api_token=${Uri.encodeComponent(auth)}');
        await http.post(uri,
            headers: {'Content-Type': 'application/json'},
            body: jsonEncode(payload));
        return;
      } catch (_) {
        // swallow any errors; registration is best-effort
      }
    }
  }
}
