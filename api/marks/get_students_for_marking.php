<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_response(false, 'Invalid request method', 405);
}

$exam_id = (int)($_GET['exam_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$section_id = (int)($_GET['section_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
if (!$exam_id || !$class_id || !$section_id || !$subject_id) {
    api_response(false, 'Missing required parameters', 400);
}

// Fetch students with any existing marks (total) in one query via LEFT JOIN
$sql = "SELECT s.student_id, s.student_name AS name, s.roll_no,
               COALESCE(m.total_marks, NULL) AS marks_obtained
        FROM students s
        LEFT JOIN marks m
          ON m.student_id = s.student_id AND m.exam_id = ? AND m.subject_id = ?
        WHERE s.class_id = ? AND s.section_id = ?
        ORDER BY s.roll_no ASC";

$students = [];
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('iiii', $exam_id, $subject_id, $class_id, $section_id);
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $students[] = [
                'student_id' => (int)$row['student_id'],
                'name' => $row['name'],
                'roll_no' => (int)$row['roll_no'],
                'marks_obtained' => $row['marks_obtained'] !== null ? (float)$row['marks_obtained'] : ''
            ];
        }
    }
    $stmt->close();
}

api_response(true, ['students' => $students]);
