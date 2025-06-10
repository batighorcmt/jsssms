<?php
// index.php
session_start();
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
} else {
    header("Location: auth/login.php");
}
exit();
?>
