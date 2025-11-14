<?php
// Common bootstrap for API endpoints
header('Content-Type: application/json; charset=utf-8');
// CORS for web (Chrome/Edge) development and deployment
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header('Access-Control-Allow-Origin: ' . $_SERVER['HTTP_ORIGIN']);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
@include_once __DIR__ . '/../config/config.php';
@include_once __DIR__ . '/../config/db.php';

function api_response($success, $payload = [], $code = 200) {
    http_response_code($code);
    if ($success) {
        echo json_encode(['success' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
    } else {
        // payload is error string or array
        $err = is_string($payload) ? $payload : ($payload['error'] ?? 'Error');
        $extra = is_array($payload) ? array_diff_key($payload, ['error'=>1]) : [];
        echo json_encode(array_merge(['success'=>false,'error'=>$err,'code'=>$code], $extra), JSON_UNESCAPED_UNICODE);
    }
    exit;
}

function get_auth_header_token() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if ($h && preg_match('~Bearer\s+(\S+)~i', $h, $m)) return $m[1];
    // Fallback: token query param for easier testing
    if (!empty($_GET['token'])) return $_GET['token'];
    return '';
}

$authUser = null; // ['id'=>, 'role'=>, 'name'=>]
$authTokenRow = null;

function api_require_auth($roles = []) {
    global $conn, $authUser, $authTokenRow;
    $token = get_auth_header_token();
    if (!$token) api_response(false, 'Missing bearer token', 401);
    $now = date('Y-m-d H:i:s');
    if ($stmt = $conn->prepare("SELECT t.user_id, t.role, t.token, t.expires, u.name, u.username FROM api_tokens t JOIN users u ON t.user_id=u.id WHERE t.token=? LIMIT 1")) {
        $stmt->bind_param('s', $token);
        if (!$stmt->execute()) api_response(false, 'Token lookup failed', 500);
        $res = $stmt->get_result();
        if (!$res || !$res->num_rows) api_response(false, 'Invalid token', 401);
        $r = $res->fetch_assoc();
        if (strtotime($r['expires']) < time()) api_response(false, 'Token expired', 401);
        $authUser = [
            'id' => (int)$r['user_id'],
            'role' => $r['role'],
            'name' => $r['name'],
            'username' => $r['username']
        ];
        $authTokenRow = $r;
        if (!empty($roles) && !in_array($authUser['role'], $roles)) api_response(false, 'Forbidden', 403);
    } else api_response(false, 'Prepare failed', 500);
}

function require_method($method) {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) api_response(false, 'Method not allowed', 405);
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    if ($j === null && trim($raw) !== '') api_response(false, 'Invalid JSON body', 400);
    return $j ?: [];
}

function validate_date($d) { return preg_match('~^\d{4}-\d{2}-\d{2}$~', $d); }

// Simple helper to generate token
function generate_token() { return bin2hex(random_bytes(32)); }

?>