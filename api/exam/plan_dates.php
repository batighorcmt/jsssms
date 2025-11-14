<?php
header('Content-Type: application/json');
require_once '../../config/db.php';

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid Plan ID']);
    exit;
}

$examDates = [];
$sqlDates = "SELECT DISTINCT es.exam_date AS d 
             FROM seat_plan_exams spe 
             JOIN exam_subjects es ON es.exam_id=spe.exam_id 
             WHERE spe.plan_id=? AND es.exam_date IS NOT NULL 
             ORDER BY es.exam_date ASC";

$stmt = $conn->prepare($sqlDates);
if ($stmt) {
    $stmt->bind_param('i', $plan_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while($row = $result->fetch_assoc()){
        if (!empty($row['d'])) {
            $examDates[] = $row['d'];
        }
    }
    $stmt->close();
}

echo json_encode(['success' => true, 'data' => ['dates' => $examDates]]);
$conn->close();
