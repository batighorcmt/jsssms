<?php
$servername = "localhost";
$username = "bktcedu_user";
$password = "@Bktc112233";
$dbname = "bktcedu_jss";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// charset set করুন
$conn->set_charset("utf8mb4");

// Content-Type header
header('Content-Type: text/html; charset=utf-8');
?>
