<?php
// Notifications system removed; provide no-op stubs to avoid runtime errors

if (!function_exists('get_user_device_tokens')) {
    function get_user_device_tokens($conn, array $userIds): array { return []; }
}

if (!function_exists('fcm_send_tokens')) {
    function fcm_send_tokens(array $tokens, string $title, string $body, array $data = []): array {
        return ['sent' => 0, 'failed' => count($tokens), 'responses' => [], 'mode' => 'removed'];
    }
}

if (!function_exists('fcm_self_test')) {
    function fcm_self_test(): array { return ['mode' => 'removed', 'sent' => 0, 'failed' => 0]; }
}

?>
