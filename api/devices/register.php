<?php
// Registers/updates an FCM device token for a user.
// Accepts POST: user_id (int), token (string)
header('Content-Type: application/json');

@include_once __DIR__ . '/../../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
@include_once __DIR__ . '/../../config/config.php';
include __DIR__ . '/../../config/db.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'code' => 405]);
    exit;
}

$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$token  = isset($_POST['token']) ? trim((string)$_POST['token']) : '';

// Allow session user if user_id is not specified explicitly
if ($userId <= 0 && isset($_SESSION['id'])) {
    $userId = (int)$_SESSION['id'];
}

if ($userId <= 0 || $token === '') {
    echo json_encode(['ok' => false, 'error' => 'missing_params', 'code' => 422]);
    exit;
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

// Upsert behavior: keep multiple tokens per user (different devices); unique by token
$stmt = $conn->prepare('INSERT INTO fcm_tokens (user_id, token) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), updated_at=CURRENT_TIMESTAMP');
if (!$stmt) {
    echo json_encode(['ok' => false, 'error' => 'prepare_failed']);
    exit;
}
$stmt->bind_param('is', $userId, $token);
$ok = @$stmt->execute();
$stmt->close();

if ($ok) {
    echo json_encode(['ok' => true, 'message' => 'token_saved']);
} else {
    echo json_encode(['ok' => false, 'error' => 'db_execute_failed']);
}
?>
<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';
api_response(false, ['error' => 'Device token registration removed', 'code' => 410]);
/* Notifications system removed; legacy code below is commented out to prevent parsing.
