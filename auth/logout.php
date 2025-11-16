<?php
// auth/logout.php
session_start();
session_destroy();
@include_once __DIR__ . '/../config/config.php';
$target = (defined('BASE_URL') ? BASE_URL : '../') . 'auth/login.php';
header('Location: ' . $target);
exit();
?>
