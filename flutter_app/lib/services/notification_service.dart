import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';
import 'package:flutter/material.dart';
import '../models/notification_message.dart';
import '../pages/notification_list_page.dart';

@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  await NotificationService.init(isBackground: true);
  await NotificationService.addMessageFromRemote(message);
}

class NotificationService {
  static final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  static final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();
  static const String _baseUrl = 'https://jss.batighorbd.com/api';
  static const String _storageKey = 'notification_messages';
  static String get baseUrl => _baseUrl;

  // Global navigator key to allow navigation from background
  static final GlobalKey<NavigatorState> navigatorKey =
      GlobalKey<NavigatorState>();

  // Value notifier to hold and update the list of messages for the UI
  static final ValueNotifier<List<NotificationMessage>> messages =
      ValueNotifier([]);

  static Future<void> init({bool isBackground = false}) async {
    if (!isBackground) {
      // Initialize Firebase (guarded so app doesn't crash if not configured yet)
      try {
        await Firebase.initializeApp();
      } catch (e) {
        // Firebase not configured; skip push setup silently
        return;
      }
    }

    // Android notification channel
    const AndroidInitializationSettings androidInitializationSettings =
        AndroidInitializationSettings('@mipmap/ic_launcher');

    // iOS initialization
    const DarwinInitializationSettings darwinInitializationSettings =
        DarwinInitializationSettings(
      requestAlertPermission: true,
      requestBadgePermission: true,
      requestSoundPermission: true,
    );

    const InitializationSettings initializationSettings =
        InitializationSettings(
      android: androidInitializationSettings,
      iOS: darwinInitializationSettings,
    );

    // Handle notification tap
    await _localNotifications.initialize(
      initializationSettings,
      onDidReceiveNotificationResponse: (response) {
        if (response.payload != null && response.payload!.isNotEmpty) {
          // Navigate to the notifications screen
          navigatorKey.currentState
              ?.push(MaterialPageRoute(builder: (_) => NotificationListPage()));
        }
      },
    );

    if (isBackground) return;

    // Request permissions for iOS and Android 13+
    await _messaging.requestPermission(
      alert: true,
      announcement: false,
      badge: true,
      carPlay: false,
      criticalAlert: false,
      provisional: false,
      sound: true,
    );

    // Set up background message handler
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

    // Handle foreground messages
    FirebaseMessaging.onMessage.listen((RemoteMessage message) {
      print('Got a message whilst in the foreground!');
      addMessageFromRemote(message);

      RemoteNotification? notification = message.notification;
      AndroidNotification? android = message.notification?.android;

      if (notification != null && android != null) {
        _localNotifications.show(
          notification.hashCode,
          notification.title,
          notification.body,
          const NotificationDetails(
            android: AndroidNotificationDetails(
              'high_importance_channel', // id
              'High Importance Notifications', // title
              channelDescription:
                  'This channel is used for important notifications.',
              importance: Importance.max,
              priority: Priority.high,
              icon: '@mipmap/ic_launcher',
            ),
          ),
          payload: jsonEncode(message.data), // Pass data for navigation
        );
      }
    });

    // Handle notification tap when app is terminated
    _messaging.getInitialMessage().then((message) {
      if (message != null) {
        navigatorKey.currentState
            ?.push(MaterialPageRoute(builder: (_) => NotificationListPage()));
      }
    });

    // Load existing messages from storage
    await loadMessages();

    // Get the token and save it to the server
    await _getTokenAndSave();
    _messaging.onTokenRefresh.listen((token) {
      _saveTokenToServer(token);
    });
  }

  static Future<void> addMessageFromRemote(RemoteMessage remoteMessage) async {
    final notification = NotificationMessage(
      title: remoteMessage.notification?.title ?? 'No Title',
      body: remoteMessage.notification?.body ?? 'No Body',
      data: remoteMessage.data,
      receivedAt: DateTime.now(),
    );
    await addMessage(notification);
  }

  static Future<void> addMessage(NotificationMessage message) async {
    final currentMessages = messages.value.toList();
    currentMessages.insert(0, message); // Add to the top of the list
    messages.value = currentMessages;
    await _saveMessages();
  }

  static Future<void> _saveMessages() async {
    final prefs = await SharedPreferences.getInstance();
    final encodedMessages =
        messages.value.map((msg) => jsonEncode(msg.toJson())).toList();
    await prefs.setStringList(_storageKey, encodedMessages);
  }

  static Future<void> loadMessages() async {
    final prefs = await SharedPreferences.getInstance();
    final encodedMessages = prefs.getStringList(_storageKey) ?? [];
    messages.value = encodedMessages
        .map((str) => NotificationMessage.fromJson(jsonDecode(str)))
        .toList();
  }

  static Future<void> clearMessages() async {
    messages.value = [];
    await _saveMessages();
  }

  static Future<void> _getTokenAndSave() async {
    String? token = await _messaging.getToken();
    if (token != null) {
      await _saveTokenToServer(token);
    }
  }

  static Future<void> _saveTokenToServer(String token) async {
    try {
      print("Saving FCM token to server...");
      final prefs = await SharedPreferences.getInstance();
      final auth = prefs.getString('token') ?? '';
      final resp = await http
          .post(Uri.parse('$_baseUrl/devices/register.php'),
              headers: {
                'Content-Type': 'application/json',
                if (auth.isNotEmpty) 'Authorization': 'Bearer ' + auth,
              },
              body: jsonEncode({'token': token, 'platform': 'android'}))
          .timeout(const Duration(seconds: 10));
      if (resp.statusCode == 200) {
        print("FCM token saved successfully.");
      } else {
        print("Failed to save FCM token: HTTP ${resp.statusCode}");
      }
    } catch (e) {
      print("Failed to save FCM token: $e");
    }
  }

  // Call this after login to ensure the token is registered with auth
  static Future<void> registerCurrentToken() async {
    try {
      final token = await _messaging.getToken();
      if (token != null) {
        await _saveTokenToServer(token);
      }
    } catch (_) {}
  }
}
