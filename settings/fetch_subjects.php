<?php
include '../config/db.php';

// fetch_subjects.php
$class_id = $_GET['class_id'];
$sql = "SELECT * FROM subjects WHERE class_id = $class_id AND status = 'Active'";
$result = $conn->query($sql);

$subjects = [];
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row; // এতে has_creative, has_objective, has_practical সহ যাবে
}
echo json_encode($subjects);

