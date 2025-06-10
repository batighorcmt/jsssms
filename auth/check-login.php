<?php
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
        header("Location: login.php?error=সব ফিল্ড পূরণ করুন");
        exit();
    }

    // প্রস্তুতকৃত স্টেটমেন্ট দিয়ে ইউজার ও রোল চেক করুন
    $sql = "SELECT * FROM users WHERE username = ? AND role = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // পাসওয়ার্ড যাচাই (হ্যাশড পাসওয়ার্ড হলে)
        if (password_verify($password, $user['password'])) {
            // লগইন সফল, সেশন স্টোর করুন
            $_SESSION['id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            header("Location: ../dashboard.php");
            exit();
        } else {
            header("Location: login.php?error=পাসওয়ার্ড সঠিক নয়");
            exit();
        }
    } else {
        header("Location: login.php?error=ইউজারনেম অথবা রোল ভুল");
        exit();
    }
} else {
    header("Location: ../dashboard.php");
    exit();
}
