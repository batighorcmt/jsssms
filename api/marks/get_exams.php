<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

$exams = [];
$sql = "SELECT id, exam_name AS name FROM exams ORDER BY exam_name";
if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $exams[] = ['id' => (int)$row['id'], 'name' => $row['name']];
    }
}

api_response(true, [
    'exams' => $exams
]);
