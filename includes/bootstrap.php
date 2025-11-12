<?php
// Global bootstrap for error handling and logging
// - Sets robust error logging to logs/php_errors.log (fallback to system temp)
// - Disables display_errors in production to avoid HTTP 500 blank pages
// - Catches fatal errors on shutdown and uncaught exceptions

// Timezone (avoid warnings on date functions)
date_default_timezone_set(@date_default_timezone_get() ?: 'Asia/Dhaka');

// Determine log file path
$__logDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($__logDir)) { @mkdir($__logDir, 0775, true); }
$__logFile = $__logDir . DIRECTORY_SEPARATOR . 'php_errors.log';
if (!is_writable($__logDir)) {
    $__logFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jsssms_php_errors.log';
}
if (!file_exists($__logFile)) { @touch($__logFile); }

// Error reporting
error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', $__logFile);

// Helper logger
if (!function_exists('app_log')) {
    function app_log($msg) {
        $ts = date('Y-m-d H:i:s');
        @error_log("[$ts] $msg");
    }
}

// Shutdown handler to catch fatals
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        app_log('FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

// Uncaught exception handler
set_exception_handler(function($ex){
    app_log('EXCEPTION: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    // Avoid revealing details on production; rely on logs
    http_response_code(500);
    echo '';
});

// Suppress libxml global errors (callers can inspect if needed)
if (function_exists('libxml_use_internal_errors')) {
    libxml_use_internal_errors(true);
}

// Clean globals
unset($__logDir, $__logFile);
?>
