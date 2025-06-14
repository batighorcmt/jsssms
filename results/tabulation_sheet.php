
<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// প্রতিষ্ঠানের তথ্য (হার্ডকোড করা হয়েছে, চাইলে ডাটাবেজ থেকে আনা যাবে)
$institute_name = "নতুন উচ্চ বিদ্যালয়";
$institute_address = "ধানমন্ডি, ঢাকা-১২০৫";

// পরীক্ষার নাম
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];


// === Custom Subject Merging ===
// Define merged subjects: subject_code => [subject_ids]
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

// Fetch marks for merged subjects
$merged_marks = [];

foreach ($merged_subjects as $group_name => $sub_ids) {
    $placeholders = implode(',', array_fill(0, count($sub_ids), '?'));
    $types = str_repeat('i', count($sub_ids) + 3); // +3 for exam_id, class_id, year
    $params = array_merge([$exam_id, $class_id, $year], $sub_ids);

    $sql = "SELECT m.student_id, m.subject_id, m.creative_marks, m.objective_marks, m.practical_marks
            FROM marks m
            JOIN students s ON m.student_id = s.student_id
            WHERE m.exam_id = ? AND s.class_id = ? AND s.year = ? AND m.subject_id IN ($placeholders)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid = $row['student_id'];
        if (!isset($merged_marks[$sid][$group_name])) {
            $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $merged_marks[$sid][$group_name]['creative'] += $row['creative_marks'];
        $merged_marks[$sid][$group_name]['objective'] += $row['objective_marks'];
        $merged_marks[$sid][$group_name]['practical'] += $row['practical_marks'];
    }
}

$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

// বিষয় তালিকা
$subjects_q = mysqli_query($conn, "
    SELECT s.id as subject_id, s.subject_name, s.subject_code, 
           s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass
    FROM exam_subjects es 
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $subjects[] = $row;
}

// শিক্ষার্থী তালিকা
$students_q = mysqli_query($conn, "
    SELECT * FROM students 
    WHERE class_id='$class_id' AND year='$year'
    ORDER BY roll_no ASC
");

function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $field = $type . "_marks";
    $res = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT $field FROM marks 
        WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'
    "));
    return $res[$field] ?? 0;
}

function isFail($mark, $pass_mark) {
    return ($mark < $pass_mark || $mark == 0);
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
    <title>টেবুলেশন শীট</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        th, td { vertical-align: middle !important; font-size: 13px border-spacing: 0px;  padding: 0px; }
        @media print {
            .no-print { display: none; }
            table { font-size: 11px border-spacing: 0px;  padding: 0px; }
        }
        .fail-mark { color: red; font-weight: bold; }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="text-center mb-3">
        <h5><?= $institute_name ?></h5>
        <p><?= $institute_address ?></p>
        <h4><?= $exam ?> - <?= $class ?> (<?= $year ?>)</h4>
        <button onclick="window.print()" class="btn btn-primary no-print">প্রিন্ট</button>
    </div>
</div>
    <div class="align-middle">
        <table class="table table-bordered text-center align-middle">
            <thead class="table-primary">
                <tr>
                    <th rowspan="2">ক্রম</th>
                    <th rowspan="2">শিক্ষার্থীর নাম</th>
                    <th rowspan="2">রোল</th>
                    <?php foreach ($subjects as $sub): 
                        $colspan = $sub['has_creative'] + $sub['has_objective'] + $sub['has_practical'] + 2;
                    ?>
                        <th colspan="<?= $colspan ?>">
                            <?= $sub['subject_name'] ?><br>
                            <small>(<?= $sub['subject_code'] ?>)</small>
                        </th>
                    <?php endforeach; ?>
                    <th rowspan="2">মোট</th>
                    <th rowspan="2">GPA</th>
                    <th rowspan="2">ফেল</th>
                </tr>
                <tr>
                    <?php foreach ($subjects as $sub): ?>
                        <?php if ($sub['has_creative']) echo "<th>C</th>"; ?>
                        <?php if ($sub['has_objective']) echo "<th>O</th>"; ?>
                        <?php if ($sub['has_practical']) echo "<th>P</th>"; ?>
                        <th>Total</th>
                        <th>GPA</th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $all_students = [];
                while ($stu = mysqli_fetch_assoc($students_q)) {
                    $total = 0;
                    $fail_count = 0;
                    $gpa_total = 0;
                    $gpa_subjects = 0;
                    $row_data = "";

                    foreach ($subjects as $sub) {
                        $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'creative') : null;
                        $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'objective') : null;
                        $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'practical') : null;

                        $sub_total = ($c ?? 0) + ($o ?? 0) + ($p ?? 0);
                        $fail = false;

                        if ($class_id <= 3) {
                            if ($sub_total < 33 || $c === 0 || $o === 0 || $p === 0) {
                                $fail = true;
                                $fail_count++;
                            }
                        } else {
                            if ($sub['has_creative'] && isFail($c, $sub['creative_pass'])) $fail = true;
                            if ($sub['has_objective'] && isFail($o, $sub['objective_pass'])) $fail = true;
                            if ($sub['has_practical'] && isFail($p, $sub['practical_pass'])) $fail = true;
                            if ($fail) $fail_count++;
                        }

                        $gpa = subjectGPA($sub_total);
                        $gpa_total += $gpa;
                        $gpa_subjects++;

                        if ($sub['has_creative']) $row_data .= "<td>" . (isFail($c, $sub['creative_pass']) ? "<span class='fail-mark'>$c</span>" : $c) . "</td>";
                        if ($sub['has_objective']) $row_data .= "<td>" . (isFail($o, $sub['objective_pass']) ? "<span class='fail-mark'>$o</span>" : $o) . "</td>";
                        if ($sub['has_practical']) $row_data .= "<td>" . (isFail($p, $sub['practical_pass']) ? "<span class='fail-mark'>$p</span>" : $p) . "</td>";

                        $row_data .= "<td>$sub_total</td><td>" . number_format($gpa, 2) . "</td>";
                        $total += $sub_total;
                    }

                    $final_gpa = $fail_count > 0 ? '0.00' : number_format($gpa_total / $gpa_subjects, 2);
                    $fail_display = $fail_count > 0 ? $fail_count : "";

                    $all_students[] = [
                        'fail' => $fail_count,
                        'total' => $total,
                        'row' => "<tr><td></td><td>{$stu['student_name']}</td><td>{$stu['roll_no']}</td>$row_data<td>$total</td><td>$final_gpa</td><td>$fail_display</td></tr>"
                    ];
                }

                foreach ($all_students as $i => $stu) {
                    echo preg_replace('/<td><\/td>/', "<td>" . ($i + 1) . "</td>", $stu['row'], 1);
                }
                ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
