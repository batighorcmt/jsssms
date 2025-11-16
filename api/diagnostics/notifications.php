<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';
@include_once __DIR__ . '/../../config/notifications.php';
@include_once __DIR__ . '/../../config/db.php';

// Restrict to super_admin
api_require_auth(['super_admin']);

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

$payload = [
    'server_time' => date('c'),
    'fcm' => [
        'enabled' => defined('FCM_ENABLED') ? (bool)FCM_ENABLED : false,
        'server_key_len' => defined('FCM_SERVER_KEY') ? strlen((string)FCM_SERVER_KEY) : 0,
        'sender_id_present' => defined('FCM_SENDER_ID') ? ((string)FCM_SENDER_ID !== '') : false,
        'curl_available' => $curlAvailable,
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
