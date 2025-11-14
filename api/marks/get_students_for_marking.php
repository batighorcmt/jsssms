<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

api_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_response(false, 'Invalid request method', 405);
}

$exam_id = (int)($_GET['exam_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$subject_id = (int)($_GET['subject_id'] ?? 0);
$section_id = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0; // optional
if (!$exam_id || !$class_id || !$subject_id) {
    api_response(false, 'Missing required parameters', 400);
}

// Subject meta for this exam
$meta = [
  'creativeMax'=>0,'objectiveMax'=>0,'practicalMax'=>0,'passType'=>'total',
  'creativePass'=>0,'objectivePass'=>0,'practicalPass'=>0
];
if ($st = $conn->prepare('SELECT creative_marks, objective_marks, practical_marks, pass_type, COALESCE(creative_pass,0) cpass, COALESCE(objective_pass,0) opass, COALESCE(practical_pass,0) ppass FROM exam_subjects WHERE exam_id=? AND subject_id=? LIMIT 1')){
  $st->bind_param('ii',$exam_id,$subject_id); $st->execute(); $r=$st->get_result()->fetch_assoc();
  if ($r){
    $meta = [
      'creativeMax'=>(int)$r['creative_marks'],
      'objectiveMax'=>(int)$r['objective_marks'],
      'practicalMax'=>(int)$r['practical_marks'],
      'passType'=>$r['pass_type'],
      'creativePass'=>(int)$r['cpass'],
      'objectivePass'=>(int)$r['opass'],
      'practicalPass'=>(int)$r['ppass'],
    ];
  }
  $st->close();
}

// Students who have this subject
$students = [];
$sql = "SELECT s.student_id, s.student_name AS name, s.roll_no,
               m.creative_marks, m.objective_marks, m.practical_marks
        FROM students s
        JOIN student_subjects ss ON ss.student_id = s.student_id AND ss.subject_id = ?
        LEFT JOIN marks m ON m.student_id = s.student_id AND m.exam_id = ? AND m.subject_id = ?
        WHERE s.class_id = ?" . ($section_id>0 ? " AND s.section_id = ?" : "") . "
        ORDER BY s.roll_no ASC";

if ($stmt = $conn->prepare($sql)) {
    if ($section_id>0) {
        $stmt->bind_param('iiiii', $subject_id, $exam_id, $subject_id, $class_id, $section_id);
    } else {
        $stmt->bind_param('iiii', $subject_id, $exam_id, $subject_id, $class_id);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $students[] = [
                'student_id' => (int)$row['student_id'],
                'name' => $row['name'],
                'roll_no' => (int)$row['roll_no'],
                'creative' => isset($row['creative_marks']) ? (float)$row['creative_marks'] : '',
                'objective' => isset($row['objective_marks']) ? (float)$row['objective_marks'] : '',
                'practical' => isset($row['practical_marks']) ? (float)$row['practical_marks'] : ''
            ];
        }
    }
    $stmt->close();
}

api_response(true, ['meta'=>$meta,'students' => $students]);
