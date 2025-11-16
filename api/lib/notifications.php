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
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => []];
    }

    // Log the attempt
    $log_msg = sprintf(
        "[%s] FCM_SEND: Attempting to send to %d tokens. Title: %s\n",
        date('Y-m-d H:i:s'),
        count($tokens),
        $title
    );
    @file_put_contents(__DIR__ . '/../../logs/sms_log.txt', $log_msg, FILE_APPEND);

    $payload = [
        'notification' => [
            'title' => $title,
            'body'  => $body,
            'sound' => 'default',
        ],
        'data' => $data
    ];

    $final_results = ['sent' => 0, 'failed' => 0, 'responses' => []];

    // Chunk tokens into batches of <= 1000
    $chunks = array_chunk($tokens, 1000);
    foreach ($chunks as $chunk) {
        $json_payload = json_encode(array_merge($payload, ['registration_ids' => $chunk]));
        $response_json = _fcm_send_raw($json_payload);
        if ($response_json) {
            $response_data = json_decode($response_json, true);
            if (is_array($response_data)) {
                $final_results['sent'] += (int)($response_data['success'] ?? 0);
                $final_results['failed'] += (int)($response_data['failure'] ?? 0);
                if (!empty($response_data['results'])) {
                    $final_results['responses'] = array_merge($final_results['responses'], $response_data['results']);
                }
            }
        }
    }
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

    // Log the response from FCM server
    $log_resp = sprintf(
        "[%s] FCM_RESPONSE: %s\n",
        date('Y-m-d H:i:s'),
        $result ?: 'cURL Error: ' . curl_error($ch)
    );
    @file_put_contents(__DIR__ . '/../../logs/sms_log.txt', $log_resp, FILE_APPEND);

    curl_close($ch);
    return $result ?: null;
}

?>
