<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
include "../config/db.php"; // ডাটাবেজ কানেকশন ফাইল
@include_once __DIR__ . '/../config/config.php'; // BASE_URL

function test_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = test_input($_POST['username'] ?? '');
    $password = test_input($_POST['password'] ?? '');

        if (!$username || !$password) {
                header("Location: ../auth/login.php?error=সব ফিল্ড পূরণ করুন");
        exit();
    }

        // ইউজারনেম দিয়ে ইউজার খুঁজে বের করুন; রোল ডিবি থেকে নিন
    $sql = "SELECT id, username, password, name, role FROM users WHERE username = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $username);
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

        // Role-based redirection
        if ($db_role === 'teacher') header("Location: " . BASE_URL . "dashboard_teacher.php");
        else header("Location: " . BASE_URL . "dashboard.php");
        exit();
    } else {
        header("Location: ../auth/login.php?error=পাসওয়ার্ড সঠিক নয়");
        exit();
    }
} else {
    header("Location: ../auth/login.php?error=ইউজারনেম ভুল");
    exit();
}

} else {
    // Default landing: decide based on existing session role if any
    if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher') header("Location: " . BASE_URL . "dashboard_teacher.php");
    else header("Location: " . BASE_URL . "dashboard.php");
    exit();
}
