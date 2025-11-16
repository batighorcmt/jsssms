<?php
// Local overrides for notification settings (not tracked in git)
// Legacy FCM server key no longer used (leave blank to force v1 API)
define('FCM_SERVER_KEY', '');

// Path to Firebase service account JSON for FCM HTTP v1
if (!defined('FIREBASE_SERVICE_ACCOUNT_FILE')) {
	define('FIREBASE_SERVICE_ACCOUNT_FILE', __DIR__ . '/firebase_service_account.json');
}
