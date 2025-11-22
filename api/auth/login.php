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

    // *** CRUCIAL FIX: Check database connection immediately after inclusion ***
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
        exit;
    }

    require_once __DIR__ . '/../bootstrap.php';

        // Ensure api_tokens table exists (auto-heal if missing) and has signed INTs matching users.id
    if (!function_exists('ensure_api_tokens_table')) {
        function ensure_api_tokens_table(mysqli $conn): bool {
                        $sql = "CREATE TABLE IF NOT EXISTS `api_tokens` (
                            `id` INT NOT NULL AUTO_INCREMENT,
                            `user_id` INT NOT NULL,
                            `role` VARCHAR(32) NOT NULL,
                            `token` VARCHAR(128) NOT NULL,
                            `expires` DATETIME NOT NULL,
                            `last_ip` VARCHAR(45) DEFAULT NULL,
                            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `uniq_token` (`token`),
                            KEY `idx_user` (`user_id`),
                            CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
                        // Attempt create; swallow errors if no permissions
                        @$conn->query($sql);

                        // Try to normalize existing schema to signed INT and correct FK
                        @$conn->query("ALTER TABLE `api_tokens` MODIFY `id` INT NOT NULL AUTO_INCREMENT");
                        @$conn->query("ALTER TABLE `api_tokens` MODIFY `user_id` INT NOT NULL");
                        // Drop and recreate FK with known name to ensure correctness
                        @$conn->query("ALTER TABLE `api_tokens` DROP FOREIGN KEY `fk_api_tokens_user`");
                        @$conn->query("ALTER TABLE `api_tokens` ADD CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE");

                        return true; // best effort
        }
    }

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

        // Enforce allowed roles
        $allowed_roles = ['teacher', 'super_admin'];
        if (!in_array($u['role'] ?? '', $allowed_roles)) {
            echo json_encode(['success' => false, 'error' => 'Only teachers and admins can log in']);
            exit;
        }

        // Generate / persist token (30 days expiry)
        $token = generate_token();
        $expires = date('Y-m-d H:i:s', time() + 2592000);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $insertSql = "INSERT INTO api_tokens (user_id, role, token, expires, last_ip) VALUES (?,?,?,?,?)";
        $ins = $conn->prepare($insertSql);
        if (!$ins) {
            // Table may be missing; try to create and retry
            ensure_api_tokens_table($conn);
            $ins = $conn->prepare($insertSql);
        }
        if (!$ins) {
            echo json_encode(['success' => false, 'error' => 'Token prepare failed']);
            exit;
        }
        $ins->bind_param('issss', $u['id'], $u['role'], $token, $expires, $ip);
        if (!$ins->execute()) {
            if ($ins->errno === 1146 /* table doesn't exist */) {
                ensure_api_tokens_table($conn);
                $ins = $conn->prepare($insertSql);
                if ($ins) {
                    $ins->bind_param('issss', $u['id'], $u['role'], $token, $expires, $ip);
                    if (!$ins->execute()) {
                        echo json_encode(['success' => false, 'error' => 'Token issue failed']);
                        exit;
                    }
                } else {
                    echo json_encode(['success' => false, 'error' => 'Token prepare failed']);
                    exit;
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Token issue failed']);
                exit;
            }
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
    // Log details on server for diagnostics, but return a generic error to client
    $logDir = __DIR__ . '/../../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
    $line = '[' . date('Y-m-d H:i:s') . "] auth/login.php error: " . $e->getMessage() . "\n";
    @file_put_contents($logDir . '/api_errors.log', $line, FILE_APPEND);
    echo json_encode(['success' => false, 'error' => 'A server error occurred. Please check server logs.']);
}
?>
