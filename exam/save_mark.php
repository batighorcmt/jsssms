<?php
session_start();
include '../config/db.php';
$conn->query("CREATE TABLE IF NOT EXISTS marks (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id VARCHAR(64) NOT NULL,
    subject_id INT NOT NULL,
    creative_marks DECIMAL(6,2) NULL,
    objective_marks DECIMAL(6,2) NULL,
    practical_marks DECIMAL(6,2) NULL,
    UNIQUE KEY uniq_mark (exam_id, student_id, subject_id)
) ENGINE=InnoDB");

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
// Accept both numeric internal id and string student_id; store as string to avoid collisions
$student_id = trim((string)($_POST['student_id'] ?? ''));
$subject_id = (int)($_POST['subject_id'] ?? 0);
// Validate field to prevent SQL injection via column name
$field = (string)($_POST['field'] ?? '');
$allowedFields = ['creative_marks','objective_marks','practical_marks'];
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Invalid field']);
    exit;
}
$value = (float)($_POST['value'] ?? 0);

if ($role === 'teacher') {
    $assigned_teacher_id = null; $deadline = null;
    if ($st = $conn->prepare("SELECT teacher_id, mark_entry_deadline FROM exam_subjects WHERE exam_id=? AND subject_id=? LIMIT 1")) {
        $st->bind_param('ii', $exam_id, $subject_id);
        $st->execute(); $st->bind_result($assigned_teacher_id, $deadline); $st->fetch(); $st->close();
    }
    if (empty($teacher_id) || empty($assigned_teacher_id) || intval($teacher_id) !== intval($assigned_teacher_id)) {
        http_response_code(403);
        echo json_encode(['status'=>'error','message'=>'You are not authorized to enter marks for this subject']);
        exit;
    }
    if ($deadline) {
        $today = date('Y-m-d');
        if ($today > $deadline) {
            http_response_code(403);
            echo json_encode(['status'=>'error','message'=>'Your mark entry deadline has passed. Contact Admin.']);
            exit;
        }
    }
}

// Upsert using UNIQUE KEY to avoid duplicate rows across updates
$creative = $field === 'creative_marks' ? $value : null;
$objective = $field === 'objective_marks' ? $value : null;
$practical = $field === 'practical_marks' ? $value : null;
$sqlUpsert = "INSERT INTO marks (exam_id, student_id, subject_id, creative_marks, objective_marks, practical_marks)
              VALUES (?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                creative_marks = COALESCE(VALUES(creative_marks), creative_marks),
                objective_marks = COALESCE(VALUES(objective_marks), objective_marks),
                practical_marks = COALESCE(VALUES(practical_marks), practical_marks)";
$stmt = $conn->prepare($sqlUpsert);
$stmt->bind_param("isiddd", $exam_id, $student_id, $subject_id, $creative, $objective, $practical);
$stmt->execute();
echo json_encode(['status'=>'ok']);
