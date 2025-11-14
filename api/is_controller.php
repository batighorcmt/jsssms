<?php
header('Content-Type: application/json');
include '../config/db.php';

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($userId <= 0) {
    echo json_encode(['is_controller' => false]);
    exit;
}

$isController = false;
$query = $conn->prepare("SELECT 1 FROM exam_controllers WHERE active=1 AND user_id=? LIMIT 1");
$query->bind_param('i', $userId);
$query->execute();
$result = $query->get_result();

if ($result && $result->num_rows > 0) {
    $isController = true;
}

echo json_encode(['is_controller' => $isController]);

$conn->close();
