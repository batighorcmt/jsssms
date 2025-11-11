<?php
// auth/session.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// চেক করো ইউজার লগইন করা কি না
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: /jsssms/auth/login.php");
    exit();
}

// রোল ভিত্তিক এক্সেস কন্ট্রোল (ডিফল্ট: শুধুমাত্র super_admin)
// কোনো পেজ থেকে $ALLOWED_ROLES অ্যারে সেট করা থাকলে সেটি ব্যবহার হবে
if (!isset($ALLOWED_ROLES) || !is_array($ALLOWED_ROLES) || empty($ALLOWED_ROLES)) {
    $ALLOWED_ROLES = ['super_admin'];
}

if (!in_array($_SESSION['role'], $ALLOWED_ROLES, true)) {
    // অনুমতি না থাকলে নিষিদ্ধ পেজে রিডাইরেক্ট
    header("Location: /jsssms/auth/forbidden.php");
    exit();
}
?>
