<?php
// auth/session.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
@include_once __DIR__ . '/../config/config.php';

// চেক করো ইউজার লগইন করা কি না
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Avoid header already sent: only redirect if no output has started
    if (!headers_sent()) {
        header("Location: " . BASE_URL . "auth/login.php");
    } else {
        echo '<script>location.href=' . json_encode(BASE_URL . 'auth/login.php') . ';</script>';
    }
    exit();
}

// রোল ভিত্তিক এক্সেস কন্ট্রোল (ডিফল্ট: শুধুমাত্র super_admin)
// কোনো পেজ থেকে $ALLOWED_ROLES অ্যারে সেট করা থাকলে সেটি ব্যবহার হবে
if (!isset($ALLOWED_ROLES) || !is_array($ALLOWED_ROLES) || empty($ALLOWED_ROLES)) {
    $ALLOWED_ROLES = ['super_admin'];
}

if (!in_array($_SESSION['role'], $ALLOWED_ROLES, true)) {
    // অনুমতি না থাকলে নিষিদ্ধ পেজে রিডাইরেক্ট
    if (!headers_sent()) {
        header("Location: " . BASE_URL . "auth/forbidden.php");
    } else {
        echo '<script>location.href=' . json_encode(BASE_URL . 'auth/forbidden.php') . ';</script>';
    }
    exit();
}
?>
