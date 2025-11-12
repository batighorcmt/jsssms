<?php
// index.php

// Ensure nothing is sent before headers
// Avoid closing PHP tag at file end to prevent accidental whitespace output
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: auth/login.php');
exit;
