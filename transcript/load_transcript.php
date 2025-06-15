<?php
session_start();
if ($_SESSION['role'] !== 'super_admin') {
    die('Access Denied');
}
include '../config/db.php';

$student_id = $_POST['student_id'];
$exam_id = $_POST['exam_id'];
$class_id = $_POST['class_id'];
$year = $_POST['year'];

function getGPA($marks) {
    if ($marks >= 80) return 5.00;
    elseif ($marks >= 70) return 4.00;
    elseif ($marks >= 60) return 3.50;
    elseif ($marks >= 50) return 3.00;
    elseif ($marks >= 40) return 2.00;
    elseif ($marks >= 33) return 1.00;
    else return 0.00;
}

$subject_groups = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

// Student info
$stmt = $conn->prepare("SELECT student_name, roll_no, class_id FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Exam, class info
$exam = $conn->query("SELECT exam_name FROM exams WHERE id = $exam_id")->fetch_assoc();
$class = $conn->query("SELECT class_name FROM classes WHERE id = $class_id")->fetch_assoc();

// Subjects
$sql = "SELECT es.*, s.subject_name, s.subject_code, s.type
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id";
$subjects = $conn->query($sql);

$grouped_subjects = [];
$individual_subjects = [];
$total_marks = 0;
$total_gpa = 0;
$subject_count = 0;
$fail_count = 0;

// Subject grouping
while ($sub = $subjects->fetch_assoc()) {
    $code = $sub['subject_code'];
    $subject_id = $sub['subject_id'];

    $marks = $conn->query("SELECT creative_marks, objective_marks, practical_marks 
                           FROM marks 
                           WHERE student_id='$student_id' AND exam_id=$exam_id AND subject_id=$subject_id")->fetch_assoc();

    $c = $marks['creative_marks'] ?? 0;
    $o = $marks['objective_marks'] ?? 0;
    $p = $marks['practical_marks'] ?? 0;
    $t = $c + $o + $p;

    $key = null;
    foreach ($subject_groups as $group => $codes) {
        if (in_array($code, $codes)) {
            $key = $group;
            break;
        }
    }

    if ($key) {
        if (!isset($grouped_subjects[$key])) {
            $grouped_subjects[$key] = ['subject_name' => $key, 'creative' => 0, 'objective' => 0, 'practical' => 0, 'total' => 0, 'count' => 0];
        }
        $grouped_subjects[$key]['creative'] += $c;
        $grouped_subjects[$key]['objective'] += $o;
        $grouped_subjects[$key]['practical'] += $p;
        $grouped_subjects[$key]['total'] += $t;
        $grouped_subjects[$key]['count']++;
    } else {
        $individual_subjects[] = ['subject_name' => $sub['subject_name'], 'subject_code' => $code, 'c' => $c, 'o' => $o, 'p' => $p, 't' => $t, 'original' => $sub];
    }
}
?>

<style>
.watermark {
    position: absolute;
    top: 100px;
    left: 0;
    opacity: 0.06;
    width: 100%;
    text-align: center;
    z-index: 0;
}
</style>

<div id="printArea" class="p-4 bg-white border rounded position-relative">
    <div class="watermark">
        <img src="../assets/logo.png" width="400">
    </div>
    <div class="text-center mb-4">
        <img src="../assets/logo.png" alt="Logo" height="80">
        <h3 class="mb-0">Jorepukuria Secondary School</h3>
        <small>Gangni, Meherpur</small>
        <h5 class="mt-3">Academic Transcript</h5>
        <p><strong>Exam:</strong> <?= $exam['exam_name'] ?> | <strong>Class:</strong> <?= $class['class_name'] ?> | <strong>Year:</strong> <?= $year ?></p>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Name:</strong> <?= $student['student_name'] ?></div>
        <div class="col-md-3"><strong>Roll:</strong> <?= $student['roll_no'] ?></div>
        <div class="col-md-3"><strong>ID:</strong> <?= $student_id ?></div>
    </div>

    <table class="table table-bordered text-center">
        <thead class="table-primary">
            <tr>
                <th>Subject</th>
                <th>Code</th>
                <th>Creative</th>
                <th>Objective</th>
                <th>Practical</th>
                <th>Total</th>
                <th>GPA</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($grouped_subjects as $name => $g) {
                $avg = $g['total'] / $g['count'];
                $gpa = getGPA($avg);
                $pass = ($class_id >= 9) ? ($g['creative'] >= 16 && $g['objective'] >= 16) : ($avg >= 33);

                if (!$pass) $fail_count++;
                else $total_gpa += $gpa;

                $total_marks += $avg;
                $subject_count++;

                echo "<tr class='".(!$pass ? 'table-danger' : '')."'>
                        <td>$name</td><td>-</td>
                        <td>{$g['creative']}</td><td>{$g['objective']}</td><td>{$g['practical']}</td>
                        <td>".round($avg)."</td>
                        <td>".number_format($gpa,2)."</td>
                        <td>".($pass ? 'Pass' : 'Fail')."</td>
                    </tr>";
            }

            foreach ($individual_subjects as $s) {
                $gpa = getGPA($s['t']);
                $pass = true;

                if ($class_id >= 9) {
                    if ($s['original']['creative_marks'] > 0 && $s['c'] < 8) $pass = false;
                    if ($s['original']['objective_marks'] > 0 && $s['o'] < 8) $pass = false;
                    if ($s['original']['practical_marks'] > 0 && $s['p'] < 10) $pass = false;
                } else {
                    if ($s['t'] < 33 || $s['c'] == 0 || $s['o'] == 0) $pass = false;
                }

                if (!$pass) $fail_count++;
                else $total_gpa += $gpa;

                $total_marks += $s['t'];
                $subject_count++;

                echo "<tr class='".(!$pass ? 'table-danger' : '')."'>
                        <td>{$s['subject_name']}</td><td>{$s['subject_code']}</td>
                        <td>{$s['c']}</td><td>{$s['o']}</td><td>{$s['p']}</td>
                        <td>{$s['t']}</td>
                        <td>".number_format($gpa,2)."</td>
                        <td>".($pass ? 'Pass' : 'Fail')."</td>
                    </tr>";
            }
            ?>
        </tbody>
        <tfoot class="table-secondary">
            <tr>
                <th colspan="5">Total</th>
                <th><?= round($total_marks) ?></th>
                <th><?= ($fail_count == 0 && $subject_count > 0) ? number_format($total_gpa / $subject_count, 2) : '0.00' ?></th>
                <th><?= $fail_count > 0 ? 'Failed in '.$fail_count : 'Passed' ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="row mt-4">
        <div class="col-md-6">
            <strong>Comments:</strong>
            <p><?= $fail_count > 0 ? 'Needs Improvement' : 'Excellent Performance' ?></p>
        </div>
        <div class="col-md-6">
            <strong>Grade Chart:</strong>
            <table class="table table-sm table-bordered">
                <thead><tr><th>Marks</th><th>Grade</th><th>GPA</th></tr></thead>
                <tbody>
                    <tr><td>80-100</td><td>A+</td><td>5.00</td></tr>
                    <tr><td>70-79</td><td>A</td><td>4.00</td></tr>
                    <tr><td>60-69</td><td>A-</td><td>3.50</td></tr>
                    <tr><td>50-59</td><td>B</td><td>3.00</td></tr>
                    <tr><td>40-49</td><td>C</td><td>2.00</td></tr>
                    <tr><td>33-39</td><td>D</td><td>1.00</td></tr>
                    <tr><td>0-32</td><td>F</td><td>0.00</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row mt-5">
        <div class="col-md-6"></div>
        <div class="col-md-6 text-end">
            <p>.......................................</p>
            <strong>Class Teacher's Signature</strong>
        </div>
    </div>
</div>

<div class="text-center mt-4">
    <button onclick="printTranscript()" class="btn btn-primary">
        <i class="bi bi-printer"></i> Print
    </button>
</div>

<script>
function printTranscript() {
    var content = document.getElementById('printArea').innerHTML;
    var win = window.open('', '', 'width=1000,height=1000');
    win.document.write('<html><head><title>Transcript</title>');
    win.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    win.document.write('<style>.watermark{position:absolute;top:100px;left:0;opacity:0.06;width:100%;text-align:center;z-index:0;}</style>');
    win.document.write('</head><body>' + content + '</body></html>');
    win.document.close();
    win.focus();
    win.print();
    win.close();
}
</script>
