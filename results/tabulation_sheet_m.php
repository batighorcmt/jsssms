<?php
include '../config/db.php'; // DB connection

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 1;
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 1;
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Load subjects for the class & exam
$subjects = [];
$sql = "SELECT s.id, s.subject_name, s.subject_code FROM exam_subjects es 
        JOIN subjects s ON es.subject_id = s.id 
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id AND es.active = 'Yes' 
        ORDER BY s.subject_code ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

// Inject custom subject columns after 102 and 108
$final_subjects = [];
foreach ($subjects as $subject) {
    $final_subjects[] = $subject;

    if ($subject['subject_code'] === '102') {
        $final_subjects[] = ['subject_name' => 'Bangla', 'subject_code' => 'bangla_combined'];
    }
    if ($subject['subject_code'] === '108') {
        $final_subjects[] = ['subject_name' => 'English', 'subject_code' => 'english_combined'];
    }
}

// Fetch all students in class
$students = [];
$student_sql = "SELECT student_id, roll_no, student_name FROM students WHERE class_id = $class_id AND year = $year AND status = 'Active' ORDER BY roll_no ASC";
$student_result = mysqli_query($conn, $student_sql);
while ($row = mysqli_fetch_assoc($student_result)) {
    $students[] = $row;
}

// Build mark matrix [student_id][subject_id] = total_marks
$marks = [];
$mark_sql = "SELECT * FROM marks WHERE exam_id = $exam_id";
$mark_result = mysqli_query($conn, $mark_sql);
while ($row = mysqli_fetch_assoc($mark_result)) {
    $marks[$row['student_id']][$row['subject_id']] = $row['creative_marks'] + $row['objective_marks'] + $row['practical_marks'];
}

// Begin HTML table
?>
<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th>Roll</th>
            <th>Name</th>
            <?php foreach ($final_subjects as $sub): ?>
                <th><?= $sub['subject_name'] ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($students as $std): ?>
            <tr>
                <td><?= $std['roll_no'] ?></td>
                <td><?= $std['student_name'] ?></td>
                <?php
                foreach ($final_subjects as $sub) {
                    $code = $sub['subject_code'];

                    if ($code === 'bangla_combined') {
                        $mark1 = $marks[$std['student_id']][1] ?? 0;
                        $mark2 = $marks[$std['student_id']][2] ?? 0;
                        echo '<td>' . ($mark1 + $mark2) . '</td>';
                    } elseif ($code === 'english_combined') {
                        $mark1 = $marks[$std['student_id']][3] ?? 0;
                        $mark2 = $marks[$std['student_id']][4] ?? 0;
                        echo '<td>' . ($mark1 + $mark2) . '</td>';
                    } else {
                        $subject_id = null;
                        foreach ($subjects as $s) {
                            if ($s['subject_code'] === $code) {
                                $subject_id = $s['id'];
                                break;
                            }
                        }
                        $mark = $subject_id ? ($marks[$std['student_id']][$subject_id] ?? '-') : '-';
                        echo '<td>' . $mark . '</td>';
                    }
                }
                ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
