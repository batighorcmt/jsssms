<?php
header('Content-Type: application/json');

@include_once __DIR__ . '/../../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
@include_once __DIR__ . '/../../config/config.php';
include __DIR__ . '/../../config/db.php';
@include_once __DIR__ . '/../lib/fcm.php';

// Restrict: super_admin only to avoid abuse
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    echo json_encode(['ok' => false, 'error' => 'forbidden', 'code' => 403]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'code' => 405]);
    exit;
}

// Params: token or user_id (one required), title, body, validate (0/1)
$token = isset($_REQUEST['token']) ? trim((string)$_REQUEST['token']) : '';
$userId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
$title = isset($_REQUEST['title']) ? (string)$_REQUEST['title'] : 'Test Notification';
$body  = isset($_REQUEST['body']) ? (string)$_REQUEST['body'] : 'Hello from diagnostics';
$validate = isset($_REQUEST['validate']) ? (int)$_REQUEST['validate'] : 0;

if ($token === '' && $userId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'provide_token_or_user_id', 'code' => 422]);
    exit;
}

try {
    if ($token !== '') {
        $result = fcm_send_to_tokens([$token], $title, $body, ['type' => 'diagnostic'], (bool)$validate);
    } else {
        $result = fcm_send_to_user($conn, $userId, $title, $body, ['type' => 'diagnostic'], (bool)$validate);
    }
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    if (function_exists('fcm_log')) { fcm_log('Diagnostics error: ' . $e->getMessage()); }
    echo json_encode(['ok' => false, 'error' => 'exception', 'message' => $e->getMessage()]);
}

?>
