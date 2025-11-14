<?php
$servername = "localhost";
$username = "jorepuku_ksms";
$password = "Halim%%2025_123";
$dbname = "jorepuku_jss";

$conn = new mysqli($servername, $username, $password, $dbname);

// Do not use die() in a file included by an API.
// The API script will handle the connection check.
if (!$conn->connect_error) {
    // charset set করুন
    $conn->set_charset("utf8mb4");
}
?>
