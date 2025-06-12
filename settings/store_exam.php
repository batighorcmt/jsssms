<?php
include '../config/db.php'; // আপনার ডেটাবেজ কানেকশন ফাইল

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $exam_name = $_POST['exam_name'];
    $class_id = $_POST['class_id'];
    $exam_type = $_POST['exam_type'];

    // Exam Insert
    $sql = "INSERT INTO exams (exam_name, class_id, exam_type) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sis", $exam_name, $class_id, $exam_type);
    $stmt->execute();
    $exam_id = $stmt->insert_id;

    // Looping through subjects
    $subject_id = $_POST['subject_id'];
    $exam_dates = $_POST['exam_date'];
    $exam_times = $_POST['exam_time'];
    $creative_marks = $_POST['creative_marks'];
    $objective_marks = $_POST['objective_marks'];
    $practical_marks = $_POST['practical_marks'];
    $creative_pass = $_POST['creative_pass'];
    $objective_pass = $_POST['objective_pass'];
    $practical_pass = $_POST['practical_pass'];

    for ($i = 0; $i < count($subject_id); $i++) {
        $total_marks = (int)$creative_marks[$i] + (int)$objective_marks[$i] + (int)$practical_marks[$i];

        $sql2 = "INSERT INTO exam_subjects (
                    exam_id, subject_id, exam_date, exam_time,
                    creative_marks, objective_marks, practical_marks, total_marks,
                    creative_pass, objective_pass, practical_pass
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param(
            "iissiiiiiii",
            $exam_id,
            $subject_ids[$i],
            $exam_dates[$i],
            $exam_times[$i],
            $creative_marks[$i],
            $objective_marks[$i],
            $practical_marks[$i],
            $total_marks,
            $creative_pass[$i],
            $objective_pass[$i],
            $practical_pass[$i]
        );
        $stmt2->execute();
    }

    echo "<script>alert('Exam created successfully!'); window.location.href='create_exam.php';</script>";
} else {
    echo "Invalid request!";
}
?>
