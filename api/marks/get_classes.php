<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

$classes = [];
$sql = "SELECT id AS class_id, class_name FROM classes ORDER BY class_name";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $classes[] = ['class_id' => (int)$row['class_id'], 'class_name' => $row['class_name']];
    }
}

api_response(true, [
    'classes' => $classes
]);
