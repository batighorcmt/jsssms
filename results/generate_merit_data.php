<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    http_response_code(403);
    exit('Access denied');
}

include '../config/db.php';

$exam_id = intval($_POST['exam_id']);
$class_id = intval($_POST['class_id']);
$year = intval($_POST['year']);

// Fetch students
$sql = "SELECT id, student_id, student_name FROM students WHERE class_id = ? AND year = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $class_id, $year);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[$row['student_id']] = [
        'student_id' => $row['student_id'],
        'student_name' => $row['student_name'],
        'total_marks' => 0,
        'total_gpa' => 0,
        'subject_count' => 0,
        'fail_count' => 0,
    ];
}

// Get subjects for this exam
$sql = "SELECT es.*, s.subject_code, s.type, s.class_id
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = ? AND s.class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $exam_id, $class_id);
$stmt->execute();
$subject_result = $stmt->get_result();

$subjects = [];
while ($row = $subject_result->fetch_assoc()) {
    $subjects[] = $row;
}

// Fetch marks for all relevant students and subjects
$subject_ids = array_column($subjects, 'subject_id');
$placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
$params = array_merge([$exam_id], $subject_ids);

$types = str_repeat('i', count($params));
$sql = "SELECT m.student_id, m.subject_id, m.creative_marks, m.objective_marks, m.practical_marks
        FROM marks m
        WHERE m.exam_id = ? AND m.subject_id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$mark_result = $stmt->get_result();

$marks_by_student = [];
while ($row = $mark_result->fetch_assoc()) {
    $marks_by_student[$row['student_id']][$row['subject_id']] = $row;
}

// GPA calculation function
function calculateGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}

// Process student results
foreach ($students as $sid => &$student) {
    $fail = 0;
    $total_marks = 0;
    $total_gpa = 0;
    $subject_count = 0;

    foreach ($subjects as $sub) {
        $sub_id = $sub['subject_id'];
        $type = $sub['type'];

        if (!isset($marks_by_student[$sid][$sub_id])) continue;

        $m = $marks_by_student[$sid][$sub_id];
        $c = floatval($m['creative_marks']);
        $o = floatval($m['objective_marks']);
        $p = floatval($m['practical_marks']);
        $t = $c + $o + $p;

        $subject_pass = true;

        if ($class_id >= 9) {
            if ($sub['creative_marks'] > 0 && $c < 8) $subject_pass = false;
            if ($sub['objective_marks'] > 0 && $o < 8) $subject_pass = false;
            if ($sub['practical_marks'] > 0 && $p < 10) $subject_pass = false;
        } else {
            if ($t < 33 || $c == 0 || $o == 0) $subject_pass = false;
        }

        if (!$subject_pass) {
            $fail++;
        } else {
            $total_gpa += calculateGPA($t);
        }

        $total_marks += $t;
        $subject_count++;
    }

    $student['total_marks'] = $total_marks;
    $student['fail_count'] = $fail;
    $student['subject_count'] = $subject_count;
    $student['total_gpa'] = ($fail == 0 && $subject_count > 0) ? round($total_gpa / $subject_count, 2) : 0.00;
}
unset($student);

// Group students by fail count, then sort by total marks
$groups = [];
foreach ($students as $stu) {
    $groups[$stu['fail_count']][] = $stu;
}
ksort($groups);

$merit_list = [];
foreach ($groups as $fail_count => $group) {
    usort($group, function($a, $b) {
        return $b['total_marks'] <=> $a['total_marks'];
    });
    foreach ($group as $stu) {
        $merit_list[] = $stu;
    }
}

// Show table
echo '<div class="table-responsive">';
echo '<table class="table table-bordered table-striped text-center">';
echo '<thead class="table-dark">';
echo '<tr><th>ক্রমিক</th><th>আইডি</th><th>নাম</th><th>মোট নাম্বার</th><th>GPA</th><th>ফেল সংখ্যা</th><th>মেধা স্থান</th></tr>';
echo '</thead><tbody>';

$serial = 1;
foreach ($merit_list as $stu) {
    echo "<tr>";
    echo "<td>{$serial}</td>";
    echo "<td>" . htmlspecialchars($stu['student_id']) . "</td>";
    echo "<td>" . htmlspecialchars($stu['student_name']) . "</td>";
    echo "<td>{$stu['total_marks']}</td>";
    echo "<td>{$stu['total_gpa']}</td>";
    echo "<td>{$stu['fail_count']}</td>";
    echo "<td>{$serial}</td>";
    echo "</tr>";
    $serial++;
}
echo '</tbody></table></div>';
