<?php 
// load_transcript.php
session_start();
if ($_SESSION['role'] !== 'super_admin') die('Access Denied');
include '../config/db.php';

$student_id = $_POST['student_id'] ?? null;
$exam_id = $_POST['exam_id'] ?? null;
$class_id = $_POST['class_id'] ?? null;
$year = $_POST['year'] ?? null;

if (!$exam_id || !$class_id || !$year || !$student_id) {
    die("Invalid request");
}

function getGPA($percentage) {
    if ($percentage >= 80) return 5.00;
    elseif ($percentage >= 70) return 4.00;
    elseif ($percentage >= 60) return 3.50;
    elseif ($percentage >= 50) return 3.00;
    elseif ($percentage >= 40) return 2.00;
    elseif ($percentage >= 33) return 1.00;
    else return 0.00;
}

// Begin rendering transcript
?>
<div id="printArea">
    <h4 class="text-center mb-4">Student Transcript</h4>
    <table class="table table-bordered">
        <thead class="table-dark">
            <tr>
                <th>Subject Code</th>
                <th>Subject</th>
                <th>Creative</th>
                <th>Objective</th>
                <th>Practical</th>
                <th>Total Marks</th>
                <th>GPA</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
<?php
$sql = "SELECT m.*, s.subject_code, s.subject_name, es.total_marks, es.creative_pass, es.objective_pass, es.practical_pass
        FROM marks m
        JOIN subjects s ON m.subject_id = s.id
        JOIN exam_subjects es ON m.subject_id = es.subject_id AND es.exam_id = ?
        WHERE m.exam_id = ? AND m.class_id = ? AND m.year = ? AND m.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiii", $exam_id, $exam_id, $class_id, $year, $student_id);
$stmt->execute();
$result = $stmt->get_result();

$total_marks = 0;
$total_gpa = 0;
$subject_count = 0;
$fail_count = 0;

while ($row = $result->fetch_assoc()) {
    $subject_code = $row['subject_code'];
    $subject_name = $row['subject_name'];
    $creative = $row['creative_marks'];
    $objective = $row['objective_marks'];
    $practical = $row['practical_marks'];
    $total = $creative + $objective + $practical;
    $percentage = ($total / $row['total_marks']) * 100;
    $gpa = getGPA($percentage);

    $status = 'Passed';
    $classCheck = ($class_id >= 9) ? true : false;
    if ($classCheck) {
        if ($creative < $row['creative_pass'] || $objective < $row['objective_pass'] || $practical < $row['practical_pass']) {
            $status = 'Fail';
            $gpa = 0.00;
            $fail_count++;
        }
    } else {
        if ($percentage < 33) {
            $status = 'Fail';
            $gpa = 0.00;
            $fail_count++;
        }
    }

    echo "<tr>
        <td>{$subject_code}</td>
        <td>{$subject_name}</td>
        <td>{$creative}</td>
        <td>{$objective}</td>
        <td>{$practical}</td>
        <td>{$total}</td>
        <td>" . number_format($gpa, 2) . "</td>
        <td>{$status}</td>
    </tr>";

    $total_marks += $total;
    $total_gpa += $gpa;
    $subject_count++;
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
    win.document.write('</head><body>' + printContents + '</body></html>');
    win.document.close();
    win.focus();
    setTimeout(() => {
        win.print();
        win.close();
    }, 1000);
}
</script>
