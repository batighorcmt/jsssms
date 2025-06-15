<?php
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (!$student_id || !$exam_id) {
    echo "<div class='alert alert-danger'>Invalid Request</div>";
    exit;
}

$student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM students WHERE student_id='$student_id'"));
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='$exam_id'"));

$class_id = $student['class_id'];
$year = $student['year'];

$subject_q = mysqli_query($conn, "
    SELECT s.id, s.subject_name, s.subject_code, s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass
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
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT $field FROM marks WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'"));
    return $res[$field] ?? 0;
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
<html>
<head>
    <meta charset="UTF-8">
    <title>Transcript</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: sans-serif; background: #fff; padding: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        table, th, td { border: 1px solid #000; }
        th, td { text-align: center; padding: 5px; }
        .text-start { text-align: left; }
        .fw-bold { font-weight: bold; }
        .text-danger { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h4 class="text-center">Academic Record</h4>
    <table>
        <thead>
            <tr>
                <th>Subject Code</th>
                <th class="text-start">Subject Name</th>
                <th>C</th>
                <th>O</th>
                <th>P</th>
                <th>Total</th>
                <th>GPA</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $totalMarks = 0;
        $gpaTotal = 0;
        $failCount = 0;
        $subjectCount = 0;

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
            if ($fail) $failCount++;

            $gpa = subjectGPA($total);
            $totalMarks += $total;
            $gpaTotal += $gpa;
            $subjectCount++;
        ?>
            <tr>
                <td><?= $sub['subject_code'] ?></td>
                <td class="text-start"><?= $sub['subject_name'] ?></td>
                <td><?= $c ?></td>
                <td><?= $o ?></td>
                <td><?= $p ?></td>
                <td class="<?= $fail ? 'text-danger' : '' ?>"><?= $total ?></td>
                <td><?= number_format($gpa, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="fw-bold">
            <td colspan="5" class="text-end">Total</td>
            <td><?= $totalMarks ?></td>
            <td><?= $failCount > 0 ? '0.00' : number_format($gpaTotal / $subjectCount, 2) ?></td>
        </tr>
        </tbody>
    </table>

    <p class="mt-3"><strong>Status:</strong> <?= $failCount > 0 ? '<span class="text-danger">Failed</span>' : '<span class="text-success">Passed</span>' ?></p>
</body>
</html>
