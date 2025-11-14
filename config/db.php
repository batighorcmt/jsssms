<?php
$servername = "localhost";
$username = "jorepuku_ksms";
$password = "Halim%%2025_123";
$dbname = "jorepuku_jss";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// charset set করুন
$conn->set_charset("utf8mb4");
?>
