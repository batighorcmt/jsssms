<?php
// Push notifications configuration (FCM)
// Prefer setting environment variables on the server:
//   FCM_SERVER_KEY = your Firebase Cloud Messaging Server key
//   FCM_SENDER_ID  = optional project sender ID
// If not set via environment, you can put the key directly here (not recommended).

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
