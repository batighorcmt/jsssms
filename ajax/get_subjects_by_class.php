<?php
include '../config/db.php';
header('Content-Type: application/json');

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$subjects = [];

if ($class_id > 0) {
    // Pull distinct subjects mapped to this class (ignore group)
    $sql = "SELECT DISTINCT s.id, s.subject_name
            FROM subjects s
            JOIN subject_group_map gm ON gm.subject_id = s.id
            WHERE gm.class_id = ? AND s.status = 'Active'
            ORDER BY s.subject_name ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $subjects[] = [
                'id' => (int)$row['id'],
                'name' => $row['subject_name'],
            ];
        }
        $stmt->close();
    }
}

echo json_encode($subjects);
