<?php
require_once '../../config/db.php';
require_once '../bootstrap.php';

api_require_auth();

$sql = "SELECT id, name FROM exams ORDER BY name";
$stmt = $pdo->query($sql);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);

api_response(true, [
    'exams' => $exams
]);
