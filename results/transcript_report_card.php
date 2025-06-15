<?php
// Configuration
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (!$student_id || !$exam_id) {
    echo "<div class='alert alert-danger'>Invalid Request</div>";
    exit;
}

// Get student info
$student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM students WHERE student_id='$student_id'"));
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='$exam_id'"));

$class_id = $student['class_id'];
$year = $student['year'];

// Get subjects
$subject_q = mysqli_query($conn, "
    SELECT s.id, s.subject_name, s.subject_code, s.has_creative, s.has_objective, s.has_practical, es.creative_pass, es.objective_pass, es.practical_pass
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subject_q)) {
    $subjects[] = $row;
}

function getMark($student_id, $subject_id, $type) {
    global $conn, $exam_id;
    $field = $type . "_marks";
    $q = mysqli_query($conn, "SELECT $field FROM marks WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'");
    $f = mysqli_fetch_assoc($q);
    return $f[$field] ?? 0;
}

function subjectGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Academic Transcript</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f8f9fa; }
        .transcript { max-width: 900px; margin: auto; background: #fff; padding: 30px; border: 1px solid #ccc; }
        .logo { width: 80px; height: 80px; object-fit: contain; }
        .table th, .table td { vertical-align: middle; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
<div class="transcript shadow">
    <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4">
        <div><img src="../assets/logo.png" class="logo" alt="Logo"></div>
        <div class="text-center w-100">
            <h4 class="mb-0">নতুন উচ্চ বিদ্যালয়</h4>
            <small>ধানমন্ডি, ঢাকা-১২০৫</small>
            <h5 class="mt-2">Academic Transcript</h5>
            <small>Exam: <?= $exam['exam_name'] ?> | Year: <?= $year ?></small>
        </div>
        <div style="width:80px;"></div>
    </div>

    <div class="mb-3">
        <strong>Student ID:</strong> <?= $student['student_id'] ?><br>
        <strong>Name:</strong> <?= $student['student_name'] ?><br>
        <strong>Class:</strong> <?= $class_id ?>, <strong>Roll:</strong> <?= $student['roll_no'] ?>
    </div>

    <table class="table table-bordered">
        <thead class="table-light text-center">
            <tr>
                <th>Subject</th>
                <th>C</th>
                <th>O</th>
                <th>P</th>
                <th>Total</th>
                <th>GPA</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $gpa_total = 0;
        $subject_count = 0;
        $fail_count = 0;
        foreach ($subjects as $sub):
            $sid = $sub['id'];
            $c = $sub['has_creative'] ? getMark($student_id, $sid, 'creative') : 0;
            $o = $sub['has_objective'] ? getMark($student_id, $sid, 'objective') : 0;
            $p = $sub['has_practical'] ? getMark($student_id, $sid, 'practical') : 0;
            $total = $c + $o + $p;

            $fail = false;
            if ($sub['has_creative'] && $c < $sub['creative_pass']) $fail = true;
            if ($sub['has_objective'] && $o < $sub['objective_pass']) $fail = true;
            if ($sub['has_practical'] && $p < $sub['practical_pass']) $fail = true;
            if ($fail) $fail_count++;

            $gpa = subjectGPA($total);
            $gpa_total += $gpa;
            $subject_count++;
        ?>
            <tr class="text-center">
                <td><?= $sub['subject_name'] ?></td>
                <td><?= $c ?></td>
                <td><?= $o ?></td>
                <td><?= $p ?></td>
                <td class="<?= $fail ? 'text-danger fw-bold' : '' ?>"><?= $total ?></td>
                <td><?= number_format($gpa, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="text-end fw-bold">
                <td colspan="4" class="text-center">Final GPA</td>
                <td colspan="2" class="text-center"><?= $fail_count > 0 ? '0.00' : number_format($gpa_total / $subject_count, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <div class="mt-4 d-flex justify-content-between">
        <div>
            <p><strong>Remarks:</strong> <?= $fail_count > 0 ? 'Failed' : 'Passed' ?></p>
        </div>
        <div class="text-end">
            <p>_______________________<br><strong>Head Teacher</strong></p>
        </div>
    </div>

    <div class="text-center mt-4 no-print">
        <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print"></i> Print Transcript</button>
    </div>
</div>
</body>
</html>
