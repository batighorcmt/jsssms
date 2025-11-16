<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';
  api_response(false, ['error' => 'Notifications diagnostics removed', 'code' => 410]);
/* Notifications system removed; legacy code below is commented out to prevent parsing.

// Restrict to super_admins only
api_require_auth(['super_admin']);
// Capture which API token was used (to avoid confusing it with a device token param)
$authTokenUsed = function_exists('get_auth_header_token') ? get_auth_header_token() : '';

function mask_tok($t){ $l=strlen($t); return $l>12?substr($t,0,8).'...'.substr($t,-4):str_repeat('*',$l); }

$mode = (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY) ? 'legacy' : (defined('FIREBASE_SERVICE_ACCOUNT_FILE') && is_readable(FIREBASE_SERVICE_ACCOUNT_FILE) ? 'v1' : 'disabled');

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
// Optional: resolve user by username/contact if provided
$username = isset($_GET['username']) ? trim((string)$_GET['username']) : '';
if ($userId <= 0 && $username !== '' && isset($conn)) {
  if ($st = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1')) {
    $st->bind_param('s', $username);
    if ($st->execute() && ($res = $st->get_result()) && ($row = $res->fetch_assoc())) {
      $userId = (int)$row['id'];
    }
    $st->close();
  }
}
// Device token input: prefer 'device' or 'device_token'; fall back to 'token' only if it is not the API auth token
$directToken = '';
if (isset($_GET['device'])) { $directToken = trim((string)$_GET['device']); }
elseif (isset($_GET['device_token'])) { $directToken = trim((string)$_GET['device_token']); }
elseif (isset($_GET['token'])) {
  $candidate = trim((string)$_GET['token']);
  if ($authTokenUsed === '' || $candidate !== $authTokenUsed) {
    $directToken = $candidate; // treat as device token only if not the auth token
  }
}
$title = isset($_GET['title']) ? trim($_GET['title']) : 'JSS Diagnostic Test';
$body = isset($_GET['body']) ? trim($_GET['body']) : 'Test push from diagnostics endpoint';

$tokens = [];
$source = null;
if ($directToken !== '') {
  $tokens = [$directToken];
  $source = 'direct';
} elseif ($userId > 0 && isset($conn)) {
  $map = get_user_device_tokens($conn, [$userId]);
  $tokens = $map[$userId] ?? [];
  $source = 'user_id';
}

if (empty($tokens)) {
  echo json_encode([
    'success'=>false,
    'error'=>'No tokens found. Provide ?device=... or ?user_id=... or ?username=...',
    'mode'=>$mode,
    <?php
    header('Content-Type: application/json');
    require_once __DIR__ . '/../bootstrap.php';
    api_response(false, ['error' => 'Notifications diagnostics removed', 'code' => 410]);

