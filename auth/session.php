<?php
// auth/session.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// চেক করো ইউজার লগইন করা কি না
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}
?>
