<?php
session_start();
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $exam_name = $_POST['exam_name'];
    $class_id = $_POST['class_id'];
    $exam_type = $_POST['exam_type'];
    $created_by = $_SESSION['user_id'] ?? 0;
    $subjects_without_fourth = isset($_POST['subjects_without_fourth']) ? (int)$_POST['subjects_without_fourth'] : 0;

    // Exam Insert
    $sql = "INSERT INTO exams (exam_name, class_id, exam_type, created_by) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sisi", $exam_name, $class_id, $exam_type, $created_by);
    
    if ($stmt->execute()) {
        $exam_id = $stmt->insert_id;

        // Try to persist the subject count (excluding 4th) onto exams table
        if ($subjects_without_fourth > 0) {
            // Ensure column exists (add if missing)
            $colExists = false;
            if ($res = $conn->query("SHOW COLUMNS FROM exams LIKE 'total_subjects_without_fourth'")) {
                $colExists = ($res->num_rows > 0);
            }
            if (!$colExists) {
                // Attempt to add the column; ignore errors
                @$conn->query("ALTER TABLE exams ADD COLUMN total_subjects_without_fourth INT NULL DEFAULT NULL");
            }
            // Update the just-created exam row (if column present now)
            @$conn->query("UPDATE exams SET total_subjects_without_fourth = " . (int)$subjects_without_fourth . " WHERE id = " . (int)$exam_id);
        }

        // Get the arrays from POST
        $subject_ids = $_POST['subject_id'];
        $exam_dates = $_POST['exam_date'];
        $exam_times = $_POST['exam_time'];
        $creative_marks = $_POST['creative_marks'];
        $objective_marks = $_POST['objective_marks'];
        $practical_marks = $_POST['practical_marks'];
        $creative_pass = $_POST['creative_pass'];
        $objective_pass = $_POST['objective_pass'];
        $practical_pass = $_POST['practical_pass'];

        // Prepare statement for exam_subjects
        $sql2 = "INSERT INTO exam_subjects (
                    exam_id, subject_id, exam_date, exam_time,
                    creative_marks, objective_marks, practical_marks,
                    creative_pass, objective_pass, practical_pass
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt2 = $conn->prepare($sql2);
        
        for ($i = 0; $i < count($subject_ids); $i++) {
            $stmt2->bind_param(
                "iissiiiiii",
                $exam_id,
                $subject_ids[$i],
                $exam_dates[$i],
                $exam_times[$i],
                $creative_marks[$i],
                $objective_marks[$i],
                $practical_marks[$i],
                $creative_pass[$i],
                $objective_pass[$i],
                $practical_pass[$i]
            );
            $stmt2->execute();
        }

        $_SESSION['success'] = "পরীক্ষা সফলভাবে তৈরি করা হয়েছে";
        header("Location: create_exam.php");
        exit();
    } else {
        $_SESSION['error'] = "পরীক্ষা তৈরি করতে সমস্যা হয়েছে: " . (function_exists('mysqli_error') ? mysqli_error($conn) : '');
        header("Location: create_exam.php");
        exit();
    }
} else {
    $_SESSION['error'] = "অবৈধ অনুরোধ";
    header("Location: create_exam.php");
    exit();
}
?>