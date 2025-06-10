<?php
include 'config/db.php';

$class_id = $_GET['class_id'] ?? 0;
$sections = [];

if ($class_id > 0) {
    $stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE class_id = ?");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }
}

header('Content-Type: application/json');
echo json_encode($sections);
?>
