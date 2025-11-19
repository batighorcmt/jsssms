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

// Primary path: ON DUPLICATE (by unique token prefix index)
$stmt = $conn->prepare('INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), updated_at=CURRENT_TIMESTAMP');
if ($stmt) {
    $stmt->bind_param('is', $userId, $token);
    $ok = @$stmt->execute();
    if (!$ok && function_exists('fcm_log')) { fcm_log('Register execute failed (upsert): '.$stmt->error); }
    $stmt->close();
} else {
    $ok = false;
    if (function_exists('fcm_log')) { fcm_log('Register prepare failed (upsert): '.$conn->error); }
}

// Fallback 1: REPLACE (delete + insert on unique key)
if (!$ok) {
    $stmt2 = $conn->prepare('REPLACE INTO fcm_tokens (user_id, token) VALUES (?, ?)');
    if ($stmt2) {
        $stmt2->bind_param('is', $userId, $token);
        $ok = @$stmt2->execute();
        if (!$ok && function_exists('fcm_log')) { fcm_log('Register execute failed (REPLACE): '.$stmt2->error); }
        $stmt2->close();
    }
}

// Fallback 2: explicit delete by exact token then insert
if (!$ok) {
    $del = $conn->prepare('DELETE FROM fcm_tokens WHERE token = ?');
    if ($del) { $del->bind_param('s', $token); @$del->execute(); $del->close(); }
    $ins = $conn->prepare('INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?)');
    if ($ins) { $ins->bind_param('is', $userId, $token); $ok = @$ins->execute(); $ins->close(); }
}

api_response($ok, $ok ? ['message'=>'token_saved'] : ['error'=>'db_execute_failed','db_error'=>$conn->error ?: 'stmt_error'], $ok ? 200 : 500);
?>