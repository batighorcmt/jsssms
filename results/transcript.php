<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die('Access denied');
}
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$student_id || !$exam_id || !$year) {
    die('Invalid Request');
}

// Fetch student info
$sql = "SELECT s.*, c.class_name, g.student_group FROM students s 
        LEFT JOIN classes c ON s.class_id = c.id
        LEFT JOIN groups g ON s.group_id = g.id
        WHERE s.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) {
    die('Student not found');
}

// Fetch subjects for this class & exam
$sql = "SELECT es.*, subj.subject_name, subj.type FROM exam_subjects es
        JOIN subjects subj ON es.subject_id = subj.id
        WHERE es.exam_id = ? AND subj.class_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $exam_id, $student['class_id']);
$stmt->execute();
$subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Fetch marks for this student
$subject_ids = array_column($subjects, 'subject_id');
$placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
$types = str_repeat('i', count($subject_ids) + 1);
$params = array_merge([$exam_id], $subject_ids);

$sql = "SELECT * FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id IN ($placeholders)";
$stmt = $conn->prepare("SELECT * FROM marks WHERE exam_id = ? AND student_id = ? AND subject_id IN ($placeholders)");
$stmt->bind_param("si" . str_repeat("i", count($subject_ids)), $exam_id, $student_id, ...$subject_ids);
$stmt->execute();
$marks_result = $stmt->get_result();

$marks = [];
while ($row = $marks_result->fetch_assoc()) {
    $marks[$row['subject_id']] = $row;
}

// GPA function
function getGrade($mark) {
    if ($mark >= 80) return ['A+', 5.00];
    if ($mark >= 70) return ['A', 4.00];
    if ($mark >= 60) return ['A-', 3.50];
    if ($mark >= 50) return ['B', 3.00];
    if ($mark >= 40) return ['C', 2.00];
    if ($mark >= 33) return ['D', 1.00];
    return ['F', 0.00];
}

$total = 0;
$subject_count = 0;
$total_gpa = 0;
$fail_count = 0;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Academic Transcript</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        @media print {
            #printBtn { display: none; }
        }
        .header-logo {
            width: 60px;
            height: 60px;
        }
    </style>
</head>
<body class="bg-light">
<div class="container my-4" id="printArea">
    <div class="text-center mb-3">
        <img src="../assets/logo.png" class="header-logo float-start">
        <h3 class="mb-0">জোরেপুকুরিয়া মাধ্যমিক বিদ্যালয়</h3>
        <p>গাংনী, মেহেরপুর</p>
        <h4 class="text-primary">Academic Transcript / Report Card</h4>
    </div>

    <div class="row mb-3 border p-2">
        <div class="col-md-6">
            <strong>নাম:</strong> <?= htmlspecialchars($student['student_name']) ?><br>
            <strong>আইডি:</strong> <?= $student['student_id'] ?><br>
            <strong>রোল:</strong> <?= $student['roll_no'] ?>
        </div>
        <div class="col-md-6">
            <strong>শ্রেণি:</strong> <?= $student['class_name'] ?><br>
            <strong>গ্রুপ:</strong> <?= $student['group_name'] ?><br>
            <strong>সেশন:</strong> <?= $student['year'] ?>
        </div>
    </div>

    <table class="table table-bordered table-striped">
        <thead class="table-info text-center">
            <tr>
                <th>বিষয়</th>
                <th>সৃজনশীল</th>
                <th>নৈর্ব্যক্তিক</th>
                <th>ব্যবহারিক</th>
                <th>মোট</th>
                <th>গ্রেড</th>
                <th>GPA</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($subjects as $sub):
            $m = $marks[$sub['subject_id']] ?? ['creative_marks' => 0, 'objective_marks' => 0, 'practical_marks' => 0];
            $t = $m['creative_marks'] + $m['objective_marks'] + $m['practical_marks'];
            [$grade, $gpa] = getGrade($t);

            $total += $t;
            $subject_count++;
            if ($gpa == 0.00) $fail_count++;
            else $total_gpa += $gpa;
        ?>
        <tr class="<?= $gpa == 0.00 ? 'table-danger' : '' ?>">
            <td><?= $sub['subject_name'] ?></td>
            <td class="text-center"><?= $m['creative_marks'] ?></td>
            <td class="text-center"><?= $m['objective_marks'] ?></td>
            <td class="text-center"><?= $m['practical_marks'] ?></td>
            <td class="text-center"><?= $t ?></td>
            <td class="text-center"><?= $grade ?></td>
            <td class="text-center"><?= number_format($gpa, 2) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="row text-center mt-4">
        <div class="col-md-4">
            <strong>সর্বমোট নাম্বার:</strong> <?= $total ?>
        </div>
        <div class="col-md-4">
            <strong>ফাইনাল GPA:</strong> 
            <?= $fail_count == 0 ? number_format($total_gpa / $subject_count, 2) : '0.00 (Failed)' ?>
        </div>
        <div class="col-md-4">
            <strong>ফলাফল:</strong> <?= $fail_count == 0 ? 'উত্তীর্ণ' : 'অনুত্তীর্ণ' ?>
        </div>
    </div>

    <div class="row mt-5">
        <div class="col-6 text-center">
            ------------------------<br>
            অভিভাবকের স্বাক্ষর
        </div>
        <div class="col-6 text-center">
            ------------------------<br>
            প্রধান শিক্ষকের স্বাক্ষর
        </div>
    </div>
</div>

<div class="text-center mt-3">
    <button class="btn btn-primary" id="printBtn" onclick="printTranscript()">
        <i class="bi bi-printer"></i> প্রিন্ট করুন
    </button>
</div>

<script>
function printTranscript() {
    var contents = document.getElementById('printArea').innerHTML;
    var frame = window.open('', '', 'width=1000,height=800');
    frame.document.write('<html><head><title>Transcript</title>');
    frame.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    frame.document.write('</head><body>' + contents + '</body></html>');
    frame.document.close();
    frame.focus();
    frame.print();
    frame.close();
}
</script>
</body>
</html>
