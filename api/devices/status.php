<?php
// Returns FCM tokens registered for the authenticated user
require_once __DIR__ . '/../../api/bootstrap.php';

require_method('GET');
api_require_auth();
global $conn, $authUser;

// Ensure table exists (noop if already)
$conn->query("CREATE TABLE IF NOT EXISTS fcm_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token TEXT NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_token (token(191)),
  INDEX idx_user (user_id)
)");

$uid = (int)($authUser['id'] ?? 0);
$list = [];
if ($stmt = $conn->prepare('SELECT token, created_at, updated_at FROM fcm_tokens WHERE user_id=? ORDER BY updated_at DESC')) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $list[] = [
                'token' => (string)$r['token'],
                'created_at' => (string)$r['created_at'],
                'updated_at' => (string)$r['updated_at'],
            ];
        }
    }
    $stmt->close();
}

api_response(true, ['tokens' => $list]);
?>
