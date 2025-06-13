<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include "../config/db.php"; // ডাটাবেজ কানেকশন ফাইল

function test_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = test_input($_POST['username'] ?? '');
    $password = test_input($_POST['password'] ?? '');
    $role = test_input($_POST['role'] ?? '');

    if (!$username || !$password || !$role) {
        header("Location: ../auth/login.php?error=সব ফিল্ড পূরণ করুন");
        exit();
    }

    // প্রস্তুতকৃত স্টেটমেন্ট দিয়ে ইউজার ও রোল চেক করুন
  $sql = "SELECT id, username, password, name, role FROM users WHERE username = ? AND role = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $username, $role);
$stmt->execute();

$stmt->store_result();

if ($stmt->num_rows === 1) {
    $stmt->bind_result($id, $db_username, $db_password, $name, $db_role);
    $stmt->fetch();

    if (password_verify($password, $db_password)) {
        $_SESSION['id'] = $id;
        $_SESSION['username'] = $db_username;
        $_SESSION['name'] = $name;
        $_SESSION['role'] = $db_role;

        header("Location: ../dashboard.php");
        exit();
    } else {
        header("Location: ../auth/login.php?error=পাসওয়ার্ড সঠিক নয়");
        exit();
    }
} else {
    header("Location: ../auth/login.php?error=ইউজারনেম অথবা রোল ভুল");
    exit();
}

} else {
    header("Location: ../dashboard.php");
    exit();
}
