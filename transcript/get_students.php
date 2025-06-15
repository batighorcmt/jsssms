<?php
include '../config/db.php';

$class_id = $_GET['class_id'];
$year = $_GET['year'];

$stmt = $conn->prepare("SELECT student_id, student_name FROM students WHERE class_id = ? AND year = ?");
$stmt->bind_param("ii", $class_id, $year);
$stmt->execute();
$result = $stmt->get_result();

echo "<option value=''>Select Student</option>";
while ($row = $result->fetch_assoc()) {
    echo "<option value='{$row['student_id']}'>{$row['student_name']} ({$row['student_id']})</option>";
}
