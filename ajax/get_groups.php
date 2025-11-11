<?php
include '../config/db.php';
header('Content-Type: application/json');

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$groups = [];

if ($class_id > 0) {
    $stmt = $conn->prepare("SELECT DISTINCT TRIM(gm.group_name) AS group_name FROM subject_group_map gm WHERE gm.class_id = ? AND gm.group_name IS NOT NULL AND gm.group_name <> '' ORDER BY gm.group_name ASC");
    if ($stmt) {
        $stmt->bind_param('i', $class_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $groups[] = $row['group_name'];
        }
        $stmt->close();
    }
}

echo json_encode($groups);
