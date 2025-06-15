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

// Get student info
$stmt = $conn->prepare("SELECT student_name, roll_no, class_id FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

// Get exam name
$exam = $conn->query("SELECT exam_name FROM exams WHERE id = $exam_id")->fetch_assoc();
// Get class name
$class = $conn->query("SELECT class_name FROM classes WHERE id = $class_id")->fetch_assoc();

// Get subjects
$sql = "SELECT es.*, s.subject_name, s.subject_code, s.type
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id";
$subjects = $conn->query($sql);

$total_marks = 0;
$total_gpa = 0;
$subject_count = 0;
$fail_count = 0;

function getGPA($marks) {
    if ($marks >= 80) return 5.00;
    elseif ($marks >= 70) return 4.00;
    elseif ($marks >= 60) return 3.50;
    elseif ($marks >= 50) return 3.00;
    elseif ($marks >= 40) return 2.00;
    elseif ($marks >= 33) return 1.00;
    else return 0.00;
}

?>
<div id="printArea" class="p-4 bg-white border rounded">
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
        <?php while ($sub = $subjects->fetch_assoc()):
            $marks = $conn->query("SELECT creative_marks, objective_marks, practical_marks FROM marks WHERE student_id='$student_id' AND exam_id=$exam_id AND subject_id=".$sub['subject_id'])->fetch_assoc();
            $c = $marks['creative_marks'] ?? 0;
            $o = $marks['objective_marks'] ?? 0;
            $p = $marks['practical_marks'] ?? 0;
            $t = $c + $o + $p;
            $gpa = getGPA($t);
            $pass = true;

            if ($class_id >= 9) {
                if ($sub['creative_marks'] > 0 && $c < 8) $pass = false;
                if ($sub['objective_marks'] > 0 && $o < 8) $pass = false;
                if ($sub['practical_marks'] > 0 && $p < 10) $pass = false;
            } else {
                if ($t < 33 || $c == 0 || $o == 0) $pass = false;
            }

            if (!$pass) $fail_count++;
            else $total_gpa += $gpa;

            $total_marks += $t;
            $subject_count++;
        ?>
            <tr class="<?= !$pass ? 'table-danger' : '' ?>">
                <td><?= $sub['subject_name'] ?></td>
                <td><?= $sub['subject_code'] ?></td>
                <td><?= $c ?></td>
                <td><?= $o ?></td>
                <td><?= $p ?></td>
                <td><?= $t ?></td>
                <td><?= number_format($gpa, 2) ?></td>
                <td><?= $pass ? 'Pass' : 'Fail' ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
        <tfoot class="table-secondary">
            <tr>
                <th colspan="5">Total</th>
                <th><?= $total_marks ?></th>
                <th><?= ($fail_count == 0 && $subject_count > 0) ? number_format($total_gpa / $subject_count, 2) : '0.00' ?></th>
                <th><?= $fail_count > 0 ? 'Failed in '.$fail_count : 'Passed' ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="mt-4 row">
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
</div>

<div class="text-center mt-3">
    <button onclick="printTranscript()" class="btn btn-primary"><i class="bi bi-printer"></i> Print</button>
</div>

<script>
function printTranscript() {
    var printContents = document.getElementById('printArea').innerHTML;
    var newWindow = window.open('', '', 'width=900,height=1000');
    newWindow.document.write('<html><head><title>Transcript</title>');
    newWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    newWindow.document.write('</head><body>' + printContents + '</body></html>');
    newWindow.document.close();
    newWindow.focus();
    newWindow.print();
    newWindow.close();
}
</script>
