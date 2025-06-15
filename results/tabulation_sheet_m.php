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
            <?php foreach ($subjects as $sub):
                $colspan = 1;
                if ($sub['has_creative']) $colspan++;
                if ($sub['has_objective']) $colspan++;
                if ($sub['has_practical']) $colspan++;
            ?>
                <th colspan="<?= $colspan + 2 ?>"><?= $sub['subject_name'] ?></th>
            <?php endforeach; ?>
        </tr>
        <tr>
            <?php foreach ($subjects as $sub): ?>
                <?php if ($sub['has_creative']) echo '<th>C</th>'; ?>
                <?php if ($sub['has_objective']) echo '<th>O</th>'; ?>
                <?php if ($sub['has_practical']) echo '<th>P</th>'; ?>
                <th>Total</th>
                <th>GPA</th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($students as $std): ?>
            <tr>
                <td><?= $std['roll_no'] ?></td>
                <td><?= $std['student_name'] ?></td>
                <?php foreach ($subjects as $sub): 
                    $sub_id = $sub['id'];
                    $m = $marks[$std['student_id']][$sub_id] ?? ['creative' => 0, 'objective' => 0, 'practical' => 0];
                    $pass = true;

                    $creative = $sub['has_creative'] ? $m['creative'] : '-';
                    $objective = $sub['has_objective'] ? $m['objective'] : '-';
                    $practical = $sub['has_practical'] ? $m['practical'] : '-';

                    if ($sub['has_creative'] && $m['creative'] < $sub['creative_pass']) $pass = false;
                    if ($sub['has_objective'] && $m['objective'] < $sub['objective_pass']) $pass = false;
                    if ($sub['has_practical'] && $m['practical'] < $sub['practical_pass']) $pass = false;

                    $total = ($m['creative'] + $m['objective'] + $m['practical']);
                    $gpa = $pass ? number_format(calculate_gpa($total), 2) : "0.00";

                    if ($sub['has_creative']) echo "<td>$creative</td>";
                    if ($sub['has_objective']) echo "<td>$objective</td>";
                    if ($sub['has_practical']) echo "<td>$practical</td>";

                    echo "<td>$total</td><td>$gpa</td>";
                endforeach; ?>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>