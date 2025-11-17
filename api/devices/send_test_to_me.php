<?php
// Sends a test notification to the authenticated user
require_once __DIR__ . '/../../api/bootstrap.php';
@include_once __DIR__ . '/../lib/fcm.php';

require_method('POST');
api_require_auth();
global $conn, $authUser;

$json = read_json_body();
$title = isset($json['title']) ? (string)$json['title'] : 'Test Notification';
$body  = isset($json['body'])  ? (string)$json['body']  : 'Hello from FCM self-test';
$validate = isset($json['validate']) ? (bool)$json['validate'] : false;

$uid = (int)($authUser['id'] ?? 0);
if ($uid <= 0) api_response(false, 'Auth error', 401);

try {
    $result = fcm_send_to_user($conn, $uid, $title, $body, ['type' => 'self_test'], $validate);
    api_response(true, ['result' => $result]);
} catch (Throwable $e) {
    api_response(false, ['error' => 'send_failed', 'message' => $e->getMessage()], 500);
}

?>
