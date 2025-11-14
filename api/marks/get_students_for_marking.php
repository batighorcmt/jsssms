<?php
require_once '../../config/db.php';
require_once '../bootstrap.php';

api_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_response(false, "Invalid request method");
}

$params = [
    'exam_id' => $_GET['exam_id'] ?? null,
    'class_id' => $_GET['class_id'] ?? null,
    'section_id' => $_GET['section_id'] ?? null,
    'subject_id' => $_GET['subject_id'] ?? null,
];

foreach ($params as $key => $value) {
    if (empty($value)) {
        api_response(false, "Missing parameter: $key");
    }
}

try {
    // Fetch students of the given class and section
    $sql = "SELECT s.student_id, s.name, s.roll_no
            FROM students s
            WHERE s.class_id = :class_id AND s.section_id = :section_id
            ORDER BY s.roll_no ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'class_id' => $params['class_id'],
        'section_id' => $params['section_id']
    ]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each student, fetch their existing marks for the given subject and exam
    $sql_marks = "SELECT marks_obtained FROM marks 
                  WHERE student_id = :student_id 
                  AND exam_id = :exam_id 
                  AND subject_id = :subject_id";
    $stmt_marks = $pdo->prepare($sql_marks);

    foreach ($students as &$student) {
        $stmt_marks->execute([
            'student_id' => $student['student_id'],
            'exam_id' => $params['exam_id'],
            'subject_id' => $params['subject_id']
        ]);
        $mark = $stmt_marks->fetch(PDO::FETCH_ASSOC);
        $student['marks_obtained'] = $mark ? $mark['marks_obtained'] : '';
    }

    api_response(true, ['students' => $students]);

} catch (PDOException $e) {
    api_response(false, "Database error: " . $e->getMessage());
}
