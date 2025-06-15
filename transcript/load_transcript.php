<?php
session_start();
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (empty($student_id) || empty($exam_id)) {
    echo '<div class="alert alert-danger">ছাত্র আইডি এবং পরীক্ষা নির্বাচন করুন।</div>';
    exit;
}

// প্রতিষ্ঠান তথ্য
$school_name = "Jorepukuria Secondary School";
$school_address = "Gangni, Meherpur";
$logo_url = "../assets/logo.png"; // লোগো

// ছাত্র তথ্য
$sql = "SELECT s.student_id, s.student_name, s.roll_no, c.class_name, s.year, e.exam_name
        FROM students s
        JOIN classes c ON s.class_id = c.id
        JOIN exams e ON e.id = ?
        WHERE s.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $exam_id, $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    echo '<div class="alert alert-warning">ছাত্রের তথ্য পাওয়া যায়নি।</div>';
    exit;
}

// বিষয় ও নাম্বার
$sql = "SELECT sub.subject_name, sub.subject_code, sub.type, 
               es.creative_marks, es.objective_marks, es.practical_marks,
               m.creative_marks AS c, m.objective_marks AS o, m.practical_marks AS p
        FROM marks m
        JOIN subjects sub ON m.subject_id = sub.id
        JOIN exam_subjects es ON m.subject_id = es.subject_id AND es.exam_id = m.exam_id
        WHERE m.exam_id = ? AND m.student_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $exam_id, $student_id);
$stmt->execute();
$res = $stmt->get_result();

$subjects = [];
$total_marks = 0;
$total_gpa = 0;
$total_subject = 0;
$failed = 0;

// বিষয়গুলো গোষ্ঠীভুক্ত করা
while ($row = $res->fetch_assoc()) {
    $code = $row['subject_code'];
    $mark = floatval($row['c']) + floatval($row['o']) + floatval($row['p']);
    
    // বিষয় যুক্তকরণ: বাংলা (101+102), ইংরেজি (107+108)
    if (in_array($code, ['101', '102'])) {
        $group = 'Bangla';
    } elseif (in_array($code, ['107', '108'])) {
        $group = 'English';
    } else {
        $group = $row['subject_name'];
    }

    if (!isset($subjects[$group])) {
        $subjects[$group] = ['marks' => 0, 'count' => 0];
    }

    $subjects[$group]['marks'] += $mark;
    $subjects[$group]['count']++;

    // Fail logic
    $is_failed = false;
    if ($row['creative_marks'] > 0 && $row['c'] < 8) $is_failed = true;
    if ($row['objective_marks'] > 0 && $row['o'] < 8) $is_failed = true;
    if ($row['practical_marks'] > 0 && $row['p'] < 10) $is_failed = true;

    if ($is_failed) $failed++;
}

// GPA Function
function calculateGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}

// গ্রেড প্রস্তুত
foreach ($subjects as &$s) {
    $average = $s['marks'] / $s['count'];
    $s['gpa'] = calculateGPA($average);
    $s['total'] = round($average);
    $total_marks += $average;
    $total_gpa += $s['gpa'];
    $total_subject++;
}
unset($s);

$final_gpa = ($failed == 0 && $total_subject > 0) ? round($total_gpa / $total_subject, 2) : 0.00;
$status = ($failed == 0) ? "Passed" : "Failed";

?>

<style>
.watermark {
    position: absolute;
    top: 100px;
    left: 0;
    opacity: 0.1;
    width: 100%;
    text-align: center;
    z-index: 0;
}
</style>

<div class="container mt-4" id="printArea" style="position:relative;">
    <div class="watermark">
        <img src="<?= $logo_url ?>" width="300">
    </div>

    <div class="card shadow">
        <div class="card-body text-center">
            <img src="<?= $logo_url ?>" alt="Logo" width="80">
            <h3 class="mb-0"><?= $school_name ?></h3>
            <small><?= $school_address ?></small>
            <h5 class="mt-3 text-primary">ACADEMIC TRANSCRIPT</h5>
            <p><strong><?= $student['exam_name'] ?></strong> | Class: <?= $student['class_name'] ?> | Year: <?= $student['year'] ?></p>
        </div>
    </div>

    <div class="mt-3 row">
        <div class="col-md-6"><strong>Name:</strong> <?= $student['student_name'] ?></div>
        <div class="col-md-3"><strong>ID:</strong> <?= $student['student_id'] ?></div>
        <div class="col-md-3"><strong>Roll:</strong> <?= $student['roll_no'] ?></div>
    </div>

    <table class="table table-bordered mt-3 text-center">
        <thead class="table-primary">
            <tr>
                <th>SL</th>
                <th>Subject</th>
                <th>Total Marks</th>
                <th>GPA</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php $i = 1; foreach ($subjects as $name => $sub): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= $name ?></td>
                <td><?= $sub['total'] ?></td>
                <td><?= number_format($sub['gpa'], 2) ?></td>
                <td><?= ($sub['gpa'] > 0) ? 'Pass' : 'Fail' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="table-warning">
                <th colspan="2">Total</th>
                <th><?= round($total_marks) ?></th>
                <th><?= $final_gpa ?></th>
                <th><?= $status ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="mt-4 row">
        <div class="col-md-6">
            <strong>Grade Chart:</strong>
            <ul class="list-group list-group-sm">
                <li class="list-group-item">80–100: GPA 5.00</li>
                <li class="list-group-item">70–79: GPA 4.00</li>
                <li class="list-group-item">60–69: GPA 3.50</li>
                <li class="list-group-item">50–59: GPA 3.00</li>
                <li class="list-group-item">40–49: GPA 2.00</li>
                <li class="list-group-item">33–39: GPA 1.00</li>
                <li class="list-group-item">0–32: GPA 0.00</li>
            </ul>
        </div>
        <div class="col-md-6 text-end">
            <p class="mt-5">..............................................</p>
            <strong>Class Teacher</strong>
        </div>
    </div>
</div>

<div class="text-center my-4">
    <button onclick="printTranscript()" class="btn btn-success"><i class="bi bi-printer"></i> Print Transcript</button>
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
