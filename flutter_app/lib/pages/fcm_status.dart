import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:http/http.dart' as http;
import '../services/notification_service.dart';

class FcmStatusPage extends StatefulWidget {
  const FcmStatusPage({super.key});
  @override
  State<FcmStatusPage> createState() => _FcmStatusPageState();
}

class _FcmStatusPageState extends State<FcmStatusPage> {
  String? _deviceToken;
  List<dynamic> _serverTokens = [];
  bool _loading = true;
  String? _error;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final devToken = await FirebaseMessaging.instance.getToken();
      final prefs = await SharedPreferences.getInstance();
      final auth = prefs.getString('token') ?? '';
      final resp = await http.get(
        Uri.parse('${NotificationService.baseUrl}/devices/status.php'),
        headers: {
          if (auth.isNotEmpty) 'Authorization': 'Bearer ' + auth,
        },
      );
      if (resp.statusCode != 200) {
        throw Exception('Server HTTP ${resp.statusCode}');
      }
      final json = jsonDecode(resp.body);
      if (json is Map && json['success'] == true) {
        setState(() {
          _deviceToken = devToken;
          _serverTokens = (json['data']?['tokens'] as List?) ?? [];
        });
      } else {
        throw Exception(json['error']?.toString() ?? 'Failed to load');
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      setState(() {
        _loading = false;
      });
    }
  }

  bool get _isRegisteredOnServer {
    if (_deviceToken == null || _deviceToken!.isEmpty) return false;
    return _serverTokens
        .any((t) => (t['token']?.toString() ?? '') == _deviceToken);
  }

  Future<void> _registerNow() async {
    await NotificationService.registerCurrentToken();
    await _load();
  }

  Future<void> _sendTestToMe() async {
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final prefs = await SharedPreferences.getInstance();
      final auth = prefs.getString('token') ?? '';
      final resp = await http
          .post(
            Uri.parse(
                '${NotificationService.baseUrl}/devices/send_test_to_me.php'),
            headers: {
              'Content-Type': 'application/json',
              if (auth.isNotEmpty) 'Authorization': 'Bearer ' + auth,
            },
            body: jsonEncode({
              'title': 'FCM Test',
              'body': 'This is a self-test notification',
              'validate': false,
            }),
          )
          .timeout(const Duration(seconds: 15));
      final json = jsonDecode(resp.body);
      if (resp.statusCode == 200 && json is Map && json['success'] == true) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
                content: Text('Test push sent (if token is valid).')),
          );
        }
      } else {
        throw Exception(json is Map
            ? (json['error']?.toString() ?? 'Send failed')
            : 'Send failed');
      }
    } catch (e) {
      setState(() {
        _error = e.toString();
      });
    } finally {
      setState(() {
        _sending = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('FCM Status'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12.0),
                      child: Text(_error!,
                          style: const TextStyle(color: Colors.red)),
                    ),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              const Icon(Icons.phone_android_outlined),
                              const SizedBox(width: 8),
                              const Text('Device Token',
                                  style:
                                      TextStyle(fontWeight: FontWeight.w600)),
                            ],
                          ),
                          const SizedBox(height: 8),
                          SelectableText(_deviceToken ?? 'Not available'),
                          const SizedBox(height: 8),
                          Wrap(spacing: 8, children: [
                            ElevatedButton.icon(
                              onPressed: _registerNow,
                              icon: const Icon(Icons.cloud_upload_outlined),
                              label: const Text('Register Token'),
                            ),
                            ElevatedButton.icon(
                              onPressed: _sending ? null : _sendTestToMe,
                              icon: _sending
                                  ? const SizedBox(
                                      width: 16,
                                      height: 16,
                                      child: CircularProgressIndicator(
                                          strokeWidth: 2),
                                    )
                                  : const Icon(Icons.send_outlined),
                              label: const Text('Send test to me'),
                            ),
                          ]),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Icon(
                                  _isRegisteredOnServer
                                      ? Icons.verified_outlined
                                      : Icons.error_outline,
                                  color: _isRegisteredOnServer
                                      ? Colors.green
                                      : Colors.orange),
                              const SizedBox(width: 8),
                              Text(
                                _isRegisteredOnServer
                                    ? 'Server Registration: OK'
                                    : 'Server Registration: Missing',
                                style: const TextStyle(
                                    fontWeight: FontWeight.w600),
                              ),
                            ],
                          ),
                          const SizedBox(height: 8),
                          const Text('Registered tokens on server:'),
                          const SizedBox(height: 6),
                          ConstrainedBox(
                            constraints: const BoxConstraints(maxHeight: 220),
                            child: ListView.builder(
                              shrinkWrap: true,
                              itemCount: _serverTokens.length,
                              itemBuilder: (context, index) {
                                final t = _serverTokens[index];
                                final tok = t['token']?.toString() ?? '';
                                final updated =
                                    t['updated_at']?.toString() ?? '';
                                final isThis = (_deviceToken ?? '') == tok;
                                return ListTile(
                                  dense: true,
                                  leading: Icon(
                                      isThis
                                          ? Icons.check_circle
                                          : Icons.radio_button_unchecked,
                                      color: isThis ? Colors.green : null),
                                  title: Text(
                                    tok,
                                    maxLines: 2,
                                    overflow: TextOverflow.ellipsis,
                                    style: const TextStyle(fontSize: 13),
                                  ),
                                  subtitle: Text('updated: $updated',
                                      style: const TextStyle(fontSize: 12)),
                                );
                              },
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  const Spacer(),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _loading ? null : _load,
                          icon: const Icon(Icons.sync),
                          label: const Text('Refresh Status'),
                        ),
                      ),
                    ],
                  )
                ],
              ),
            ),
    );
  }
}
