<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php';
session_start();

if (isset($_POST['class_id']) && isset($_POST['group'])) {
    $class_id = $_POST['class_id'];
    $group = $_POST['group']; // যেমন: Science, Humanities, Business Studies
    $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;

        // Base query
    $sql = "SELECT s.id, s.subject_name
            FROM subjects s
            JOIN subject_group_map gm ON gm.subject_id = s.id
            WHERE gm.class_id = ? 
              AND gm.group_name = ?
              AND s.status = 'Active'";

    $params = [$class_id, $group];
    $types = 'is';

    // If a teacher is logged in and an exam is selected, filter to only subjects assigned to them for that exam
    if (!empty($_SESSION['role']) && $_SESSION['role'] === 'teacher' && $exam_id > 0) {
        $username = $_SESSION['username'] ?? '';
        $teacher_id = 0;
        if ($username !== '') {
            if ($t = $conn->prepare("SELECT id FROM teachers WHERE contact = ? LIMIT 1")) {
                $t->bind_param('s', $username);
                $t->execute(); $t->bind_result($teacher_id); $t->fetch(); $t->close();
            }
        }
        // Check if exam_subjects has teacher_id column; if not, skip extra filter to avoid SQL error
        $hasTeacherCol = false;
        if ($cchk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'teacher_id'")) {
            $hasTeacherCol = ($cchk->num_rows > 0);
        }
        if ($teacher_id && $hasTeacherCol) {
            $sql = "SELECT s.id, s.subject_name
                    FROM subjects s
                    JOIN subject_group_map gm ON gm.subject_id = s.id
                    JOIN exam_subjects es ON es.subject_id = s.id AND es.exam_id = ?
                    WHERE gm.class_id = ? AND gm.group_name = ? AND s.status='Active' AND es.teacher_id = ?";
            $params = [$exam_id, $class_id, $group, $teacher_id];
            $types = 'iisi';
        }
    }

    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();

    $stmt->bind_result($id, $subject_name);

    echo '<option value="">বিষয় নির্বাচন করুন</option>';
    while ($stmt->fetch()) {
        echo '<option value="' . $id . '">' . $subject_name . '</option>';
    }

    $stmt->close();
}
?>
