<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

require_method('POST');
// Require any authenticated user (teachers will use this in app)
api_require_auth(['teacher','super_admin']);

$body = read_json_body();
$token = trim((string)($body['token'] ?? ''));
$platform = strtolower(trim((string)($body['platform'] ?? 'android')));
if ($token === '') api_response(false, 'Missing token', 400);
if (!in_array($platform, ['android','ios','web'], true)) $platform = 'android';

global $authUser, $conn;
$userId = (int)$authUser['id'];

// Ensure table exists (idempotent create)
$conn->query("CREATE TABLE IF NOT EXISTS device_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  platform VARCHAR(16) NOT NULL DEFAULT 'android',
  token VARCHAR(255) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Upsert by token
if ($st = $conn->prepare("INSERT INTO device_tokens (user_id, platform, token, active) VALUES (?,?,?,1)
    ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), platform=VALUES(platform), active=1")) {
    $st->bind_param('iss', $userId, $platform, $token);
    if ($st->execute()) {
        $st->close();
        api_response(true, ['ok'=>true]);
    } else {
        $err = $conn->error;
        $st->close();
        api_response(false, 'Failed to save token: '.$err, 500);
    }
} else {
    api_response(false, 'Prepare failed', 500);
}

?>
