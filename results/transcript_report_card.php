<?php
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (!$student_id || !$exam_id) {
    echo "<div class='alert alert-danger'>Invalid Request</div>";
    exit;
}

// Student and Exam Info
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

// Load subjects
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

// Bangla & English merging
$merged_subjects = [
    'Bangla' => [101, 102],
    'English' => [107, 108]
];

$rows = ['Compulsory' => [], 'Optional' => []];
$total_gpa = 0;
$gpa_count = 0;
$fail = false;

foreach ($merged_subjects as $group_name => $codes) {
    $c = $o = $p = 0;
    $fail_sub = false;

    foreach ($codes as $code) {
        if (!isset($subjects[$code])) continue;
        $sub = $subjects[$code];
        $id = $sub['id'];
        if ($sub['has_creative']) $mark = getMark($student_id, $id, 'creative', $exam_id); else $mark = 0;
        $c += $mark;
        if ($sub['has_objective']) $mark = getMark($student_id, $id, 'objective', $exam_id); else $mark = 0;
        $o += $mark;
        if ($sub['has_practical']) $mark = getMark($student_id, $id, 'practical', $exam_id); else $mark = 0;
        $p += $mark;

        if ($sub['has_creative'] && $mark < $sub['creative_pass']) $fail_sub = true;
        if ($sub['has_objective'] && $mark < $sub['objective_pass']) $fail_sub = true;
        if ($sub['has_practical'] && $mark < $sub['practical_pass']) $fail_sub = true;
    }

    $total = $c + $o + $p;
    list($gpa, $grade) = subjectGPA($total);
    if ($fail_sub) $gpa = 0.00;
    $fail = $fail || $fail_sub;

    $rows['Compulsory'][] = [
        'name' => $group_name,
        'c' => $c, 'o' => $o, 'p' => $p,
        'total' => $total,
        'grade' => $grade,
        'gpa' => $gpa
    ];
    $total_gpa += $gpa;
    $gpa_count++;
}

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
        'name' => $sub['subject_name'],
        'c' => $c, 'o' => $o, 'p' => $p,
        'total' => $total,
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
        body { font-family: serif; padding: 20px; }
        .header { text-align: center; margin-bottom: 10px; }
        .logo { width: 80px; float: left; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 20px; }
        th, td { border: 1px solid #000; padding: 5px; text-align: center; }
        .info-table td { border: none; text-align: left; }
        .print-btn { margin-top: 20px; text-align: center; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>

<div class="header">
    <img src="../assets/logo.png" class="logo" alt="Logo">
    <h2>নতুন উচ্চ বিদ্যালয়</h2>
    <p>ধানমন্ডি, ঢাকা-১২০৫</p>
    <h3>Academic Transcript</h3>
</div>

<table class="info-table">
<tr><td><strong>Name:</strong> <?= $student['student_name'] ?></td><td><strong>Roll:</strong> <?= $student['roll_no'] ?></td></tr>
<tr><td><strong>Student ID:</strong> <?= $student['student_id'] ?></td><td><strong>Year:</strong> <?= $year ?></td></tr>
<tr><td><strong>Class:</strong> <?= $class_id ?></td><td><strong>Exam:</strong> <?= $exam['exam_name'] ?></td></tr>
</table>

<table>
    <thead>
        <tr>
            <th>SL</th>
            <th>Subject</th>
            <th>C</th>
            <th>O</th>
            <th>P</th>
            <th>Total</th>
            <th>Letter</th>
            <th>GPA</th>
        </tr>
    </thead>
    <tbody>
        <?php
        $sl = 1;
        foreach (['Compulsory', 'Optional'] as $type) {
            foreach ($rows[$type] as $r) {
                echo "<tr>
                    <td>{$sl}</td>
                    <td style='text-align:left'>{$r['name']}</td>
                    <td>{$r['c']}</td>
                    <td>{$r['o']}</td>
                    <td>{$r['p']}</td>
                    <td>{$r['total']}</td>
                    <td>{$r['grade']}</td>
                    <td>" . number_format($r['gpa'], 2) . "</td>
                </tr>";
                $sl++;
            }
        }
        ?>
        <tr>
            <td colspan="5" style="text-align:right"><strong>Total GPA</strong></td>
            <td colspan="3"><strong><?= $fail ? '0.00' : number_format($total_gpa / $gpa_count, 2) ?></strong></td>
        </tr>
    </tbody>
</table>

<p style="margin-top:10px;"><strong>Status:</strong> <?= $fail ? '<span style="color:red;">Failed</span>' : '<span style="color:green;">Passed</span>' ?></p>

<div class="print-btn">
    <button onclick="window.print()">🖨️ Print</button>
</div>

</body>
</html>
