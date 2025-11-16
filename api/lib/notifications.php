<?php
// Lightweight FCM send helper and token fetchers
@include_once __DIR__ . '/../../config/notifications.php';
@include_once __DIR__ . '/../../config/db.php';

/**
 * Fetch active device tokens for one or more user IDs
 * @param mysqli $conn
 * @param int[] $userIds
 * @return array<int, array<string>> Map of user_id => [tokens]
 */
function get_user_device_tokens(mysqli $conn, array $userIds): array {
    $out = [];
    $ids = array_values(array_unique(array_map('intval', array_filter($userIds, fn($v)=> (int)$v>0))));
    if (empty($ids)) return $out;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $sql = "SELECT user_id, token FROM device_tokens WHERE active=1 AND user_id IN ($ph)";
    if ($st = $conn->prepare($sql)) {
        $params = [];
        $params[] = & $types;
        foreach ($ids as $i => $val) { $params[] = & $ids[$i]; }
        call_user_func_array([$st, 'bind_param'], $params);
        if ($st->execute() && ($res = $st->get_result())) {
            while ($r = $res->fetch_assoc()) {
                $uid = (int)$r['user_id'];
                $tok = (string)$r['token'];
                if (!isset($out[$uid])) $out[$uid] = [];
                $out[$uid][] = $tok;
            }
        }
        $st->close();
    }
    return $out;
}

// Structured logger for notification events
function notify_log(string $message, array $context = []): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if (!empty($context)) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";
    @file_put_contents(__DIR__ . '/../../logs/notifications_log.txt', $line, FILE_APPEND);
}

/**
 * Send an FCM push to a list of device tokens
 * @param string[] $tokens
 * @param string $title
 * @param string $body
 * @param array $data
 * @return array{sent:int, failed:int, responses:array}
 */
function fcm_send_tokens(array $tokens, string $title, string $body, array $data = []): array {
    if (empty($tokens) || !defined('FCM_SERVER_KEY') || !FCM_SERVER_KEY) {
        notify_log('FCM_SKIP', [
            'reason' => empty($tokens) ? 'no_tokens' : 'server_key_missing',
            'token_count' => count($tokens),
            'title' => $title,
            'data' => $data,
        ]);
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => []];
    }

    notify_log('FCM_SEND_ATTEMPT', [
        'token_count' => count($tokens),
        'title' => $title,
        'body' => $body,
        'data' => $data,
    ]);

    $payload = [
        'notification' => [
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
        ],
        'data' => $data,
    ];

    $final_results = ['sent' => 0, 'failed' => 0, 'responses' => []];

    // Chunk tokens into batches of <= 1000
    $chunks = array_chunk($tokens, 1000);
    foreach ($chunks as $idx => $chunk) {
        $json_payload = json_encode(array_merge($payload, ['registration_ids' => $chunk]));
        $response_json = _fcm_send_raw($json_payload);
        $succ = 0; $fail = 0;
        if ($response_json) {
            $response_data = json_decode($response_json, true);
            if (is_array($response_data)) {
                $succ = (int)($response_data['success'] ?? 0);
                $fail = (int)($response_data['failure'] ?? 0);
                $final_results['sent'] += $succ;
                $final_results['failed'] += $fail;
                if (!empty($response_data['results'])) {
                    $final_results['responses'] = array_merge($final_results['responses'], $response_data['results']);
                }
            }
        }
        notify_log('FCM_SEND_CHUNK_RESULT', [
            'chunk_index' => $idx,
            'chunk_size' => count($chunk),
            'success' => $succ,
            'failure' => $fail,
            'raw' => $response_json,
        ]);
    }
    notify_log('FCM_SEND_SUMMARY', $final_results + ['chunks' => count($chunks)]);
    return $final_results;
}

function _fcm_send_raw(string $json_payload): ?string {
    if (!defined('FCM_SERVER_KEY') || !FCM_SERVER_KEY) return null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://fcm.googleapis.com/fcm/send');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: key=' . FCM_SERVER_KEY,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    if ($result === false) {
        notify_log('FCM_HTTP_ERROR', ['curl_error' => curl_error($ch)]);
    }
    curl_close($ch);
    return $result ?: null;
}

?>
