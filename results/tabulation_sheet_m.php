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
?><!DOCTYPE html><html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
        th, td { vertical-align: middle !important; font-size: 13px; border-spacing: 0px; padding: 2px; }
        @media print {
            .no-print { display: none; }
            table { font-size: 11px; border-spacing: 0px; padding: 2px; }
        }
        .fail-mark { color: red; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .text-center { text-align: center; }
        .table-bordered th, .table-bordered td { border: 1px solid #dee2e6; }
        .table-primary { background-color: #cfe2ff; }
    </style>
</head>
<body class="bg-light">
<div class="container my-5">
    <h3 class="text-center mb-4">Student Tabulation Sheet - <?= $year ?></h3>
    </div>
<div class="align-middle">
    <table class="table table-bordered text-center align-middle">
        <thead class="table-primary">
        <tr>
            <th rowspan="2">Roll</th>
            <th style="width: 20%;" rowspan="2">Name</th>
            <?php foreach ($final_subjects as $sub):
                $colspan = ($sub['has_creative'] ? 1 : 0) + ($sub['has_objective'] ? 1 : 0) + ($sub['has_practical'] ? 1 : 0) + 2;
                echo '<th colspan="' . $colspan . '">' . $sub['subject_name'] . '</th>';
            endforeach; ?>
            <th rowspan="2">Total Marks</th>
            <th rowspan="2">GPA</th>
            <th rowspan="2">Status</th>
            <th rowspan="2">Fail Count</th>
        </tr>
        <tr>
            <?php foreach ($final_subjects as $sub):
                if ($sub['has_creative']) echo '<th>C</th>';
                if ($sub['has_objective']) echo '<th>O</th>';
                if ($sub['has_practical']) echo '<th>P</th>';
                echo '<th>Total</th><th>GPA</th>';
            endforeach; ?>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($students as $std): ?>
            <tr>
                <td><?= $std['roll_no'] ?></td>
                <td class="text-start ps-2"><?= $std['student_name'] ?></td>
                <?php
                $total_marks_all = 0;
                $gpa_total = 0;
                $gpa_subjects = 0;
                $fail_count = 0;
                $status = 'Pass';foreach ($final_subjects as $sub) {
                $code = $sub['subject_code'];
                $creative = $objective = $practical = 0;

                if ($code === 'bangla_combined') {
                    $b1 = $marks[$std['student_id']][1] ?? ['creative' => 0, 'objective' => 0];
                    $b2 = $marks[$std['student_id']][2] ?? ['creative' => 0, 'objective' => 0];
                    $creative = ($b1['creative'] ?? 0) + ($b2['creative'] ?? 0);
                    $objective = ($b1['objective'] ?? 0) + ($b2['objective'] ?? 0);
                    $total = $creative + $objective;
                    $gpa = calculate_gpa($total);
                    echo "<td>$creative</td><td>$objective</td><td>$total</td><td>" . number_format($gpa, 2) . "</td>";
                    //$total_marks_all += $total;
                    $gpa_total += $gpa;
                    $gpa_subjects++;
                    if (!$pass && $code === 'bangla_combined') {
                        $fail_count++;
                       
                    }
                    continue;
                }

                if ($code === 'english_combined') {
                    $e1 = $marks[$std['student_id']][3] ?? ['creative' => 0, 'objective' => 0];
                    $e2 = $marks[$std['student_id']][4] ?? ['creative' => 0, 'objective' => 0];
                    $creative = ($e1['creative'] ?? 0) + ($e2['creative'] ?? 0);
                    $objective = ($e1['objective'] ?? 0) + ($e2['objective'] ?? 0);
                    $total = $creative + $objective;
                    $gpa = calculate_gpa($total);
                    echo "<td>$creative</td><td>$objective</td><td>$total</td><td>" . number_format($gpa, 2) . "</td>";
                    //$total_marks_all += $total;
                    $gpa_total += $gpa;
                    $gpa_subjects++;
                    if (!$pass && $code === 'english_combined') {
                        $fail_count++;
                        $status = 'Fail';
                    }
                    continue;
                }


                $sub_id = $sub['id'] ?? 0;
                $m = $marks[$std['student_id']][$sub_id] ?? ['creative' => 0, 'objective' => 0, 'practical' => 0];
                $pass = true;

                if ($sub['has_creative'] && $m['creative'] < $sub['creative_pass']) $pass = false;
                if ($sub['has_objective'] && $m['objective'] < $sub['objective_pass']) $pass = false;
                if ($sub['has_practical'] && $m['practical'] < $sub['practical_pass']) $pass = false;

                $total = $m['creative'] + $m['objective'] + $m['practical'];
                $gpa = calculate_gpa($total);
                
                if (!$pass && !in_array($code, ['101', '102', '107', '108'])) {
                $fail_count++;
                $status = 'Fail';
                }

                if ($sub['has_creative']) echo '<td>' . $m['creative'] . '</td>';
                if ($sub['has_objective']) echo '<td>' . $m['objective'] . '</td>';
                if ($sub['has_practical']) echo '<td>' . $m['practical'] . '</td>';
                echo '<td>' . $total . '</td>';
                echo '<td>' . ($pass ? number_format($gpa, 2) : '0.00') . '</td>';

                //$total_marks_all += $total;
            }

            if (!in_array($code, ['101', '102', '107', '108'])) {
            $total_marks_all += $total;
            $avg_gpa = $gpa_subjects ? number_format($gpa_total / $gpa_subjects, 2) : '0.00';
            $gpa_subjects++;   
            }



            //$avg_gpa = $gpa_subjects ? number_format($gpa_total / $gpa_subjects, 2) : '0.00';
            echo "<td>$total_marks_all</td><td>$avg_gpa</td><td>$status</td><td>$fail_count</td>";
            ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

