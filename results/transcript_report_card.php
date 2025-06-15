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

// Define subject merges
$merged_subjects = [
    'Bangla' => [101, 102],
    'English' => [107, 108]
];

function subjectGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}

function getMark($student_id, $subject_id, $type, $exam_id) {
    global $conn;
    $field = $type . "_marks";
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT $field FROM marks WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'"));
    return $res[$field] ?? 0;
}

// Get all subjects for this class and exam
$subject_q = mysqli_query($conn, "
    SELECT s.id, s.subject_code, s.subject_name, s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subject_q)) {
    $subjects[$row['subject_code']] = $row;
}

// Result compilation
$total_marks = 0;
$total_gpa = 0;
$fail_count = 0;
$subject_count = 0;

$rows = [];

foreach ($merged_subjects as $group_name => $codes) {
    $c = $o = $p = 0;
    $fail = false;
    foreach ($codes as $code) {
        $sub = $subjects[$code];
        $cid = $sub['id'];
        if ($sub['has_creative']) $c += getMark($student_id, $cid, 'creative', $exam_id);
        if ($sub['has_objective']) $o += getMark($student_id, $cid, 'objective', $exam_id);
        if ($sub['has_practical']) $p += getMark($student_id, $cid, 'practical', $exam_id);
        if ($sub['has_creative'] && $c < $sub['creative_pass']) $fail = true;
        if ($sub['has_objective'] && $o < $sub['objective_pass']) $fail = true;
        if ($sub['has_practical'] && $p < $sub['practical_pass']) $fail = true;
    }
    $total = $c + $o + $p;
    $gpa = subjectGPA($total);
    if ($fail) $fail_count++;
    $total_marks += $total;
    $total_gpa += $gpa;
    $subject_count++;
    $rows[] = ["code" => implode("+", $codes), "name" => $group_name, "c" => $c, "o" => $o, "p" => $p, "total" => $total, "gpa" => $gpa, "fail" => $fail];
}

// Now add remaining subjects not in merged
$merged_codes = array_merge(...array_values($merged_subjects));

foreach ($subjects as $code => $sub) {
    if (in_array($code, $merged_codes)) continue;
    $cid = $sub['id'];
    $c = $sub['has_creative'] ? getMark($student_id, $cid, 'creative', $exam_id) : 0;
    $o = $sub['has_objective'] ? getMark($student_id, $cid, 'objective', $exam_id) : 0;
    $p = $sub['has_practical'] ? getMark($student_id, $cid, 'practical', $exam_id) : 0;
    $total = $c + $o + $p;
    $fail = false;
    if ($sub['has_creative'] && $c < $sub['creative_pass']) $fail = true;
    if ($sub['has_objective'] && $o < $sub['objective_pass']) $fail = true;
    if ($sub['has_practical'] && $p < $sub['practical_pass']) $fail = true;
    $gpa = subjectGPA($total);
    if ($fail) $fail_count++;
    $total_marks += $total;
    $total_gpa += $gpa;
    $subject_count++;
    $rows[] = ["code" => $code, "name" => $sub['subject_name'], "c" => $c, "o" => $o, "p" => $p, "total" => $total, "gpa" => $gpa, "fail" => $fail];
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
        .text-success { color: green; font-weight: bold; }
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
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= $r['code'] ?></td>
                <td class="text-start"><?= $r['name'] ?></td>
                <td><?= $r['c'] ?></td>
                <td><?= $r['o'] ?></td>
                <td><?= $r['p'] ?></td>
                <td class="<?= $r['fail'] ? 'text-danger' : '' ?>"><?= $r['total'] ?></td>
                <td><?= number_format($r['gpa'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="fw-bold">
            <td colspan="5" class="text-end">Total</td>
            <td><?= $total_marks ?></td>
            <td><?= $fail_count > 0 ? '0.00' : number_format($total_gpa / $subject_count, 2) ?></td>
        </tr>
        </tbody>
    </table>

    <p class="mt-3"><strong>Status:</strong> <?= $fail_count > 0 ? '<span class="text-danger">Failed</span>' : '<span class="text-success">Passed</span>' ?></p>
</body>
</html>
