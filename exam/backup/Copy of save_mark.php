<?php
// ajax/save_mark.php
session_start();
include '../config/db.php';

// ইনপুট যাচাই
if (!isset($_POST['student_id'], $_POST['subject_id'], $_POST['field'], $_POST['value'])) {
    http_response_code(400);
    echo "অনুপূর্ণ অনুরোধ।";
    exit;
}

$student_id = intval($_POST['student_id']);
$subject_id = intval($_POST['subject_id']);
$field = $_POST['field'];
$value = floatval($_POST['value']);

$allowedFields = ['creative_marks', 'objective_marks'];
if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    echo "অবৈধ ফিল্ড।";
    exit;
}

// exam_id ও subject_id বের করতে হবে
$sqlSub = "SELECT exam_id, subject_id FROM exam_subjects WHERE id = ?";
$stmt = $conn->prepare($sqlSub);
$stmt->bind_param('i', $subject_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    http_response_code(404);
    echo "বিষয় খুঁজে পাওয়া যায়নি।";
    exit;
}

$row = $result->fetch_assoc();
$exam_id = $row['exam_id'];
$subject_id = $row['subject_id'];

// এখন চেক করা হবে mark আগেই আছে কিনা
$sqlCheck = "SELECT id FROM marks WHERE student_id = ? AND subject_id = ?";
$stmt2 = $conn->prepare($sqlCheck);
$stmt2->bind_param("ii", $student_id, $subject_id);
$stmt2->execute();
$resultCheck = $stmt2->get_result();

if ($resultCheck->num_rows > 0) {
    // আপডেট
    $sqlUpdate = "UPDATE marks SET $field = ? WHERE student_id = ? AND subject_id = ?";
    $stmt3 = $conn->prepare($sqlUpdate);
    $stmt3->bind_param("dii", $value, $student_id, $subject_id);
    $stmt3->execute();
    echo "আপডেট সম্পন্ন হয়েছে।";
} else {
    // নতুন এন্ট্রি
    $creative = $field == 'creative_marks' ? $value : 0;
    $objective = $field == 'objective_marks' ? $value : 0;

    $sqlInsert = "INSERT INTO marks (student_id, exam_id, subject_id, creative_marks, objective_marks) VALUES (?, ?, ?, ?, ?)";
    $stmt4 = $conn->prepare($sqlInsert);
    $stmt4->bind_param("iiidd", $student_id, $exam_id, $subject_id, $creative, $objective);
    $stmt4->execute();
    echo "নতুন এন্ট্রি সংরক্ষিত হয়েছে।";
}

