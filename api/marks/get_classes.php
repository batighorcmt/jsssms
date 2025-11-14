<?php
require_once '../../config/db.php';
require_once '../bootstrap.php';

api_require_auth();

$sql = "SELECT class_id, class_name FROM classes ORDER BY class_name";
$stmt = $pdo->query($sql);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

api_response(true, [
    'classes' => $classes
]);
