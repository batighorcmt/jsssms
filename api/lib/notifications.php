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

    // Preferred location in project logs directory
    $base = dirname(__DIR__, 2); // project root
    $logDir = $base . '/logs';
    $logFile = $logDir . '/notifications_log.txt';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $ok = @file_put_contents($logFile, $line, FILE_APPEND);
    if ($ok === false) {
        // Fallback to system temp (works in many shared hosts/cPanel)
        $tmpFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jsssms_notifications.log';
        $ok2 = @file_put_contents($tmpFile, $line, FILE_APPEND);
        if ($ok2 === false) {
            // Last resort: PHP error_log
            @error_log($line);
        }
    }
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
    // Decide path: legacy key present -> legacy; else attempt v1 service account
    if (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY) {
        return fcm_send_tokens_legacy($tokens, $title, $body, $data);
    }
    return fcm_send_tokens_v1($tokens, $title, $body, $data);
}

// Legacy HTTP API sender (unchanged logic moved here)
function fcm_send_tokens_legacy(array $tokens, string $title, string $body, array $data = []): array {
    if (empty($tokens) || !defined('FCM_SERVER_KEY') || !FCM_SERVER_KEY) {
        notify_log('FCM_LEGACY_SKIP', [
            'reason' => empty($tokens) ? 'no_tokens' : 'server_key_missing',
            'token_count' => count($tokens),
            'title' => $title,
            'data' => $data,
        ]);
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => [], 'mode' => 'legacy'];
    }
    notify_log('FCM_LEGACY_SEND_ATTEMPT', [
        'token_count' => count($tokens), 'title' => $title, 'body' => $body, 'data' => $data
    ]);
    $payload = [
        'notification' => ['title' => $title, 'body' => $body, 'sound' => 'default'],
        'data' => $data,
    ];
    $final = ['sent' => 0, 'failed' => 0, 'responses' => [], 'mode' => 'legacy'];
    $chunks = array_chunk($tokens, 1000);
    foreach ($chunks as $idx => $chunk) {
        $json_payload = json_encode(array_merge($payload, ['registration_ids' => $chunk]));
        $resp_json = _fcm_send_raw($json_payload);
        $succ = 0; $fail = 0; $results = [];
        if ($resp_json) {
            $resp_data = json_decode($resp_json, true);
            if (is_array($resp_data)) {
                $succ = (int)($resp_data['success'] ?? 0);
                $fail = (int)($resp_data['failure'] ?? 0);
                $results = $resp_data['results'] ?? [];
            }
        }
        $final['sent'] += $succ; $final['failed'] += $fail; $final['responses'] = array_merge($final['responses'], $results);
        notify_log('FCM_LEGACY_CHUNK_RESULT', ['chunk_index' => $idx, 'success' => $succ, 'failure' => $fail, 'raw' => $resp_json]);
    }
    notify_log('FCM_LEGACY_SUMMARY', $final + ['chunks' => count($chunks)]);
    return $final;
}

// FCM HTTP v1 sender (service account)
function fcm_send_tokens_v1(array $tokens, string $title, string $body, array $data = []): array {
    if (empty($tokens)) {
        notify_log('FCM_V1_SKIP', ['reason' => 'no_tokens']);
        return ['sent' => 0, 'failed' => 0, 'responses' => [], 'mode' => 'v1'];
    }
    $saFile = defined('FIREBASE_SERVICE_ACCOUNT_FILE') ? FIREBASE_SERVICE_ACCOUNT_FILE : null;
    if (!$saFile || !is_readable($saFile)) {
        notify_log('FCM_V1_SKIP', ['reason' => 'service_account_missing', 'file' => $saFile]);
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => [], 'mode' => 'v1'];
    }
    $accessToken = firebase_v1_get_access_token();
    if (!$accessToken) {
        notify_log('FCM_V1_SKIP', ['reason' => 'access_token_failed']);
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => [], 'mode' => 'v1'];
    }
    $projectId = firebase_v1_get_project_id();
    if (!$projectId) {
        notify_log('FCM_V1_SKIP', ['reason' => 'project_id_missing']);
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => [], 'mode' => 'v1'];
    }
    $validateOnly = !empty($data['_validate_only']);
    // Do not send internal flag as app data
    if ($validateOnly) { unset($data['_validate_only']); }
    notify_log('FCM_V1_SEND_ATTEMPT', ['token_count' => count($tokens), 'title' => $title, 'body' => $body, 'validate_only' => $validateOnly]);
    $endpoint = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send' . ($validateOnly ? '?validate_only=true' : '');
    $results = []; $sent = 0; $failed = 0;
    foreach ($tokens as $t) {
        $message = [
            'message' => [
                'token' => $t,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $data,
                'android' => [ 'priority' => 'HIGH' ],
            ]
        ];
        $json = json_encode($message);
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $resp = curl_exec($ch);
        $err = $resp === false ? curl_error($ch) : null;
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($err) {
            $failed++; $results[] = ['token' => mask_token($t), 'error' => $err];
            notify_log('FCM_V1_HTTP_ERROR', ['token_masked' => mask_token($t), 'error' => $err]);
            continue;
        }
        $respData = json_decode($resp, true);
        if ($code >= 200 && $code < 300 && isset($respData['name'])) {
            // In validate_only mode, server returns empty object on success; handle both
            if ($validateOnly && empty($respData)) {
                $sent++; $results[] = ['token' => mask_token($t), 'validated' => true];
            } else {
                $sent++; $results[] = ['token' => mask_token($t), 'name' => $respData['name']];
            }
        } else {
            $failed++; $results[] = ['token' => mask_token($t), 'http_code' => $code, 'response' => $respData];
        }
    }
    $final = ['sent' => $sent, 'failed' => $failed, 'responses' => $results, 'mode' => 'v1', 'validate_only' => $validateOnly];
    notify_log('FCM_V1_SUMMARY', $final);
    return $final;
}

function mask_token(string $t): string {
    $len = strlen($t); return $len > 12 ? substr($t,0,8) . '...' . substr($t,-4) : str_repeat('*', $len);
}

function firebase_v1_get_project_id(): ?string {
    static $pid = null; if ($pid !== null) return $pid;
    $file = defined('FIREBASE_SERVICE_ACCOUNT_FILE') ? FIREBASE_SERVICE_ACCOUNT_FILE : null;
    if (!$file || !is_readable($file)) return null;
    $json = json_decode(@file_get_contents($file), true);
    if (!is_array($json)) return null;
    $pid = $json['project_id'] ?? null; return $pid ?: null;
}

function firebase_v1_get_access_token(): ?string {
    static $cached = null; static $exp = 0;
    if ($cached && time() < $exp - 60) return $cached; // reuse until near expiry
    $file = defined('FIREBASE_SERVICE_ACCOUNT_FILE') ? FIREBASE_SERVICE_ACCOUNT_FILE : null;
    if (!$file || !is_readable($file)) return null;
    $sa = json_decode(@file_get_contents($file), true);
    if (!is_array($sa)) return null;
    $email = $sa['client_email'] ?? null; $key = $sa['private_key'] ?? null; $kid = $sa['private_key_id'] ?? null;
    if (!$email || !$key) return null;
    // Normalize key newlines in case of accidental literal \n
    if (strpos($key, "\\n") !== false) { $key = str_replace("\\n", "\n", $key); }
    // Obtain private key resource
    $pkey = openssl_pkey_get_private($key);
    if ($pkey === false) {
        $err = function_exists('openssl_error_string') ? openssl_error_string() : 'unknown';
        notify_log('FCM_V1_PKEY_LOAD_FAIL', ['error' => $err]);
        return null;
    }
    $iat = time(); $expTime = $iat + 3600;
    $jwtHeader = ['alg' => 'RS256','typ'=>'JWT'];
    if ($kid) { $jwtHeader['kid'] = $kid; }
    $header = base64url_encode(json_encode($jwtHeader));
    $claims = base64url_encode(json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $iat,
        'exp' => $expTime,
    ]));
    $unsigned = $header . '.' . $claims;
    $signature = '';
    $ok = openssl_sign($unsigned, $signature, $pkey, OPENSSL_ALGO_SHA256);
    if (!$ok) { notify_log('FCM_V1_JWT_SIGN_FAIL', []); return null; }
    if (PHP_VERSION_ID >= 80000 && is_object($pkey)) {
        // PHP 8 returns OpenSSLAsymmetricKey; freeing not necessary but safe
    } else {
        @openssl_pkey_free($pkey);
    }
    $jwt = $unsigned . '.' . base64url_encode($signature);
    $postFields = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postFields, CURLOPT_RETURNTRANSFER => true]);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $resp = curl_exec($ch); $err = $resp === false ? curl_error($ch) : null; curl_close($ch);
    if ($err) { notify_log('FCM_V1_TOKEN_HTTP_ERROR', ['error' => $err]); return null; }
    $data = json_decode($resp, true); $token = $data['access_token'] ?? null; $ttl = (int)($data['expires_in'] ?? 0);
    if ($token) { $cached = $token; $exp = time() + ($ttl ?: 3600); notify_log('FCM_V1_TOKEN_OK', ['len' => strlen($token), 'expires_in' => $ttl]); }
    else { notify_log('FCM_V1_TOKEN_FAIL', ['response' => $data]); }
    return $token ?: null;
}

function base64url_encode(string $in): string { return rtrim(strtr(base64_encode($in), '+/=', '-_'), '='); }

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

// Simple local self-test helper (non-production). Returns summary without sending if no tokens.
function fcm_self_test(): array {
    $dummyToken = 'TEST_TOKEN_1234567890';
    $res = fcm_send_tokens([$dummyToken], 'Test Title', 'Test Body', ['ping' => '1']);
    return $res;
}

?>
