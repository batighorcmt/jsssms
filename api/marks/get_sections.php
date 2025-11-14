<?php
require_once '../../config/db.php';
require_once '../bootstrap.php';

api_require_auth();

$sql = "SELECT section_id, section_name FROM sections ORDER BY section_name";
$stmt = $pdo->query($sql);
$sections = $stmt->fetchAll(PDO::FETCH_ASSOC);

api_response(true, [
    'sections' => $sections
]);
