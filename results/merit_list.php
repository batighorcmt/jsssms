<?php
session_start();
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_id = intval($_POST['exam_id']);
    $class_id = intval($_POST['class_id']);
    $year = intval($_POST['year']);

    // 1. Load all students for that class & year
    $students = [];
    $sql = "SELECT student_id, student_name FROM students WHERE class_id = ? AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $class_id, $year);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $students[$row['student_id']] = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'total_marks' => 0,
            'total_gpa' => 0,
            'fail_count' => 0,
        ];
    }

    // 2. Load all subjects for this exam and class
    $subjects = [];
    $sql = "SELECT es.subject_id, s.subject_name, s.pass_type, s.group, s.has_creative, s.has_objective, s.has_practical,
                   es.creative_marks, es.objective_marks, es.practical_marks
            FROM exam_subjects es
            JOIN subjects s ON es.subject_id = s.id
            WHERE es.exam_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $subjects[$row['subject_id']] = $row;
    }

    // 3. Load all marks
    $sql = "SELECT * FROM marks WHERE exam_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $exam_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid = $row['student_id'];
        $sub_id = $row['subject_id'];
        if (!isset($students[$sid]) || !isset($subjects[$sub_id])) continue;

        $sub = $subjects[$sub_id];
        $total = $row['creative_marks'] + $row['objective_marks'] + $row['practical_marks'];

        $is_fail = false;
        if ($sub['pass_type'] === 'total') {
            if ($total < 33 || $row['creative_marks'] == 0 || $row['objective_marks'] == 0) $is_fail = true;
        } else {
            $cpass = ($sub['creative_marks'] > 0) ? ceil($sub['creative_marks'] * 0.33) : 0;
            $opass = ($sub['objective_marks'] > 0) ? ceil($sub['objective_marks'] * 0.33) : 0;
            $ppass = ($sub['practical_marks'] > 0) ? ceil($sub['practical_marks'] * 0.33) : 0;

            if (($sub['has_creative'] && $row['creative_marks'] < $cpass) ||
                ($sub['has_objective'] && $row['objective_marks'] < $opass) ||
                ($sub['has_practical'] && $row['practical_marks'] < $ppass)) {
                $is_fail = true;
            }
        }

        if ($is_fail) {
            $students[$sid]['fail_count']++;
        }

        $students[$sid]['total_marks'] += $total;
        $students[$sid]['total_gpa'] += getGPA($total);
    }

    // 4. Calculate merit position
    $data = [];
    foreach ($students as $stu) {
        $subject_count = count($subjects);
        $gpa = ($stu['fail_count'] > 0 || $subject_count == 0) ? 0.00 : round($stu['total_gpa'] / $subject_count, 2);
        $data[] = [
            'student_id' => $stu['student_id'],
            'student_name' => $stu['student_name'],
            'total_marks' => $stu['total_marks'],
            'total_gpa' => $gpa,
            'fail_count' => $stu['fail_count']
        ];
    }

    // Sort by fail_count ASC, then total_marks DESC
    usort($data, function ($a, $b) {
        return [$a['fail_count'], -$a['total_marks']] <=> [$b['fail_count'], -$b['total_marks']];
    });

    // Add merit position
    $position = 1;
    foreach ($data as $index => $row) {
        $data[$index]['merit_position'] = $position++;
    }

    // Output as Table
    echo '<div class="table-responsive"><table class="table table-bordered mt-4">';
    echo '<thead><tr><th>ক্রমিক</th><th>আইডি</th><th>নাম</th><th>মোট নম্বর</th><th>GPA</th><th>ফেল</th><th>Merit Position</th></tr></thead><tbody>';
    $i = 1;
    foreach ($data as $row) {
        $failStyle = $row['fail_count'] > 0 ? 'style="color:red;font-weight:bold;"' : '';
        echo "<tr>
                <td>{$i}</td>
                <td>{$row['student_id']}</td>
                <td>{$row['student_name']}</td>
                <td>{$row['total_marks']}</td>
                <td>{$row['total_gpa']}</td>
                <td {$failStyle}>{$row['fail_count']}</td>
                <td>{$row['merit_position']}</td>
              </tr>";
        $i++;
    }
    echo '</tbody></table></div>';
    exit;
}

function getGPA($mark) {
    if ($mark >= 80) return 5.00;
    if ($mark >= 70) return 4.00;
    if ($mark >= 60) return 3.50;
    if ($mark >= 50) return 3.00;
    if ($mark >= 40) return 2.00;
    if ($mark >= 33) return 1.00;
    return 0.00;
}
?>

<!-- Simple Form UI to test merit list -->
<form method="POST" class="p-3 bg-light">
    <label>Exam ID: <input type="number" name="exam_id" required></label>
    <label>Class ID: <input type="number" name="class_id" required></label>
    <label>Year: <input type="number" name="year" required></label>
    <button type="submit" class="btn btn-primary">Generate Merit List</button>
</form>