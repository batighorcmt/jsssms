<?php
// FCM Debug & Diagnostics endpoint
// Provides comprehensive FCM health check, token validation, and test sending
// Supports both Bearer token and ?api_token= query parameter authentication
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../bootstrap.php';
@include_once __DIR__ . '/../lib/fcm.php';

// Authentication: support both Bearer and api_token parameter
$token = get_auth_header_token();
if (!$token) {
    api_response(false, 'Missing authentication. Provide Bearer token or ?api_token=...', 401);
}

// Verify token (allow both super_admin and teacher for their own diagnostics)
api_require_auth();
global $conn, $authUser;

$isSuperAdmin = ($authUser['role'] ?? '') === 'super_admin';

// Parameters
$withToken = isset($_GET['with_token']) && $_GET['with_token'] === '1';
$validateSend = isset($_GET['validate_send']) && $_GET['validate_send'] === '1';
$deviceToken = isset($_GET['device']) ? trim((string)$_GET['device']) : '';
$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Only super_admin can debug other users
if (!$isSuperAdmin && $targetUserId > 0 && $targetUserId !== ($authUser['id'] ?? 0)) {
    api_response(false, 'Forbidden: can only debug your own tokens', 403);
}

// Default to current user if not specified
if ($targetUserId <= 0) {
    $targetUserId = (int)($authUser['id'] ?? 0);
}

$diagnostics = [
    'timestamp' => date('c'),
    'auth_user' => [
        'id' => $authUser['id'] ?? null,
        'role' => $authUser['role'] ?? null,
        'name' => $authUser['name'] ?? null,
    ],
];

// 1. Service Account Health Check
$sa = fcm_load_sa();
$saPath = __DIR__ . '/../../config/firebase_service_account.json';
$diagnostics['service_account'] = [
    'file_exists' => is_file($saPath),
    'file_readable' => is_readable($saPath),
    'parsed' => is_array($sa),
    'project_id' => $sa['project_id'] ?? null,
    'client_email' => $sa['client_email'] ?? null,
    'has_private_key' => isset($sa['private_key']) && !empty($sa['private_key']),
];

// 2. Access Token Generation Test
$accessToken = null;
$tokenError = null;
try {
    $accessToken = fcm_get_access_token();
    $diagnostics['access_token'] = [
        'generated' => !empty($accessToken),
        'length' => $accessToken ? strlen($accessToken) : 0,
    ];
    if ($withToken && $accessToken) {
        $diagnostics['access_token']['token'] = $accessToken;
    }
    if (!$accessToken) {
        $tokenError = 'Failed to generate access token. Check logs for details.';
        $diagnostics['access_token']['error'] = $tokenError;
    }
} catch (Throwable $e) {
    $tokenError = $e->getMessage();
    $diagnostics['access_token'] = [
        'generated' => false,
        'error' => $tokenError,
    ];
}

// 3. User Device Tokens
if ($targetUserId > 0) {
    $userTokens = fcm_get_user_tokens($conn, $targetUserId);
    $diagnostics['user_tokens'] = [
        'user_id' => $targetUserId,
        'count' => count($userTokens),
        'tokens' => array_map(function($t) {
            return substr($t, 0, 20) . '...' . substr($t, -10);
        }, $userTokens),
    ];
}

// 4. Test Send (validate only mode if requested)
if ($validateSend) {
    $sendResult = null;
    $sendError = null;
    
    try {
        if ($deviceToken !== '') {
            // Direct token send
            $sendResult = fcm_send_to_tokens(
                [$deviceToken],
                'FCM Debug Test',
                'Test notification from fcm_debug.php endpoint',
                ['type' => 'debug', 'timestamp' => time()],
                true // validate_only
            );
        } elseif ($targetUserId > 0) {
            // User token send
            $sendResult = fcm_send_to_user(
                $conn,
                $targetUserId,
                'FCM Debug Test',
                'Test notification from fcm_debug.php endpoint',
                ['type' => 'debug', 'timestamp' => time()],
                true // validate_only
            );
        } else {
            $sendError = 'No device token or user_id provided for test send';
        }
        
        if ($sendResult) {
            $successCount = 0;
            if (isset($sendResult['results'])) {
                foreach ($sendResult['results'] as $r) {
                    if (!empty($r['ok'])) $successCount++;
                }
            }
            $diagnostics['test_send'] = [
                'attempted' => true,
                'validate_only' => true,
                'result' => $sendResult,
                'success_count' => $successCount,
            ];
        } elseif ($sendError) {
            $diagnostics['test_send'] = [
                'attempted' => false,
                'error' => $sendError,
            ];
        }
    } catch (Throwable $e) {
        $diagnostics['test_send'] = [
            'attempted' => true,
            'error' => $e->getMessage(),
        ];
    }
}

// 5. Overall Status
$allOk = (
    ($diagnostics['service_account']['parsed'] ?? false) &&
    ($diagnostics['service_account']['has_private_key'] ?? false) &&
    ($diagnostics['access_token']['generated'] ?? false)
);

$diagnostics['overall_status'] = $allOk ? 'healthy' : 'issues_detected';
$diagnostics['recommendations'] = [];

if (!($diagnostics['service_account']['file_exists'] ?? false)) {
    $diagnostics['recommendations'][] = 'Create config/firebase_service_account.json with valid Firebase service account credentials';
}
if (!($diagnostics['service_account']['has_private_key'] ?? false)) {
    $diagnostics['recommendations'][] = 'Ensure service account JSON includes private_key field';
}
if (!($diagnostics['access_token']['generated'] ?? false)) {
    $diagnostics['recommendations'][] = 'Check logs/notifications_log.txt for access token generation errors';
    $diagnostics['recommendations'][] = 'Verify service account key has not been revoked in Firebase Console';
}
if (isset($diagnostics['user_tokens']) && $diagnostics['user_tokens']['count'] === 0) {
    $diagnostics['recommendations'][] = 'User has no registered FCM device tokens. Register via mobile app.';
}

api_response(true, $diagnostics);
?>
