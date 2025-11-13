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

// Collect runtime errors for this request
$__caught_errors = [];
$__banner_trigger = false; // only show banner for fatal/500 cases

// Custom error handler to record warnings/notices too
set_error_handler(function($errno, $errstr, $errfile, $errline){
    $types = [
        E_ERROR=>'E_ERROR', E_WARNING=>'E_WARNING', E_PARSE=>'E_PARSE', E_NOTICE=>'E_NOTICE',
        E_CORE_ERROR=>'E_CORE_ERROR', E_CORE_WARNING=>'E_CORE_WARNING', E_COMPILE_ERROR=>'E_COMPILE_ERROR', E_COMPILE_WARNING=>'E_COMPILE_WARNING',
        E_USER_ERROR=>'E_USER_ERROR', E_USER_WARNING=>'E_USER_WARNING', E_USER_NOTICE=>'E_USER_NOTICE',
        E_STRICT=>'E_STRICT', E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR', E_DEPRECATED=>'E_DEPRECATED', E_USER_DEPRECATED=>'E_USER_DEPRECATED'
    ];
    $label = isset($types[$errno]) ? $types[$errno] : (string)$errno;
    $msg = $label . ': ' . $errstr . ' in ' . $errfile . ':' . $errline;
    app_log($msg);
    // Log all, but do not trigger UI banner for non-fatal notices/warnings/deprecations
    global $__caught_errors; $__caught_errors[] = ['type'=>$label,'message'=>$errstr,'file'=>$errfile,'line'=>$errline];
    return true; // handled
});

// Shutdown handler to catch fatals and optionally surface a banner (disabled by default)
register_shutdown_function(function() use (&$__caught_errors, &$__banner_trigger){
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        app_log('FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $__caught_errors[] = ['type'=>'FATAL','message'=>$err['message'],'file'=>$err['file'],'line'=>$err['line']];
        $__banner_trigger = true;
    }
    // Banner output disabled for production
});

// Uncaught exception handler (no UI banner in production)
set_exception_handler(function($ex) use (&$__banner_trigger){
    app_log('EXCEPTION: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine());
    // Avoid revealing details on production; rely on logs
    if (function_exists('http_response_code') && !headers_sent()) {
        http_response_code(500);
    }
    // Mark internal flag (in case code needs it); no UI output
    $__banner_trigger = true;
});

// Suppress libxml global errors (callers can inspect if needed)
if (function_exists('libxml_use_internal_errors')) {
    libxml_use_internal_errors(true);
}

// Clean globals
unset($__logDir, $__logFile);
?>
