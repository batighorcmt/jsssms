<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

// Optional filter by class_id if provided
$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$sections = [];
if ($classId > 0) {
    $stmt = $conn->prepare("SELECT id AS section_id, section_name FROM sections WHERE class_id=? ORDER BY section_name");
    if ($stmt) {
        $stmt->bind_param('i', $classId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sections[] = ['section_id' => (int)$row['section_id'], 'section_name' => $row['section_name']];
        }
        $stmt->close();
    }
} else {
    $sql = "SELECT id AS section_id, section_name FROM sections ORDER BY section_name";
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $sections[] = ['section_id' => (int)$row['section_id'], 'section_name' => $row['section_name']];
        }
    }
}

api_response(true, [
    'sections' => $sections
]);
