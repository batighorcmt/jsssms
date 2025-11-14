<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

// ---- Simple token-based auth for API (no redirects) ----
header('Content-Type: application/json; charset=utf-8');

try {
    // get bearer token from Authorization header if present
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token   = '';

    if (preg_match('~^Bearer\\s+(.*)$~i', $auth, $m)) {
        $token = trim($m[1]);
    }

    // fallback: token via query ?token=...
    if ($token === '' && isset($_GET['token'])) {
        $token = trim((string)$_GET['token']);
    }

    // যদি আপনার api_require_auth() টোকেন থেকে ইউজার সেট করে, সেটা ব্যবহার করতে চাইলে:
    if ($token === '' && empty($_SESSION['id'])) {
        api_response(false, 'Unauthorized', 401);
    }
    // আপনি চাইলে এখানে টোকেন ভ্যালিডেট করে $_SESSION['id'] সেট করতে পারেন।
} catch (Throwable $e) {
    api_response(false, 'Auth error: '.$e->getMessage(), 401);
}

// ---- Actual seat plan list ----
$plans = [];
$sql = "SELECT id, plan_name, shift, status
        FROM seat_plans
        WHERE status='active'
        ORDER BY id DESC";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $plans[] = [
            'id'        => (int)$row['id'],
            'plan_name' => (string)$row['plan_name'],
            'shift'     => (string)$row['shift'],
            'status'    => (string)$row['status'],
        ];
    }
}

api_response(true, ['plans' => $plans]);