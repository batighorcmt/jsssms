<?php
// Global application configuration
// Define a single Base URL for the whole project so links work both locally and in production.
// Example manual override:
// define('BASE_URL', 'https://your-domain.com/');

// Environment-aware mapping so you don't need to edit during deploy

// Provide institute details
$institute_name='Jorepukuria Secondary School';
$institute_address='Gangni, Meherpur';
$institute_phone='01713913076';
$institute_logo='/assets/logo.png';

//Provide Developer Info
$developer_name='Md. Abdul Halim';
$developer_phone='01762396713';
$company_name='Batighor Computers';
$company_website='https://batighorbd.com';


// Auto-detect BASE_URL if not defined
if (!defined('BASE_URL')) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host) {
        if (stripos($host, 'jss.batighorbd.com') !== false) {
            // Live
            define('BASE_URL', 'https://jss.batighorbd.com/');
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
