<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

$exams = [];
$sql = "SELECT e.id, e.exam_name, e.class_id, c.class_name
        FROM exams e
        JOIN classes c ON c.id = e.class_id
        ORDER BY e.exam_name";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $label = $row['exam_name'] . ' - ' . $row['class_name'];
        $exams[] = [
            'id' => (int)$row['id'],
            'name' => $row['exam_name'],
            'class_id' => (int)$row['class_id'],
            'class_name' => $row['class_name'],
            'label' => $label,
        ];
    }
}

api_response(true, [
    'exams' => $exams
]);
