<?php
// index.php

// Ensure nothing is sent before headers
// Avoid closing PHP tag at file end to prevent accidental whitespace output
@ini_set('display_errors', 1);
@ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
@include_once __DIR__ . '/config/config.php';

$role = $_SESSION['role'] ?? null;
if ($role === 'teacher') {
    header('Location: ' . BASE_URL . 'dashboard_teacher.php');
    exit;
}
if ($role) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

header('Location: ' . BASE_URL . 'auth/login.php');
exit;
