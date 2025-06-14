<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// প্রতিষ্ঠানের তথ্য
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";

// পরীক্ষার নাম
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

// === Custom Subject Merging ===
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

// Fetch marks for merged subjects
$merged_marks = [];

foreach ($merged_subjects as $group_name => $sub_codes) { // ✅ প্লেসহোল্ডার গুলো সঠিকভাবে বানান (without quotes) $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));
 `$sql = "SELECT m.student_id, m.subject_id, s.subject_code,                   m.creative_marks, m.objective_marks, m.practical_marks           FROM marks m           JOIN subjects s ON m.subject_id = s.id           JOIN students stu ON m.student_id = stu.student_id           WHERE m.exam_id = ?           AND stu.class_id = ?           AND stu.year = ?           AND s.subject_code IN ($placeholders)";    $stmt = $conn->prepare($sql);    // ✅ সকল bind_param value একত্রিত করুন   $types = 'sss' . str_repeat('s', count($sub_codes));   $params = array_merge([$exam_id, $class_id, $year], $sub_codes);    // ✅ bind_param() call with unpacked reference   $stmt->bind_param($types, ...$params);    $stmt->execute();   $res = $stmt->get_result();    while ($row = $res->fetch_assoc()) {       $sid = $row['student_id'];       if (!isset($merged_marks[$sid][$group_name])) {           $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];       }       $merged_marks[$sid][$group_name]['creative'] += $row['creative_marks'];       $merged_marks[$sid][$group_name]['objective'] += $row['objective_marks'];       $merged_marks[$sid][$group_name]['practical'] += $row['practical_marks'];   }   ` 
}

    // ✅ bind_param কল
    call_user_func_array([$stmt, 'bind_param'], $bind_names);

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

    $stmt->close();
    unset($bind_names); // prevent next loop from reusing
}

// Fetch subjects with total marks
$subjects_q = mysqli_query($conn, "
    SELECT s.id as subject_id, s.subject_name, s.subject_code, 
           s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass,
           es.total_marks as max_marks
    FROM exam_subjects es 
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $subjects[] = $row;
}

// Prepare display subjects (merged and regular)
$display_subjects = [];
$merged_pass_marks = [];
$merged_added = [];

foreach ($merged_subjects as $group_name => $sub_codes) {
    $creative_pass = 0;
    $objective_pass = 0;
    $practical_pass = 0;
    $max_marks = 0;
    
    foreach ($subjects as $sub) {
        if (in_array($sub['subject_code'], $sub_codes)) {
            $creative_pass += $sub['creative_pass'] ?? 0;
            $objective_pass += $sub['objective_pass'] ?? 0;
            $practical_pass += $sub['practical_pass'] ?? 0;
            $max_marks += $sub['max_marks'] ?? 0;
        }
    }
    
    $merged_pass_marks[$group_name] = [
        'creative_pass' => $creative_pass,
        'objective_pass' => $objective_pass,
        'practical_pass' => $practical_pass,
        'max_marks' => $max_marks
    ];
}

foreach ($subjects as $sub) {
    $is_merged = false;
    foreach ($merged_subjects as $group_name => $sub_codes) {
        if (in_array($sub['subject_code'], $sub_codes)) {
            $is_merged = true;
            if (!in_array($group_name, $merged_added)) {
                $display_subjects[] = [
                    'subject_name' => $group_name,
                    'subject_code' => $group_name,
                    'has_creative' => 1,
                    'has_objective' => 1,
                    'has_practical' => 0,
                    'creative_pass' => $merged_pass_marks[$group_name]['creative_pass'],
                    'objective_pass' => $merged_pass_marks[$group_name]['objective_pass'],
                    'practical_pass' => $merged_pass_marks[$group_name]['practical_pass'],
                    'max_marks' => $merged_pass_marks[$group_name]['max_marks'],
                    'is_merged' => true
                ];
                $merged_added[] = $group_name;
            }
            break;
        }
    }
    
    if (!$is_merged) {
        $display_subjects[] = array_merge($sub, ['is_merged' => false]);
    }
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
    return ($mark < $pass_mark && $pass_mark > 0);
}

function subjectGPA($total, $max) {
    if ($max == 0) return 0.00;
    $percentage = ($total / $max) * 100;
    if ($percentage >= 80) return 5.00;
    elseif ($percentage >= 70) return 4.00;
    elseif ($percentage >= 60) return 3.50;
    elseif ($percentage >= 50) return 3.00;
    elseif ($percentage >= 40) return 2.00;
    elseif ($percentage >= 33) return 1.00;
    return 0.00;
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        th, td { vertical-align: middle !important; font-size: 13px; border-spacing: 0px; padding: 2px; }
        @media print {
            .no-print { display: none; }
            table { font-size: 11px; border-spacing: 0px; padding: 2px; }
        }
        .fail-mark { color: red; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .text-center { text-align: center; }
        .table-bordered th, .table-bordered td { border: 1px solid #dee2e6; }
        .table-primary { background-color: #cfe2ff; }
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
                <th rowspan="2">#</th>
                <th rowspan="2">Student Name</th>
                <th rowspan="2">Roll</th>
                <?php foreach ($display_subjects as $sub): 
                    $colspan = $sub['has_creative'] + $sub['has_objective'] + $sub['has_practical'] + 2;
                ?>
                    <th colspan="<?= $colspan ?>">
                        <?= $sub['subject_name'] ?><br>
                        <small>(<?= $sub['subject_code'] ?>)</small>
                    </th>
                <?php endforeach; ?>
                <th rowspan="2">Total</th>
                <th rowspan="2">GPA</th>
                <th rowspan="2">Fail</th>
            </tr>
            <tr>
                <?php foreach ($display_subjects as $sub): ?>
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
                $total_marks = 0;
                $fail_count = 0;
                $gpa_total = 0;
                $gpa_subjects = 0;
                $row_data = "";
                
                foreach ($display_subjects as $sub) {
                    if ($sub['is_merged']) {
                        // Handle merged subjects
                        $group = $sub['subject_name'];
                        $c = $merged_marks[$stu['student_id']][$group]['creative'] ?? 0;
                        $o = $merged_marks[$stu['student_id']][$group]['objective'] ?? 0;
                        $p = $merged_marks[$stu['student_id']][$group]['practical'] ?? 0;
                        $sub_total = $c + $o + $p;
                        
                        $fail = false;
                        if ($sub['has_creative'] && isFail($c, $sub['creative_pass'])) $fail = true;
                        if ($sub['has_objective'] && isFail($o, $sub['objective_pass'])) $fail = true;
                        if ($sub['has_practical'] && isFail($p, $sub['practical_pass'])) $fail = true;
                        
                        if ($fail) $fail_count++;
                        
                        $gpa = subjectGPA($sub_total, $sub['max_marks']);
                        $gpa_total += $gpa;
                        $gpa_subjects++;
                        
                        $row_data .= "<td>" . (isFail($c, $sub['creative_pass']) ? "<span class='fail-mark'>$c</span>" : $c) . "</td>";
                        $row_data .= "<td>" . (isFail($o, $sub['objective_pass']) ? "<span class='fail-mark'>$o</span>" : $o) . "</td>";
                        $row_data .= "<td>$sub_total</td>";
                        $row_data .= "<td>" . number_format($gpa, 2) . "</td>";
                        
                        $total_marks += $sub_total;
                    } else {
                        // Handle regular subjects
                        $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'creative') : 0;
                        $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'objective') : 0;
                        $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'practical') : 0;
                        $sub_total = $c + $o + $p;
                        
                        $fail = false;
                        if ($sub['has_creative'] && isFail($c, $sub['creative_pass'])) $fail = true;
                        if ($sub['has_objective'] && isFail($o, $sub['objective_pass'])) $fail = true;
                        if ($sub['has_practical'] && isFail($p, $sub['practical_pass'])) $fail = true;
                        
                        if ($fail) $fail_count++;
                        
                        $gpa = subjectGPA($sub_total, $sub['max_marks']);
                        $gpa_total += $gpa;
                        $gpa_subjects++;
                        
                        if ($sub['has_creative']) $row_data .= "<td>" . (isFail($c, $sub['creative_pass']) ? "<span class='fail-mark'>$c</span>" : $c) . "</td>";
                        if ($sub['has_objective']) $row_data .= "<td>" . (isFail($o, $sub['objective_pass']) ? "<span class='fail-mark'>$o</span>" : $o) . "</td>";
                        if ($sub['has_practical']) $row_data .= "<td>" . (isFail($p, $sub['practical_pass']) ? "<span class='fail-mark'>$p</span>" : $p) . "</td>";
                        
                        $row_data .= "<td>$sub_total</td>";
                        $row_data .= "<td>" . number_format($gpa, 2) . "</td>";
                        
                        $total_marks += $sub_total;
                    }
                }
                
                $final_gpa = ($gpa_subjects > 0) ? $gpa_total / $gpa_subjects : 0;
                $final_gpa = $fail_count > 0 ? 0.00 : number_format($final_gpa, 2);
                $fail_display = $fail_count > 0 ? $fail_count : "";
                
                $all_students[] = [
                    'fail' => $fail_count,
                    'total' => $total_marks,
                    'row' => "<tr><td></td><td>{$stu['student_name']}</td><td>{$stu['roll_no']}</td>$row_data<td>$total_marks</td><td>$final_gpa</td><td>$fail_display</td></tr>"
                ];
            }
            
            // Sort students by total marks (descending)
            usort($all_students, function($a, $b) {
                return $b['total'] <=> $a['total'];
            });
            
            // Output sorted students
            foreach ($all_students as $i => $stu) {
                echo preg_replace('/<td><\/td>/', "<td>" . ($i + 1) . "</td>", $stu['row'], 1);
            }
            ?>
        </tbody>
    </table>
</div>
</body>
</html>