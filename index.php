

<?php
// index.php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
} else {
    header("Location: auth/login.php");
}
exit();
?>
