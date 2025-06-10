<?php
include "config/db.php";

$username = "ah";
$password_plain = "123456";
$role = "teacher";

$hashed_password = password_hash($password_plain, PASSWORD_DEFAULT);

$sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $username, $hashed_password, $role);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    echo "User created successfully.";
} else {
    echo "Failed to create user.";
}
?>
