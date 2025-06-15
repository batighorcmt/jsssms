<?php
include '../config/db.php'; // DB connection

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 1;
$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 1;
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Load subjects with full info
$subjects = [];
$sql = "SELECT s.*, es.subject_id as es_subject_id, es.creative_pass, es.objective_pass, es.practical_pass FROM exam_subjects es 
        JOIN subjects s ON es.subject_id = s.id 
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id AND es.active = 'Yes' 
        ORDER BY s.subject_code ASC";
$result = mysqli_query($conn, $sql);
while ($row = mysqli_fetch_assoc($result)) {
    $subjects[] = $row;
}

// Insert combined subjects: Bangla after 102, English after 108
$final_subjects = [];
foreach ($subjects as $subj) {
    $final_subjects[] = $subj;
    if ($subj['subject_code'] == '102') {
        $final_subjects[] = [
            'subject_name' => 'Bangla',
            'subject_code' => 'bangla_combined',
            'has_creative' => 1, 'has_objective' => 1, 'has_practical' => 0
        ];
    }
    if ($subj['subject_code'] == '108') {
        $final_subjects[] = [
            'subject_name' => 'English',
            'subject_code' => 'english_combined',
            'has_creative' => 1, 'has_objective' => 1, 'has_practical' => 0
        ];
    }
}

// Fetch students
$students = [];
$student_sql = "SELECT student_id, roll_no, student_name FROM students WHERE class_id = $class_id AND year = $year AND status = 'Active' ORDER BY roll_no ASC";
$student_result = mysqli_query($conn, $student_sql);
while ($row = mysqli_fetch_assoc($student_result)) {
    $students[] = $row;
}

// Fetch marks
$marks = [];
$mark_sql = "SELECT * FROM marks WHERE exam_id = $exam_id";
$mark_result = mysqli_query($conn, $mark_sql);
while ($row = mysqli_fetch_assoc($mark_result)) {
    $marks[$row['student_id']][$row['subject_id']] = [
        'creative' => $row['creative_marks'],
        'objective' => $row['objective_marks'],
        'practical' => $row['practical_marks'],
    ];
}

// GPA calculation helper
function calculate_gpa($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    else return 0.00;
}

?>
<table border="1" cellpadding="6" cellspacing="0">
    <thead>
        <tr>
            <th rowspan="2">Roll</th>
            <th rowspan="2">Name</th>
            <?php foreach ($final_subjects as $sub): 
                $code = $sub['subject_code'];
                $colspan = 0;
                if ($sub['has_creative']) $colspan++;
                if ($sub['has_objective']) $colspan++;
                if ($sub['has_practical']) $colspan++;
                $colspan += ($code === 'bangla_combined' || $code === 'english_combined') ? 2 : 2;
                echo '<th colspan="' . $colspan . '">' . $sub['subject_name'] . '</th>';
            endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($final_subjects as $sub):
                if ($sub['has_creative']) echo '<th>C</th>';
                if ($sub['has_objective']) echo '<th>O</th>';
                if ($sub['has_practical']) echo '<th>P</th>';
                echo '<th>Total</th>';
                $code = $sub['subject_code'];
                if (in_array($code, ['bangla_combined', 'english_combined'])) echo '<th>GPA</th>';
                else echo '<th>GPA</th>';
            endforeach; ?>
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
                    $creative = $objective = $practical = 0;

                    if ($code === 'bangla_combined') {
                        $b1 = $marks[$std['student_id']][1] ?? ['creative' => 0, 'objective' => 0];
                        $b2 = $marks[$std['student_id']][2] ?? ['creative' => 0, 'objective' => 0];
                        $creative = ($b1['creative'] ?? 0) + ($b2['creative'] ?? 0);
                        $objective = ($b1['objective'] ?? 0) + ($b2['objective'] ?? 0);
                        $total = $creative + $objective;
                        $gpa = number_format(calculate_gpa($total), 2);
                        echo "<td>$creative</td><td>$objective</td><td>$total</td><td>$gpa</td>";
                        continue;
                    }

                    if ($code === 'english_combined') {
                        $e1 = $marks[$std['student_id']][3] ?? ['creative' => 0, 'objective' => 0];
                        $e2 = $marks[$std['student_id']][4] ?? ['creative' => 0, 'objective' => 0];
                        $creative = ($e1['creative'] ?? 0) + ($e2['creative'] ?? 0);
                        $objective = ($e1['objective'] ?? 0) + ($e2['objective'] ?? 0);
                        $total = $creative + $objective;
                        $gpa = number_format(calculate_gpa($total), 2);
                        echo "<td>$creative</td><td>$objective</td><td>$total</td><td>$gpa</td>";
                        continue;
                    }

                    $sub_id = $sub['id'] ?? 0;
                    $m = $marks[$std['student_id']][$sub_id] ?? ['creative' => 0, 'objective' => 0, 'practical' => 0];
                    $pass = true;

                    if ($sub['has_creative'] && $m['creative'] < $sub['creative_pass']) $pass = false;
                    if ($sub['has_objective'] && $m['objective'] < $sub['objective_pass']) $pass = false;
                    if ($sub['has_practical'] && $m['practical'] < $sub['practical_pass']) $pass = false;

                    $total = $m['creative'] + $m['objective'] + $m['practical'];
                    $gpa = ($code === 'bangla_combined' || $code === 'english_combined') ? number_format(calculate_gpa($total), 2) : '-';

                    if ($sub['has_creative']) echo '<td>' . $m['creative'] . '</td>';
                    if ($sub['has_objective']) echo '<td>' . $m['objective'] . '</td>';
                    if ($sub['has_practical']) echo '<td>' . $m['practical'] . '</td>';
                    echo '<td>' . $total . '</td>';
                    echo '<td>' . ($gpa === '-' ? ($pass ? number_format(calculate_gpa($total), 2) : '0.00') : $gpa) . '</td>';
                }
                ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>