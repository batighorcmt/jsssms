<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../bootstrap.php';
@include_once __DIR__ . '/../../config/notifications.php';
@include_once __DIR__ . '/../../config/db.php';
@include_once __DIR__ . '/../lib/notifications.php';

// Restrict to super_admins only
api_require_auth(['super_admin']);

function mask_tok($t){ $l=strlen($t); return $l>12?substr($t,0,8).'...'.substr($t,-4):str_repeat('*',$l); }

$mode = (defined('FCM_SERVER_KEY') && FCM_SERVER_KEY) ? 'legacy' : (defined('FIREBASE_SERVICE_ACCOUNT_FILE') && is_readable(FIREBASE_SERVICE_ACCOUNT_FILE) ? 'v1' : 'disabled');

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$directToken = isset($_GET['token']) ? trim($_GET['token']) : '';
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
  echo json_encode(['success'=>false,'error'=>'No tokens found. Provide ?token=... or ?user_id=...','mode'=>$mode]);
  exit;
}

$data = [
  'type' => 'diagnostic',
  'ts' => time(),
  'source' => $source,
];

$result = fcm_send_tokens($tokens, $title, $body, $data);

// Mask tokens in output
$masked = array_map('mask_tok', $tokens);

$response = [
  'success' => true,
  'mode' => $mode,
  'token_count' => count($tokens),
  'tokens_masked' => $masked,
  'result' => $result,
];

echo json_encode($response);
