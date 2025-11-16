<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';
api_response(false, ['error' => 'Notifications diagnostics removed', 'code' => 410]);
/* Notifications system removed; legacy code below is commented out to prevent parsing.

function path_info_status(string $dir, string $file): array {
    $exists = is_dir($dir);
    $writable = $exists ? is_writable($dir) : false;
    $fileExists = file_exists($file);
    $fileReadable = $fileExists ? is_readable($file) : false;
    return [
        'dir' => $dir,
        'exists' => $exists,
        'writable' => $writable,
        'file' => $file,
        'file_exists' => $fileExists,
        'file_readable' => $fileReadable,
    ];
}

function tail_file_lines(string $file, int $maxLines = 50): array {
    if (!is_readable($file)) return [];
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    $count = count($lines);
    if ($count <= $maxLines) return $lines;
    return array_slice($lines, -$maxLines);
}

$base = dirname(__DIR__, 1); // api/
$projectRoot = dirname($base, 1); // project root
$logsDir = $projectRoot . '/logs';
$logFile = $logsDir . '/notifications_log.txt';
$tmpDir = sys_get_temp_dir();
$tmpFallback = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jsssms_notifications.log';

// DB stats (device tokens)
$deviceStats = [
    'total_active' => 0,
    'by_user' => null,
];
try {
    if ($conn) {
        if ($qr = $conn->query("SHOW TABLES LIKE 'device_tokens'")) {
            if ($qr->num_rows > 0) {
                if ($rs = $conn->query("SELECT COUNT(*) AS c FROM device_tokens WHERE active=1")) {
                    if ($row = $rs->fetch_assoc()) $deviceStats['total_active'] = (int)$row['c'];
                }
                $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
                if ($userId > 0) {
                    $by = ['user_id' => $userId, 'active_tokens' => 0, 'sample_tokens' => []];
                    if ($st = $conn->prepare('SELECT token FROM device_tokens WHERE active=1 AND user_id=? LIMIT 5')) {
                        $st->bind_param('i', $userId);
                        if ($st->execute() && ($res = $st->get_result())) {
                            $tokens = [];
                            while ($r = $res->fetch_assoc()) { $tokens[] = (string)$r['token']; }
                            $by['active_tokens'] = count($tokens);
                            foreach ($tokens as $t) {
                                $len = strlen($t);
                                $mask = $len > 12 ? (substr($t,0,8) . '...' . substr($t,-4)) : str_repeat('*', $len);
                                $by['sample_tokens'][] = $mask;
                            }
                        }
                        $st->close();
                    }
                    $deviceStats['by_user'] = $by;
                }
            }
        }
    }
} catch (Throwable $e) {
    // ignore DB diagnostics failures
}

// curl availability
$curlAvailable = function_exists('curl_init');
$opensslAvailable = extension_loaded('openssl');

$saFile = defined('FIREBASE_SERVICE_ACCOUNT_FILE') ? FIREBASE_SERVICE_ACCOUNT_FILE : null;
$saExists = $saFile && is_readable($saFile);
$projectId = null; $tokenPreview = null; $tokenLen = 0; $tokenError = null;
$clientEmail = null; $privateKeyId = null; $keyFingerprint = null;
if ($saExists) {
    $json = json_decode(@file_get_contents($saFile), true);
    if (is_array($json)) {
        $projectId = $json['project_id'] ?? null;
        $clientEmail = $json['client_email'] ?? null;
        $privateKeyId = $json['private_key_id'] ?? null;
        $pk = (string)($json['private_key'] ?? '');
        if ($pk !== '') {
            // Fingerprint without exposing the key: sha256 of normalized content, first 12 chars
            if (strpos($pk, "\\n") !== false) { $pk = str_replace("\\n", "\n", $pk); }
            $keyFingerprint = substr(hash('sha256', $pk), 0, 12);
        }
    }
    // Attempt short token fetch (do not fail whole diagnostics)
    if (function_exists('firebase_v1_get_access_token')) {
        try {
            $tok = firebase_v1_get_access_token();
            if ($tok) { $tokenLen = strlen($tok); $tokenPreview = substr($tok,0,12) . '...'; }
        } catch (Throwable $e) { $tokenError = $e->getMessage(); }
    }
}

$payload = [
    'server_time' => date('c'),
    'fcm' => [
        'legacy_server_key_len' => defined('FCM_SERVER_KEY') ? strlen((string)FCM_SERVER_KEY) : 0,
        'curl_available' => $curlAvailable,
        'openssl_available' => $opensslAvailable,
        'mode' => (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY) ? 'legacy' : ($saExists ? 'v1' : 'disabled'),
        'v1' => [
            'service_account_file' => $saFile,
            'service_account_exists' => $saExists,
            'project_id' => $projectId,
            'client_email' => $clientEmail,
            'private_key_id' => $privateKeyId,
            'private_key_fingerprint' => $keyFingerprint,
            'access_token_len' => $tokenLen,
            'access_token_preview' => $tokenPreview,
            'access_token_error' => $tokenError,
        ],
    ],
    'paths' => [
        'logs' => path_info_status($logsDir, $logFile),
        'tmp_fallback' => [
            'dir' => $tmpDir,
            'writable' => is_writable($tmpDir),
            'file' => $tmpFallback,
            'file_exists' => file_exists($tmpFallback),
            'file_readable' => is_readable($tmpFallback),
        ],
    ],
    'device_tokens' => $deviceStats,
    'recent_logs' => file_exists($logFile) ? tail_file_lines($logFile, 40) : (file_exists($tmpFallback) ? tail_file_lines($tmpFallback, 40) : []),
];

api_response(true, $payload);
