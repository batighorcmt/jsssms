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
    if (!defined('FCM_ENABLED') || !FCM_ENABLED || empty($tokens)) {
        return ['sent' => 0, 'failed' => 0, 'responses' => []];
    }
    $url = 'https://fcm.googleapis.com/fcm/send';
    $headers = [
        'Content-Type: application/json',
        'Authorization: key=' . FCM_SERVER_KEY,
    ];
    $sent = 0; $failed = 0; $responses = [];

    // FCM allows up to 1000 registration_ids per request
    $chunks = array_chunk(array_values(array_unique(array_filter($tokens))), 1000);
    foreach ($chunks as $batch) {
        $payload = [
            'registration_ids' => $batch,
            'notification' => [
                'title' => $title,
                'body'  => $body,
                'sound' => 'default',
            ],
            'data' => $data,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $result = curl_exec($ch);
        $err = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $responses[] = ['code'=>$code,'error'=>$err,'body'=>$result];
        if ($code === 200 && $result) {
            $j = json_decode($result, true);
            if (isset($j['success'])) $sent += (int)$j['success'];
            if (isset($j['failure'])) $failed += (int)$j['failure'];
        } else {
            $failed += count($batch);
        }
    }
    return ['sent'=>$sent,'failed'=>$failed,'responses'=>$responses];
}

?>
