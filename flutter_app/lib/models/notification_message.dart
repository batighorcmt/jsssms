/// Model for a single notification message.
/// Used for storing and displaying notification history.
class NotificationMessage {
  final String title;
  final String body;
  final Map<String, dynamic> data;
  final DateTime receivedAt;

  NotificationMessage({
    required this.title,
    required this.body,
    required this.data,
    required this.receivedAt,
  });

  // For encoding to JSON (to save in SharedPreferences)
  Map<String, dynamic> toJson() => {
        'title': title,
        'body': body,
        'data': data,
        'receivedAt': receivedAt.toIso8601String(),
      };

  // For decoding from JSON
  factory NotificationMessage.fromJson(Map<String, dynamic> json) =>
      NotificationMessage(
        title: json['title'] as String,
        body: json['body'] as String,
        data: json['data'] as Map<String, dynamic>,
        receivedAt: DateTime.parse(json['receivedAt'] as String),
      );
}
