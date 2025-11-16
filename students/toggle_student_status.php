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

// Fetch current status
$status = '';
$res = $conn->prepare('SELECT status FROM students WHERE id = ?');
$res->bind_param('i', $student_id);
$res->execute();
$res->bind_result($status);
$res->fetch();
$res->close();

if ($status === 'Active') {
    $newStatus = 'Inactive';
} else {
    $newStatus = 'Active';
}

$upd = $conn->prepare('UPDATE students SET status = ? WHERE id = ?');
$upd->bind_param('si', $newStatus, $student_id);
$upd->execute();
$upd->close();

header('Location: manage_students.php?status_toggled=1');
exit;
