import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/notification_message.dart';
import '../services/notification_service.dart';

class NotificationListPage extends StatelessWidget {
  const NotificationListPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Notifications'),
        actions: [
          IconButton(
            icon: const Icon(Icons.delete_sweep),
            tooltip: 'Clear All Notifications',
            onPressed: () {
              // Show a confirmation dialog before clearing
              showDialog(
                context: context,
                builder: (BuildContext ctx) {
                  return AlertDialog(
                    title: const Text('Confirm Clear'),
                    content: const Text(
                        'Are you sure you want to delete all notifications? This cannot be undone.'),
                    actions: <Widget>[
                      TextButton(
                        child: const Text('Cancel'),
                        onPressed: () {
                          Navigator.of(ctx).pop();
                        },
                      ),
                      TextButton(
                        child: const Text('Clear All'),
                        onPressed: () {
                          NotificationService.clearMessages();
                          Navigator.of(ctx).pop();
                        },
                      ),
                    ],
                  );
                },
              );
            },
          ),
        ],
      ),
      body: ValueListenableBuilder<List<NotificationMessage>>(
        valueListenable: NotificationService.messages,
        builder: (context, messages, child) {
          if (messages.isEmpty) {
            return const Center(
              child: Text(
                'You have no notifications.',
                style: TextStyle(fontSize: 16, color: Colors.grey),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.all(8),
            itemCount: messages.length,
            separatorBuilder: (context, index) =>
                const Divider(height: 1, indent: 16, endIndent: 16),
            itemBuilder: (context, index) {
              final message = messages[index];
              return ListTile(
                leading: const Icon(Icons.notifications_active,
                    color: Colors.indigo),
                title: Text(message.title,
                    style: const TextStyle(fontWeight: FontWeight.bold)),
                subtitle: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(message.body),
                    const SizedBox(height: 4),
                    Text(
                      DateFormat.yMMMd()
                          .add_jm()
                          .format(message.receivedAt.toLocal()),
                      style: const TextStyle(fontSize: 12, color: Colors.grey),
                    ),
                  ],
                ),
                isThreeLine: true,
                onTap: () {
                  // Optional: show a dialog with more details from message.data
                  showDialog(
                    context: context,
                    builder: (context) => AlertDialog(
                      title: Text(message.title),
                      content: SingleChildScrollView(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Text(message.body),
                            const SizedBox(height: 16),
                            const Text('Details:',
                                style: TextStyle(fontWeight: FontWeight.bold)),
                            const Divider(),
                            Text(message.data.toString()),
                          ],
                        ),
                      ),
                      actions: [
                        TextButton(
                          onPressed: () => Navigator.of(context).pop(),
                          child: const Text('Close'),
                        )
                      ],
                    ),
                  );
                },
              );
            },
          );
        },
      ),
    );
  }
}
