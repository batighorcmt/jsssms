<?php
// Push notifications configuration (FCM)
// Prefer setting environment variables on the server:
//   FCM_SERVER_KEY = your Firebase Cloud Messaging Server key
//   FCM_SENDER_ID  = optional project sender ID
// You can also create an untracked local override file:
//   config/notifications.local.php  defining FCM_SERVER_KEY (recommended for cPanel)

// Optional local overrides (define constants here to override below defaults)
@include_once __DIR__ . '/notifications.local.php';

if (!defined('FCM_SERVER_KEY')) {
    define('FCM_SERVER_KEY', getenv('FCM_SERVER_KEY') ?: '');
}
if (!defined('FCM_SENDER_ID')) {
    define('FCM_SENDER_ID', getenv('FCM_SENDER_ID') ?: '');
}
if (!defined('FCM_ENABLED')) {
    define('FCM_ENABLED', FCM_SERVER_KEY !== '');
}

?>
