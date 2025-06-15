<?php
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (!$student_id || !$exam_id) {
    echo "<div class='alert alert-danger'>Invalid Request</div>";
    exit;
}

// Student Info
$student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM students WHERE student_id='$student_id'"));
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='$exam_id'"));

$class_id = $student['class_id'];
$year = $student['year'];

function subjectGPA($total) {
    if ($total >= 80) return [5.00, 'A+'];
    elseif ($total >= 70) return [4.00, 'A'];
    elseif ($total >= 60) return [3.50, 'A-'];
    elseif ($total >= 50) return [3.00, 'B'];
    elseif ($total >= 40) return [2.00, 'C'];
    elseif ($total >= 33) return [1.00, 'D'];
    return [0.00, 'F'];
}

function getMark($student_id, $subject_id, $type, $exam_id) {
    global $conn;
    $field = $type . "_marks";
    $res = mysqli_fetch_assoc(mysqli_query($conn, "SELECT $field FROM marks WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'"));
    return $res[$field] ?? 0;
}

$subject_q = mysqli_query($conn, "
    SELECT s.id, s.subject_code, s.subject_name, s.type, s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subject_q)) {
    $subjects[$row['subject_code']] = $row;
}

$merged_subjects = [
    'Bangla' => [101, 102],
    'English' => [107, 108]
];

$rows = ['Compulsory' => [], 'Optional' => []];
$total_gpa = 0;
$gpa_count = 0;
$fail = false;

foreach ($merged_subjects as $name => $codes) {
    $total = $c = $o = $p = 0;
    $fail_sub = false;

    foreach ($codes as $code) {
        $sub = $subjects[$code];
        $id = $sub['id'];
        if ($sub['has_creative']) $c += getMark($student_id, $id, 'creative', $exam_id);
        if ($sub['has_objective']) $o += getMark($student_id, $id, 'objective', $exam_id);
        if ($sub['has_practical']) $p += getMark($student_id, $id, 'practical', $exam_id);
        if ($sub['has_creative'] && $c < $sub['creative_pass']) $fail_sub = true;
        if ($sub['has_objective'] && $o < $sub['objective_pass']) $fail_sub = true;
        if ($sub['has_practical'] && $p < $sub['practical_pass']) $fail_sub = true;
    }

    $total = $c + $o + $p;
    list($gpa, $grade) = subjectGPA($total);
    if ($fail_sub) $gpa = 0.00;
    $fail = $fail || $fail_sub;

    $rows['Compulsory'][] = [
        'code' => implode('+', $codes),
        'name' => $name,
        'marks' => $total,
        'grade' => $grade,
        'gpa' => $gpa
    ];

    $total_gpa += $gpa;
    $gpa_count++;
}

// Add other subjects
$merged_codes = array_merge(...array_values($merged_subjects));
foreach ($subjects as $code => $sub) {
    if (in_array($code, $merged_codes)) continue;
    $id = $sub['id'];
    $c = $sub['has_creative'] ? getMark($student_id, $id, 'creative', $exam_id) : 0;
    $o = $sub['has_objective'] ? getMark($student_id, $id, 'objective', $exam_id) : 0;
    $p = $sub['has_practical'] ? getMark($student_id, $id, 'practical', $exam_id) : 0;
    $total = $c + $o + $p;

    $fail_sub = false;
    if ($sub['has_creative'] && $c < $sub['creative_pass']) $fail_sub = true;
    if ($sub['has_objective'] && $o < $sub['objective_pass']) $fail_sub = true;
    if ($sub['has_practical'] && $p < $sub['practical_pass']) $fail_sub = true;

    list($gpa, $grade) = subjectGPA($total);
    if ($fail_sub) $gpa = 0.00;
    $fail = $fail || $fail_sub;

    $type = $sub['type'] === 'Optional' ? 'Optional' : 'Compulsory';
    $rows[$type][] = [
        'code' => $code,
        'name' => $sub['subject_name'],
        'marks' => $total,
        'grade' => $grade,
        'gpa' => $gpa
    ];

    $total_gpa += $gpa;
    $gpa_count++;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Academic Transcript</title>
    <style>
        body { font-family: serif; padding: 20px; background: #fdfdfd; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table, th, td { border: 1px solid #000; }
        th, td { padding: 4px; text-align: center; font-size: 14px; }
        .title { text-align: center; font-weight: bold; font-size: 20px; margin-top: 20px; }
    </style>
</head>
<body>

<h3 class="title">ACADEMIC TRANSCRIPT</h3>

<p><strong>Name:</strong> <?= $student['student_name'] ?> &nbsp; <strong>Roll:</strong> <?= $student['roll_no'] ?> &nbsp; <strong>Year:</strong> <?= $year ?></p>

<table>
    <thead>
        <tr>
            <th>Sl. No.</th>
            <th>Name of Subjects</th>
            <th>Marks Obtained</th>
            <th>Letter Grade</th>
            <th>Grade Point</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $sl = 1;
        foreach (['Compulsory', 'Optional'] as $type) {
            foreach ($rows[$type] as $r):
        ?>
        <tr>
            <td><?= $sl++ ?></td>
            <td style="text-align: left;"><?= $r['name'] ?></td>
            <td><?= $r['marks'] ?></td>
            <td><?= $r['grade'] ?></td>
            <td><?= number_format($r['gpa'], 2) ?></td>
        </tr>
        <?php endforeach; } ?>
        <tr>
            <td colspan="4" style="text-align:right;"><strong>GPA</strong></td>
            <td><strong><?= $fail ? "0.00" : number_format($total_gpa / $gpa_count, 2) ?></strong></td>
        </tr>
    </tbody>
</table>

<p><strong>Status:</strong> <?= $fail ? "Failed" : "Passed" ?></p>

</body>
</html>
