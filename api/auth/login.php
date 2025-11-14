<?php
// Suppress all error reporting to prevent HTML output
ini_set('display_errors', 0);
error_reporting(0);

// Set the content type to JSON for all responses
header('Content-Type: application/json');

// Use a try-catch block to handle potential fatal errors, like a failed include
try {
    // Correctly include the database and helper functions using absolute paths
    require_once __DIR__ . '/../../config/db.php';
    require_once __DIR__ . '/../bootstrap.php';

    require_method('POST');

    $body = read_json_body();
    $username = trim($body['username'] ?? '');
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'error' => 'Username and password required']);
        exit;
    }

    // Look up user
    if ($stmt = $conn->prepare("SELECT id, username, password, name, role FROM users WHERE username=? LIMIT 1")) {
        $stmt->bind_param('s', $username);
        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'error' => 'Query failed']);
            exit;
        }
        $res = $stmt->get_result();
        if (!$res || $res->num_rows === 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            exit;
        }
        $u = $res->fetch_assoc();
        if (!password_verify($password, $u['password'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
            exit;
        }

        // Generate / persist token (24h expiry)
        $token = generate_token();
        $expires = date('Y-m-d H:i:s', time() + 86400);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($ins = $conn->prepare("INSERT INTO api_tokens (user_id, role, token, expires, last_ip) VALUES (?,?,?,?,?)")) {
            $ins->bind_param('issss', $u['id'], $u['role'], $token, $expires, $ip);
            if (!$ins->execute()) {
                echo json_encode(['success' => false, 'error' => 'Token issue failed']);
                exit;
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Token prepare failed']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'token' => $token,
                'expires' => $expires,
                'user' => [
                    'id' => (int)$u['id'],
                    'username' => $u['username'],
                    'name' => $u['name'],
                    'role' => $u['role']
                ]
            ]
        ]);

    } else {
        echo json_encode(['success' => false, 'error' => 'Database statement prepare failed.']);
    }
} catch (Throwable $e) {
    // Catch any other errors (like failed includes) and return a JSON response
    echo json_encode(['success' => false, 'error' => 'A server error occurred. Please check server logs.']);
}
?>
