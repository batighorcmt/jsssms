<?php
// Global application configuration
// Define a single Base URL for the whole project so links work both locally and in production.
// Example manual override:
// define('BASE_URL', 'https://your-domain.com/');

// Environment-aware mapping so you don't need to edit during deploy
if (!defined('BASE_URL')) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host) {
        if (stripos($host, 'bktc.edu.bd') !== false) {
            // Live
            define('BASE_URL', 'https://bktc.edu.bd/jss/');
        } elseif ($host === 'localhost' || $host === '127.0.0.1') {
            // Local
            define('BASE_URL', 'http://localhost/jsssms/');
        }
    }
}

if (!defined('BASE_URL')) {
    // Try to auto-detect base path relative to the web server document root
    $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim(str_replace('\\','/', $_SERVER['DOCUMENT_ROOT']), '/') : '';
    $appRoot = rtrim(str_replace('\\','/', dirname(__DIR__)), '/'); // path to /jsssms
    $basePath = '/';
    if ($docRoot && strpos($appRoot, $docRoot) === 0) {
        $basePath = substr($appRoot, strlen($docRoot));
        if ($basePath === '' || $basePath === false) { $basePath = '/'; }
    }
    // Ensure trailing slash
    $detected = rtrim($basePath, '/').'/';

    // Environment override if provided
    $env = getenv('APP_BASE_URL');
    if ($env && preg_match('~^https?://|/~i', $env)) {
        $detected = rtrim($env, '/').'/';
    }

    define('BASE_URL', $detected);
}

// Optional: Provide $BASE_URL variable for templates that expect a variable
if (!isset($BASE_URL)) {
    $BASE_URL = BASE_URL;
}
