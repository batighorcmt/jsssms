<?php
session_start();
include '../config/db.php';

$exam_id = $_POST['exam_id'];
$student_id = $_POST['student_id'];
$subject_id = $_POST['subject_id'];
$field = $_POST['field'];
$value = $_POST['value'];

// Check if record exists
$sqlCheck = "SELECT id FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id = ?";
$stmt = $conn->prepare($sqlCheck);
$stmt->bind_param("iii", $exam_id, $student_id, $subject_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Update
    $sqlUpdate = "UPDATE marks SET $field = ? WHERE exam_id = ? AND student_id = ? AND subject_id = ?";
    $stmt = $conn->prepare($sqlUpdate);
    $stmt->bind_param("diii", $value, $exam_id, $student_id, $subject_id);
    $stmt->execute();
} else {
    // Insert
    $creative = $field == 'creative_marks' ? $value : 0;
    $objective = $field == 'objective_marks' ? $value : 0;
    $sqlInsert = "INSERT INTO marks (exam_id, student_id, subject_id, creative_marks, objective_marks)
                  VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sqlInsert);
    $stmt->bind_param("iiidd", $exam_id, $student_id, $subject_id, $creative, $objective);
    $stmt->execute();
}
echo "saved";
