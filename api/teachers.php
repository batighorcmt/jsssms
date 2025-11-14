<?php
header('Content-Type: application/json');
require_once '../config/db.php';

$teachers = [];
$sql = "SELECT u.id AS user_id, u.username, COALESCE(t.name, CONCAT('Teacher(', u.username, ')')) AS display_name
         FROM users u
         LEFT JOIN teachers t ON t.contact = u.username
         WHERE u.role='teacher'
         ORDER BY display_name ASC";

if ($result = $conn->query($sql)) {
    while($row = $result->fetch_assoc()) {
        $teachers[] = $row;
    }
    $result->free();
}

echo json_encode(['success' => true, 'data' => ['teachers' => $teachers]]);
$conn->close();
