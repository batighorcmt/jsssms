<?php
// Lightweight FCM v1 sender using a Firebase service account JSON.
// Reads credentials from config/firebase_service_account.json
// Exposes helpers to send to tokens and to user_id tokens.

if (!function_exists('fcm_base64url_encode')) {
    function fcm_base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('fcm_log')) {
    function fcm_log(string $message): void {
        $dir = __DIR__ . '/../../logs';
        $file = __DIR__ . '/../../logs/notifications_log.txt';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($file, "[$ts] $message\n", FILE_APPEND);
    }
}

if (!function_exists('fcm_load_sa')) {
    function fcm_load_sa(): ?array {
        $path = __DIR__ . '/../../config/firebase_service_account.json';
        if (!is_file($path)) {
            fcm_log('FCM: service account file missing at ' . $path);
            return null;
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            fcm_log('FCM: failed to parse service account json');
            return null;
        }
        return $data;
    }
}

if (!function_exists('fcm_get_access_token')) {
    function fcm_get_access_token(): ?string {
        $sa = fcm_load_sa();
        if (!$sa) return null;

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $now = time();
        $claim = [
            'iss' => $sa['client_email'] ?? '',
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $jwtHeader = fcm_base64url_encode(json_encode($header));
        $jwtClaim  = fcm_base64url_encode(json_encode($claim));

        $privateKey = (string)($sa['private_key'] ?? '');
        // Normalize potential CRLF issues but preserve PEM markers
        $privateKey = preg_replace('~\r\n?~', "\n", trim($privateKey));
        $dataToSign = $jwtHeader . '.' . $jwtClaim;
        $signature = '';
        $keyResource = @openssl_pkey_get_private($privateKey);
        if (!$keyResource) {
            fcm_log('FCM: openssl_pkey_get_private failed (bad key format?)');
            return null;
        }
        $ok = openssl_sign($dataToSign, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        if (!$ok || !$signature) {
            fcm_log('FCM: openssl_sign failed: ' . (function_exists('openssl_error_string') ? (openssl_error_string() ?: 'unknown') : 'no openssl_error_string'));
            return null;
        }

        $jwt = $dataToSign . '.' . fcm_base64url_encode($signature);

        $tokenUri = $sa['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $ch = curl_init($tokenUri);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            fcm_log('FCM: token http error: ' . $err);
            return null;
        }
        $json = json_decode($resp, true);
        if ($http !== 200 || !isset($json['access_token'])) {
            fcm_log('FCM: token failure http=' . $http . ' body=' . $resp);
            return null;
        }
        return $json['access_token'];
    }
}

if (!function_exists('fcm_send_to_tokens')) {
    function fcm_send_to_tokens(array $tokens, string $title, string $body, array $dataPayload = [], bool $validateOnly = false): array {
        $sa = fcm_load_sa();
        if (!$sa) return ['ok' => false, 'error' => 'missing_service_account'];
        $projectId = $sa['project_id'] ?? '';
        if (!$projectId) return ['ok' => false, 'error' => 'missing_project_id'];
        if (empty($tokens)) return ['ok' => false, 'error' => 'no_tokens'];

        $access = fcm_get_access_token();
        if (!$access) return ['ok' => false, 'error' => 'token_generation_failed'];

        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send';
        $results = [];
        foreach ($tokens as $t) {
            $payload = [
                'message' => [
                    'token' => $t,
                    'notification' => [
                        'title' => $title,
                        'body'  => $body,
                    ],
                    // Ensure heads-up with sound on Android & lock-screen on iOS
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => [
                            'channel_id' => 'jsssms_channel_high',
                            'sound' => 'default',
                            'priority' => 'PRIORITY_HIGH',
                            'visibility' => 'PUBLIC',
                        ],
                    ],
                    'apns' => [
                        'headers' => [ 'apns-priority' => '10' ],
                        'payload' => [
                            'aps' => [
                                'alert' => ['title' => $title, 'body' => $body],
                                'sound' => 'default',
                                'badge' => 1,
                                'content-available' => 0,
                            ],
                        ],
                    ],
                    'data' => $dataPayload,
                ],
                'validate_only' => $validateOnly,
            ];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $access,
                    'Content-Type: application/json',
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_TIMEOUT => 15,
            ]);
            $resp = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($resp === false) {
                $results[] = ['token' => $t, 'ok' => false, 'http' => 0, 'error' => $err];
                fcm_log('FCM: send curl error: ' . $err);
            } else {
                $results[] = ['token' => $t, 'ok' => ($http>=200 && $http<300), 'http' => $http, 'body' => $resp];
                if ($http<200 || $http>=300) {
                    fcm_log('FCM: send fail http=' . $http . ' resp=' . $resp);
                }
            }
        }
        return ['ok' => true, 'results' => $results];
    }
}

if (!function_exists('fcm_get_user_tokens')) {
    function fcm_get_user_tokens(mysqli $conn, int $userId): array {
        $tokens = [];
        $conn->query("CREATE TABLE IF NOT EXISTS fcm_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token TEXT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token(191)),
  INDEX idx_user (user_id)
)");
        $stmt = $conn->prepare('SELECT token FROM fcm_tokens WHERE user_id=?');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            // Prefer get_result when available; otherwise fall back to bind_result
            if (method_exists($stmt, 'get_result')) {
                $res = $stmt->get_result();
                if ($res) { while ($r = $res->fetch_assoc()) { $tok = trim((string)$r['token']); if ($tok) $tokens[] = $tok; } }
            } else {
                $stmt->bind_result($tok);
                while ($stmt->fetch()) { $t = trim((string)$tok); if ($t) $tokens[] = $t; }
            }
            $stmt->close();
        }
        return array_values(array_unique($tokens));
    }
}

if (!function_exists('fcm_send_to_user')) {
    function fcm_send_to_user(mysqli $conn, int $userId, string $title, string $body, array $dataPayload = [], bool $validateOnly = false): array {
        $tokens = fcm_get_user_tokens($conn, $userId);
        if (empty($tokens)) {
            fcm_log('FCM: no tokens for user_id=' . $userId);
            return ['ok' => false, 'error' => 'no_tokens_for_user'];
        }
        $res = fcm_send_to_tokens($tokens, $title, $body, $dataPayload, $validateOnly);
        // Persist per-token send results for diagnostics
        if (is_array($res) && isset($res['results']) && is_array($res['results'])) {
            // Ensure logs table exists
            $tableSql = "CREATE TABLE IF NOT EXISTS fcm_send_logs (
              id BIGINT AUTO_INCREMENT PRIMARY KEY,
              user_id INT NULL,
              token TEXT NULL,
              title TEXT NULL,
              body TEXT NULL,
              data_payload TEXT NULL,
              http_code INT NULL,
              response_body TEXT NULL,
              success TINYINT(1) NOT NULL DEFAULT 0,
              created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
              INDEX idx_user (user_id)
            )";
            if (!$conn->query($tableSql)) {
                fcm_log('FCM: failed to create fcm_send_logs table: ' . ($conn->error ?? 'unknown error'));
            }
            $ins = $conn->prepare('INSERT INTO fcm_send_logs (user_id, token, title, body, data_payload, http_code, response_body, success) VALUES (?,?,?,?,?,?,?,?)');
            if ($ins) {
                foreach ($res['results'] as $r) {
                    $tok = $r['token'] ?? null;
                    $http = isset($r['http']) ? (int)$r['http'] : null;
                    $bodyResp = isset($r['body']) ? (string)$r['body'] : ($r['error'] ?? null);
                    $ok = !empty($r['ok']) ? 1 : 0;
                    $jsonData = null;
                    try { $jsonData = json_encode($dataPayload, JSON_UNESCAPED_UNICODE); } catch (Throwable $e) { $jsonData = null; }
                    $ins->bind_param('isssssis', $userId, $tok, $title, $body, $jsonData, $http, $bodyResp, $ok);
                    @$ins->execute();
                }
                $ins->close();
            }
        }
        return $res;
    }
}

?>
