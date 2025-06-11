<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// ডাটাবেজ কানেকশন
require_once '../config/db.php';

if (isset($_GET['class_id'])) {
    $class_id = intval($_GET['class_id']);

    $stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE class_id = ? AND status = 'Active'");
    if (!$stmt) {
        echo json_encode(["error" => "Prepare failed: " . $conn->error]);
        exit;
    }

    $stmt->bind_param("i", $class_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $sections = [];

    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    echo json_encode($sections);
} else {
    echo json_encode(["error" => "No class_id provided"]);
}
?>
