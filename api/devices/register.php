<?php

// Registers/updates an FCM device token for a user.
// Accepts:
//  - Authorization: Bearer <api_token> (preferred)
//  - Body: JSON { token, platform? } OR form-data user_id, token

require_once __DIR__ . '/../../api/bootstrap.php';

require_method('POST');

// Prefer API auth
api_require_auth();
global $conn, $authUser;

$json = read_json_body();

$token = '';
if (isset($json['token'])) {
    $token = trim((string)$json['token']);
} elseif (isset($_POST['token'])) {
    $token = trim((string)$_POST['token']);
}

// Use authenticated user id; do not allow spoofing via body
$userId = (int)($authUser['id'] ?? 0);

if ($userId <= 0 || $token === '') {
    api_response(false, 'Missing params', 422);
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS fcm_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token TEXT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token(191)),
  INDEX idx_user (user_id)
)");

$stmt = $conn->prepare('INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), updated_at=CURRENT_TIMESTAMP');
if (!$stmt) {
    if (function_exists('fcm_log')) { fcm_log('Register prepare failed: '.$conn->error); }
    api_response(false, ['error'=>'prepare_failed','db_error'=>$conn->error], 500);
}
$stmt->bind_param('is', $userId, $token);
$ok = @$stmt->execute();
if (!$ok && function_exists('fcm_log')) { fcm_log('Register execute failed: '.$stmt->error); }
$stmt->close();

if ($ok) {
    api_response(true, ['message' => 'token_saved']);
} else {
    api_response(false, ['error'=>'db_execute_failed','db_error'=>$conn->error ?: 'stmt_error'], 500);
}
?>