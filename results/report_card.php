<?php
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$student_id || !$exam_id || !$year) {
    echo "<div class='alert alert-danger'>অনুগ্রহ করে ছাত্রের ID, পরীক্ষা ও সাল নির্ধারণ করুন।</div>";
    exit;
}

$student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM students WHERE student_id='$student_id'"));
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='{$student['class_id']}'"))['class_name'];
$section = mysqli_fetch_assoc(mysqli_query($conn, "SELECT section_name FROM sections WHERE id='{$student['section_id']}'"))['section_name'];
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];

$school_name = "Jorepukuria Secondary School";
$school_address = "Gangni, Meherpur";
$school_logo = "../assets/logo.png";

$class_id = $student['class_id'];
$all_students = [];
$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id = $class_id AND year = $year");
while ($stu = mysqli_fetch_assoc($students_q)) {
    $id = $stu['student_id'];
    $total = 0;

    foreach ([ 'Bangla' => ['101', '102'], 'English' => ['107', '108'] ] as $group => $codes) {
        $m_total = 0;
        foreach ($codes as $code) {
            $q = mysqli_query($conn, "SELECT creative_marks, objective_marks, practical_marks FROM marks m JOIN subjects s ON m.subject_id = s.id WHERE m.student_id='$id' AND m.exam_id='$exam_id' AND s.subject_code='$code'");
            $m = mysqli_fetch_assoc($q);
            $m_total += ($m['creative_marks'] + $m['objective_marks'] + $m['practical_marks']);
        }
        $total += $m_total;
    }

    $ind_q = mysqli_query($conn, "SELECT m.creative_marks, m.objective_marks, m.practical_marks FROM marks m JOIN subjects s ON m.subject_id = s.id WHERE m.student_id='$id' AND m.exam_id='$exam_id' AND s.subject_code NOT IN ('101','102','107','108')");
    while ($row = mysqli_fetch_assoc($ind_q)) {
        $total += ($row['creative_marks'] + $row['objective_marks'] + $row['practical_marks']);
    }

    $all_students[] = ['id' => $id, 'total' => $total];
}
usort($all_students, fn($a, $b) => $b['total'] <=> $a['total']);
$merit_pos = 0;
foreach ($all_students as $index => $s) {
    if ($s['id'] == $student_id) {
        $merit_pos = $index + 1;
        break;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Report Card</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #fdfdfd; padding: 30px; font-family: 'Segoe UI'; }
    .marksheet { border: 1px solid #ddd; border-radius: 12px; padding: 20px; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.08); }
    .school-header img { height: 60px; margin-right: 15px; }
    .school-header { text-align: center; margin-bottom: 20px; }
    .school-header h4 { margin: 0; }
    table th, table td { text-align: center; }
    .fail { color: red; font-weight: bold; }
    .pass { color: green; font-weight: bold; }
    @media print {.no-print { display: none; }}
  </style>
</head>
<body>
<div class="container marksheet">
  <div class="school-header">
    <img src="<?= $school_logo ?>">
    <h4><?= $school_name ?><br><small><?= $school_address ?></small></h4>
  </div>

  <h5 class="text-center mb-3">Report Card - <?= $exam ?> (<?= $year ?>)</h5>

  <table class="table table-bordered small">
    <tr><th>Student ID</th><td><?= $student['student_id'] ?></td><th>Roll</th><td><?= $student['roll_no'] ?></td></tr>
    <tr><th>Name</th><td><?= $student['student_name'] ?></td><th>Group</th><td><?= $student['student_group'] ?></td></tr>
    <tr><th>Father's Name</th><td><?= $student['father_name'] ?></td><th>Mother's Name</th><td><?= $student['mother_name'] ?></td></tr>
    <tr><th>Class</th><td><?= $class ?></td><th>Section</th><td><?= $section ?></td></tr>
    <tr><th>Merit Position</th><td colspan="3"><?= $merit_pos ?></td></tr>
  </table>

  <table class="table table-bordered table-striped">
    <thead class="table-light">
      <tr>
        <th>Subject Code</th>
        <th>Subject</th>
        <th>Creative</th>
        <th>MCQ</th>
        <th>Practical</th>
        <th>Total</th>
        <th>GPA</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php include 'report_card_subjects_logic.php'; ?>
    </tbody>
  </table>

  <div class="text-center no-print">
    <button onclick="window.print()" class="btn btn-primary">Print Report Card</button>
  </div>
</div>
</body>
</html>
