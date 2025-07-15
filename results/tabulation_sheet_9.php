<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger'>পরীক্ষার ID, শ্রেণি ও সাল দিন।</div>";
    exit;
}

$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";

// Fetch class, exam name
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $stmt = $conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id=? AND subject_id=? AND exam_id=?");
    $stmt->bind_param("sss", $student_id, $subject_id, $exam_id);
    $stmt->execute();
    $stmt->bind_result($marks);
    $stmt->fetch();
    return $marks ?? 0;
}

function subjectGPA($total, $max) {
    $percent = ($max > 0) ? ($total / $max) * 100 : 0;
    if ($percent >= 80) return 5.00;
    elseif ($percent >= 70) return 4.00;
    elseif ($percent >= 60) return 3.50;
    elseif ($percent >= 50) return 3.00;
    elseif ($percent >= 40) return 2.00;
    elseif ($percent >= 33) return 1.00;
    else return 0.00;
}

function isFail($marks, $pass) {
    return $marks < $pass || $marks == 0;
}

// Fetch subjects
$subject_q = mysqli_query($conn, "
    SELECT s.*, es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks, es.pass_type, gm.type
    FROM subjects s 
    JOIN exam_subjects es ON es.subject_id = s.id 
    JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = '$class_id'
    WHERE es.exam_id = '$exam_id' 
    ORDER BY FIELD(gm.type, 'Compulsory', 'Optional'), s.subject_code
");
if (!$subject_q) {
    echo "<div class='alert alert-danger'>বিষয়সমূহ লোড করতে সমস্যা হয়েছে।</div>";
    exit;
}
$subjects = [];
while ($sub = mysqli_fetch_assoc($subject_q)) $subjects[] = $sub;

// Fetch students
$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id='$class_id' AND year='$year'");
$students = [];
while ($stu = mysqli_fetch_assoc($students_q)) $students[] = $stu;

// GPA Calculation
$student_data = [];
foreach ($students as $stu) {
    $sid = $stu['student_id'];
    $total_marks = 0;
    $fail_count = 0;
    $gpa_sum = 0;
    $gpa_subs = 0;
    $optional_gpas = [];

    $subject_results = [];
    foreach ($subjects as $sub) {
        $cid = $sub['id'];
        $c = $sub['has_creative'] ? getMarks($sid, $cid, $exam_id, 'creative') : 0;
        $o = $sub['has_objective'] ? getMarks($sid, $cid, $exam_id, 'objective') : 0;
        $p = $sub['has_practical'] ? getMarks($sid, $cid, $exam_id, 'practical') : 0;
        $t = $c + $o + $p;
        $is_fail = false;

        if ($sub['pass_type'] == 'individual') {
            if (isFail($c, $sub['creative_pass']) || isFail($o, $sub['objective_pass']) || isFail($p, $sub['practical_pass'])) {
                $is_fail = true;
            }
        } else {
            $required = $sub['creative_pass'] + $sub['objective_pass'] + $sub['practical_pass'];
            if ($t < $required) $is_fail = true;
        }

        $gpa = subjectGPA($t, $sub['total_marks']);
        if ($sub['type'] == 'Optional') $optional_gpas[] = $gpa;
        else {
            $gpa_sum += $gpa;
            $gpa_subs++;
        }

        if ($is_fail) $fail_count++;

        $total_marks += $t;

        $subject_results[] = [
            'name' => $sub['subject_name'],
            'creative' => $c,
            'objective' => $o,
            'practical' => $p,
            'total' => $t,
            'gpa' => number_format($gpa, 2),
            'is_fail' => $is_fail
        ];
    }

    $bonus = 0;
    if (!empty($optional_gpas)) {
        $max_optional = max($optional_gpas);
        if ($max_optional > 2.00) {
            $bonus = $max_optional - 2.00;
        }
    }

    $final_gpa = $gpa_subs ? ($gpa_sum + $bonus) / $gpa_subs : 0;
    if ($fail_count > 0) $final_gpa = 0.00;

    $student_data[] = [
        'student' => $stu,
        'subjects' => $subject_results,
        'total' => $total_marks,
        'fail' => $fail_count,
        'gpa' => number_format(min($final_gpa, 5.00), 2)
    ];
}

// Merit calculation
usort($student_data, function($a, $b) {
    return $a['fail'] <=> $b['fail'] ?: $b['total'] <=> $a['total'];
});
foreach ($student_data as $i => &$stu) $stu['merit'] = $i + 1;
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Individual Tabulation Sheet</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        .fail { color: red; font-weight: bold; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body class="bg-light">
<div class="container mt-3">
    <div class="text-center mb-4">
        <h5><?= $institute_name ?></h5>
        <p><?= $institute_address ?></p>
        <h6><?= $exam ?> - <?= $class ?> (<?= $year ?>)</h6>
        <button class="btn btn-primary no-print" onclick="window.print()">প্রিন্ট করুন</button>
    </div>

    <?php foreach ($student_data as $i => $s): ?>
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <?= ($i+1) ?>. <?= $s['student']['student_name'] ?> | রোল: <?= $s['student']['roll_no'] ?> | বিভাগ: <?= $s['student']['student_group'] ?> | Merit: <?= $s['merit'] ?>
            </div>
            <div class="card-body p-2">
                <table class="table table-bordered table-sm text-center">
                    <thead class="table-light">
                        <tr>
                            <th>বিষয়</th>
                            <th>সৃজনশীল</th>
                            <th>বহুনির্বাচনী</th>
                            <th>ব্যবহারিক</th>
                            <th>মোট</th>
                            <th>GPA</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($s['subjects'] as $sub): ?>
                        <tr>
                            <td><?= $sub['name'] ?></td>
                            <td><?= $sub['creative'] ?></td>
                            <td><?= $sub['objective'] ?></td>
                            <td><?= $sub['practical'] ?></td>
                            <td><?= $sub['total'] ?></td>
                            <td><?= $sub['gpa'] ?></td>
                            <td><?= $sub['is_fail'] ? '<span class="fail">F</span>' : 'P' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="row text-center fw-bold">
                    <div class="col-md-4">মোট নম্বর: <?= $s['total'] ?></div>
                    <div class="col-md-4">ফাইনাল GPA: <?= $s['gpa'] ?></div>
                    <div class="col-md-4">ফেল বিষয়: <?= $s['fail'] ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
