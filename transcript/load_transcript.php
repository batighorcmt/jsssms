<?php 
session_start();
if ($_SESSION['role'] !== 'super_admin') die('Access Denied');
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

// মার্জ বিষয়
$subject_groups = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

// Student Info
$stmt = $conn->prepare("SELECT student_name, roll_no FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

$exam = $conn->query("SELECT exam_name FROM exams WHERE id = $exam_id")->fetch_assoc();
$class = $conn->query("SELECT class_name FROM classes WHERE id = $class_id")->fetch_assoc();

$sql = "SELECT es.*, s.subject_name, s.subject_code 
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id";
$subjects = $conn->query($sql);

$grouped_subjects = [];
$individual_subjects = [];
$total_gpa = 0;
$total_marks = 0;
$subject_count = 0;
$fail_count = 0;

while ($row = $subjects->fetch_assoc()) {
    $code = $row['subject_code'];
    $subject_id = $row['subject_id'];
    $max_total = $row['total_marks'];
    $c_pass = $row['creative_pass'];
    $o_pass = $row['objective_pass'];
    $p_pass = $row['practical_pass'];

    $marks = $conn->query("SELECT creative_marks, objective_marks, practical_marks 
                           FROM marks WHERE student_id='$student_id' 
                           AND exam_id=$exam_id AND subject_id=$subject_id")->fetch_assoc();

    $c = $marks['creative_marks'] ?? 0;
    $o = $marks['objective_marks'] ?? 0;
    $p = $marks['practical_marks'] ?? 0;
    $t = $c + $o + $p;

    $group = null;
    foreach ($subject_groups as $g => $codes) {
        if (in_array($code, $codes)) {
            $group = $g;
            break;
        }
    }

    if ($group) {
        if (!isset($grouped_subjects[$group])) {
            $grouped_subjects[$group] = [
                'subject_name' => $group,
                'creative' => 0, 'objective' => 0, 'practical' => 0, 'total' => 0,
                'max_total' => 0,
                'c_pass' => 0, 'o_pass' => 0, 'p_pass' => 0
            ];
        }

        $grouped_subjects[$group]['creative'] += $c;
        $grouped_subjects[$group]['objective'] += $o;
        $grouped_subjects[$group]['practical'] += $p;
        $grouped_subjects[$group]['total'] += $t;
        $grouped_subjects[$group]['max_total'] += $max_total;
        $grouped_subjects[$group]['c_pass'] = max($grouped_subjects[$group]['c_pass'], $c_pass);
        $grouped_subjects[$group]['o_pass'] = max($grouped_subjects[$group]['o_pass'], $o_pass);
        $grouped_subjects[$group]['p_pass'] = max($grouped_subjects[$group]['p_pass'], $p_pass);
    } else {
        $individual_subjects[] = [
            'subject_name' => $row['subject_name'],
            'subject_code' => $code,
            'creative' => $c, 'objective' => $o, 'practical' => $p, 'total' => $t,
            'max_total' => $max_total,
            'c_pass' => $c_pass, 'o_pass' => $o_pass, 'p_pass' => $p_pass
        ];
    }
}
?>

<!-- HTML Layout -->
<style>
.watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.06;
    z-index: 0;
}
</style>

<div id="printArea" class="p-4 bg-white border rounded position-relative">
    <div class="watermark">
        <img src="../assets/logo.png" width="400">
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <img src="../assets/logo.png" height="90">
        <div class="text-center w-100" style="margin-left:-90px;">
            <h3 class="mb-0">Jorepukuria Secondary School</h3>
            <small>Gangni, Meherpur</small>
            <h5 class="mt-2">Academic Transcript</h5>
            <p><strong>Exam:</strong> <?= $exam['exam_name'] ?> | 
               <strong>Class:</strong> <?= $class['class_name'] ?> | 
               <strong>Year:</strong> <?= $year ?></p>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6"><strong>Name:</strong> <?= $student['student_name'] ?></div>
        <div class="col-md-3"><strong>Roll:</strong> <?= $student['roll_no'] ?></div>
        <div class="col-md-3"><strong>ID:</strong> <?= $student_id ?></div>
    </div>

    <table class="table table-bordered text-center">
        <thead class="table-primary">
            <tr>
                <th>Subject</th><th>Code</th><th>Creative</th><th>Objective</th><th>Practical</th>
                <th>Total</th><th>GPA</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($grouped_subjects as $name => $g) {
                $total = $g['total'];
                $max = $g['max_total'];
                $percent = ($total / $max) * 100;
                $gpa = getGPA($percent);
                $pass = ($class_id >= 9) ?
                    ($g['creative'] >= $g['c_pass'] && $g['objective'] >= $g['o_pass'] && $g['practical'] >= $g['p_pass']) :
                    ($percent >= 33);

                if (!$pass) $fail_count++;
                else $total_gpa += $gpa;

                $total_marks += $total;
                $subject_count++;

                echo "<tr class='".(!$pass ? 'table-danger' : '')."'>
                        <td>$name</td><td>-</td>
                        <td>{$g['creative']}</td><td>{$g['objective']}</td><td>{$g['practical']}</td>
                        <td>".round($total)."</td>
                        <td>".number_format($gpa, 2)."</td>
                        <td>".($pass ? 'Pass' : 'Fail')."</td>
                    </tr>";
            }

            foreach ($individual_subjects as $s) {
                $gpa = getGPA(($s['total'] / $s['max_total']) * 100);
                $pass = ($class_id >= 9) ?
                    ($s['creative'] >= $s['c_pass'] && $s['objective'] >= $s['o_pass'] && $s['practical'] >= $s['p_pass']) :
                    (($s['total'] / $s['max_total']) * 100 >= 33);

                if (!$pass) $fail_count++;
                else $total_gpa += $gpa;

                $total_marks += $s['total'];
                $subject_count++;

                echo "<tr class='".(!$pass ? 'table-danger' : '')."'>
                        <td>{$s['subject_name']}</td><td>{$s['subject_code']}</td>
                        <td>{$s['creative']}</td><td>{$s['objective']}</td><td>{$s['practical']}</td>
                        <td>{$s['total']}</td>
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
                <th><?= $fail_count > 0 ? "Failed in $fail_count" : 'Passed' ?></th>
            </tr>
        </tfoot>
    </table>
</div>

<div class="text-center mt-4">
    <button onclick="printTranscript()" class="btn btn-primary">
        <i class="bi bi-printer"></i> Print
    </button>
</div>

<script>
function printTranscript() {
    const printContents = document.getElementById('printArea').innerHTML;
    const win = window.open('', '', 'height=800,width=1000');
    win.document.write('<html><head><title>Print Transcript</title>');
    win.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    win.document.write('<style>.watermark{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);opacity:0.06;z-index:0;}</style>');
    win.document.write('</head><body>' + printContents + '</body></html>');
    win.document.close();
    win.focus();
    setTimeout(() => {
        win.print();
        win.close();
    }, 1000);
}
</script>
