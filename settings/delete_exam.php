<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

$id = $_GET['id'] ?? null;

if ($id) {
    $delete = $conn->prepare("DELETE FROM exam_subjects WHERE id = ?");
    $delete->bind_param("i", $id);
    if ($delete->execute()) {
        $_SESSION['success'] = "পরীক্ষা সফলভাবে মুছে ফেলা হয়েছে!";
    } else {
        $_SESSION['error'] = "মুছতে ব্যর্থ হয়েছে!";
    }
}

header("Location: manage_exams.php");
exit();
