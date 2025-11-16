<?php
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
$ALLOWED_ROLES = ['super_admin'];
include '../config/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: manage_students.php?error=InvalidID');
    exit;
}
$student_id = (int)$_GET['id'];

// Fetch student info for photo deletion
$photo = '';
$res = $conn->prepare('SELECT photo FROM students WHERE id = ?');
$res->bind_param('i', $student_id);
$res->execute();
$res->bind_result($photo);
$res->fetch();
$res->close();

// Delete from marks table
$conn->query('DELETE FROM marks WHERE student_id = ' . $student_id);
// Delete from student_subjects table
$conn->query('DELETE FROM student_subjects WHERE student_id = ' . $student_id);
// Delete from students table
$conn->query('DELETE FROM students WHERE id = ' . $student_id);

// Delete photo file if exists
if ($photo) {
    $photoPath = __DIR__ . '/../uploads/students/' . $photo;
    if (file_exists($photoPath)) {
        @unlink($photoPath);
    }
}

header('Location: manage_students.php?deleted=1');
exit;
