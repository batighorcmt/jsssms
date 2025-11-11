<?php
session_start();
include '../config/db.php';

// Permission check for teachers: only assigned teacher can submit marks for this exam+subject
$role = $_SESSION['role'] ?? '';
if ($role === 'teacher') {
    $username = $_SESSION['username'] ?? '';
    $teacher_id = 0;
    if ($username !== '') {
        if ($t = $conn->prepare("SELECT id FROM teachers WHERE contact = ? LIMIT 1")) {
            $t->bind_param('s', $username);
            $t->execute(); $t->bind_result($teacher_id); $t->fetch(); $t->close();
        }
    }
}

$exam_id = (int)($_POST['exam_id'] ?? 0);
$student_id = (int)($_POST['student_id'] ?? 0);
$subject_id = (int)($_POST['subject_id'] ?? 0);
$field = $_POST['field'];
$value = $_POST['value'];

if ($role === 'teacher') {
    $assigned_teacher_id = null;
    if ($st = $conn->prepare("SELECT teacher_id FROM exam_subjects WHERE exam_id=? AND subject_id=? LIMIT 1")) {
        $st->bind_param('ii', $exam_id, $subject_id);
        $st->execute(); $st->bind_result($assigned_teacher_id); $st->fetch(); $st->close();
    }
    if (empty($teacher_id) || empty($assigned_teacher_id) || intval($teacher_id) !== intval($assigned_teacher_id)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'এই বিষয়ের নম্বর প্রদান করার অনুমতি নেই']);
        exit;
    }
}

// Check if record exists
$sqlCheck = "SELECT student_id FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id = ?";
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
echo json_encode(['status'=>'ok']);
