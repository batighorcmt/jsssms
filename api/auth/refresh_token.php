<?php
require_once __DIR__ . '/../bootstrap.php';

// Token refresh endpoint: extends existing token's expiry by 30 days
require_method('POST');

$token = get_auth_header_token();
if (!$token) api_response(false, 'Missing bearer token', 401);

$now = date('Y-m-d H:i:s');
$stmt = $conn->prepare("SELECT user_id, role FROM api_tokens WHERE token=? LIMIT 1");
if (!$stmt) api_response(false, 'Database error', 500);

$stmt->bind_param('s', $token);
if (!$stmt->execute()) api_response(false, 'Query failed', 500);

$res = $stmt->get_result();
if (!$res || !$res->num_rows) api_response(false, 'Invalid token', 401);

$r = $res->fetch_assoc();
$stmt->close();

// Extend token expiry by 30 days from now
$newExpiry = date('Y-m-d H:i:s', time() + 2592000);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$upd = $conn->prepare("UPDATE api_tokens SET expires=?, last_ip=? WHERE token=?");
if (!$upd) api_response(false, 'Database error', 500);

$upd->bind_param('sss', $newExpiry, $ip, $token);
if (!$upd->execute()) api_response(false, 'Token refresh failed', 500);

api_response(true, [
    'token' => $token,
    'expires' => $newExpiry,
    'message' => 'Token refreshed successfully'
]);
?>
