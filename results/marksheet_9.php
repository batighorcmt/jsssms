<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';
$search_roll = $_GET['search_roll'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";

$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

// ✅ মার্ক রিটার্ন ফাংশন
function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $stmt = $conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
    $stmt->bind_param("sss", $student_id, $subject_id, $exam_id);
    $stmt->execute();
    $stmt->bind_result($marks);
    if ($stmt->fetch()) {
        return floatval($marks);
    }
    return 0;
}

function isFail($marks, $pass_mark) {
    return $marks < $pass_mark || $marks == 0;
}

function subjectGPA($marks, $full_marks) {
    $percentage = ($full_marks > 0) ? ($marks / $full_marks) * 100 : 0;

    if ($percentage >= 80) return 5.00;
    elseif ($percentage >= 70) return 4.00;
    elseif ($percentage >= 60) return 3.50;
    elseif ($percentage >= 50) return 3.00;
    elseif ($percentage >= 40) return 2.00;
    elseif ($percentage >= 33) return 1.00;
    else return 0.00;
}

function getPassStatus($class_id, $marks, $pass_marks, $pass_type = 'total') {
    $total = ($marks['creative'] ?? 0) + ($marks['objective'] ?? 0) + ($marks['practical'] ?? 0);
    if ($pass_type === 'total') {
        return $total >= 33;
    } else {
        if (isset($pass_marks['creative']) && ($marks['creative'] < $pass_marks['creative'] || $marks['creative'] == 0)) return false;
        if (isset($pass_marks['objective']) && ($marks['objective'] < $pass_marks['objective'] || $marks['objective'] == 0)) return false;
        if (isset($pass_marks['practical']) && $pass_marks['practical'] > 0 && ($marks['practical'] < $pass_marks['practical'] || $marks['practical'] == 0)) return false;
        return true;
    }
}

// === Merge Configuration ===
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

$excluded_subject_codes = ['101', '102', '107', '108'];
$insert_after_codes = ['Bangla' => '102', 'English' => '108'];

// Fetch students
$query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year";
if (!empty($search_roll)) {
    $query .= " AND roll_no = '$search_roll'";
}
$students_q = mysqli_query($conn, $query);
$students = [];
while ($stu = mysqli_fetch_assoc($students_q)) {
    $students[] = $stu;
}

// Fetch subjects with pass marks and group info
$subject_q = mysqli_query($conn, "
    SELECT s.*, es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks, es.pass_type, 
           IFNULL(gm.group_name, '') AS group_name,
           s.has_creative, s.has_objective, s.has_practical
    FROM subjects s
    JOIN exam_subjects es ON es.subject_id = s.id AND es.exam_id = '$exam_id'
    LEFT JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = '$class_id'
    WHERE es.exam_id = '$exam_id' AND (gm.class_id = '$class_id' OR gm.class_id IS NULL)
    ORDER BY s.subject_code
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subject_q)) {
    $subjects[] = $row;
}

$all_students = [];
foreach ($students as $stu) {
    $student_id = $stu['student_id'];
    $group = strtolower(trim($stu['student_group'])); // শিক্ষার্থীর গ্রুপ (lowercase)
    
    $compulsory_subjects = [];
    $optional_subjects = [];
    $added_merged = [];
    
    $total_marks = 0;
    $fail_count = 0;
    $compulsory_gpa = 0;
    $compulsory_count = 0;
    $optional_gpas = [];

    foreach ($subjects as $sub) {
        $code = $sub['subject_code'];
        $subject_group_name = strtolower(trim($sub['group_name']));

        // গ্রুপ মিল না করলে সাধারণ বিষয় (empty/null/none) বাদ দিও না
        if ($subject_group_name !== $group && !in_array($subject_group_name, ['', 'none', 'null'], true)) {
            continue;
        }

        $merged_handled = false;

        // merged_subjects যোগ করো
        foreach ($merged_subjects as $label => $codes) {
            if (in_array($code, $codes) && $code === $insert_after_codes[$label] && !in_array($label, $added_merged)) {
                // merged_subjects হিসাব
                $c = 0; $o = 0; $p = 0; $max_marks = 0;
                $cp = 0; $op = 0; $pp = 0;

                foreach ($subjects as $sub2) {
                    $sub2_group_name = strtolower(trim($sub2['group_name']));
                    if (($sub2_group_name === $group || in_array($sub2_group_name, ['', 'none', 'null'], true)) && in_array($sub2['subject_code'], $codes)) {
                        $c += $sub2['has_creative'] ? getMarks($student_id, $sub2['id'], $exam_id, 'creative') : 0;
                        $o += $sub2['has_objective'] ? getMarks($student_id, $sub2['id'], $exam_id, 'objective') : 0;
                        $p += $sub2['has_practical'] ? getMarks($student_id, $sub2['id'], $exam_id, 'practical') : 0;

                        $cp += $sub2['creative_pass'];
                        $op += $sub2['objective_pass'];
                        $pp += $sub2['practical_pass'];

                        $max_marks += $sub2['total_marks'];
                    }
                }

                $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
                $pass_marks = ['creative' => $cp, 'objective' => $op, 'practical' => $pp];
                $is_pass = getPassStatus($class_id, $marks_arr, $pass_marks, 'individual');
                $sub_total = $c + $o + $p;
                $gpa = subjectGPA($sub_total, $max_marks);

                if (!$is_pass) $fail_count++;
                $total_marks += $sub_total;

                $subject_data = [
                    'subject_name' => $label,
                    'creative' => $c,
                    'objective' => $o,
                    'practical' => $p,
                    'total' => $sub_total,
                    'gpa' => $gpa,
                    'is_fail' => !$is_pass
                ];

                if (strtolower($sub['subject_type']) === 'optional') {
                    $optional_subjects[] = $subject_data;
                    $optional_gpas[] = $gpa;
                } else {
                    $compulsory_subjects[] = $subject_data;
                    $compulsory_gpa += $gpa;
                    $compulsory_count++;
                }

                $added_merged[] = $label;
                $merged_handled = true;
                break; // merged_subjects যোগ হয়ে গেছে, আর এপিসোডের লুপ থেকে বের হও
            }
        }

        if ($merged_handled) continue; // merged_subjects যোগ হয়ে গেলে আর নিচের অংশ চালাও না

        // merged_subjects এর কোডগুলো বাদ দাও
        if (in_array($code, $excluded_subject_codes)) continue;

        // সাধারণ সাবজেক্ট মার্ক
        $c = $sub['has_creative'] ? getMarks($student_id, $sub['id'], $exam_id, 'creative') : 0;
        $o = $sub['has_objective'] ? getMarks($student_id, $sub['id'], $exam_id, 'objective') : 0;
        $p = $sub['has_practical'] ? getMarks($student_id, $sub['id'], $exam_id, 'practical') : 0;

        $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
        $pass_marks = [
            'creative' => $sub['creative_pass'],
            'objective' => $sub['objective_pass'],
            'practical' => $sub['practical_pass']
        ];

        $sub_total = $c + $o + $p;
        $is_pass = getPassStatus($class_id, $marks_arr, $pass_marks, $sub['pass_type']);
        $gpa = subjectGPA($sub_total, $sub['total_marks']);

        if (!$is_pass) $fail_count++;
        $total_marks += $sub_total;

        $subject_data = [
            'subject_name' => $sub['subject_name'],
            'creative' => $c,
            'objective' => $o,
            'practical' => $p,
            'total' => $sub_total,
            'gpa' => $gpa,
            'is_fail' => !$is_pass
        ];

        if (strtolower($sub['subject_type']) === 'optional') {
            $optional_subjects[] = $subject_data;
            $optional_gpas[] = $gpa;
        } else {
            $compulsory_subjects[] = $subject_data;
            $compulsory_gpa += $gpa;
            $compulsory_count++;
        }
    }

    // Optional এর মধ্যে থেকে বোনাস জিপিএ
    $bonus = (!empty($optional_gpas) && max($optional_gpas) > 2.00) ? max($optional_gpas) - 2.00 : 0;
    $final_gpa = $compulsory_count ? ($compulsory_gpa + $bonus) / $compulsory_count : 0;
    if ($fail_count > 0) $final_gpa = 0.00;

    // বাধ্যতামুল আগে, অপশনাল পরে
    $student_subjects = array_merge($compulsory_subjects, $optional_subjects);

    $all_students[] = [
        'id' => $student_id,
        'name' => $stu['student_name'],
        'roll' => $stu['roll_no'],
        'subjects' => $student_subjects,
        'total_marks' => $total_marks,
        'fail_count' => $fail_count,
        'gpa' => number_format(min($final_gpa, 5.00), 2)
    ];
}

// মেরিট অনুযায়ী সাজানো
usort($all_students, function ($a, $b) {
    if ($a['fail_count'] !== $b['fail_count']) return $a['fail_count'] - $b['fail_count'];
    return $b['total_marks'] <=> $a['total_marks'];
});

foreach ($all_students as $i => &$stu) {
    $stu['merit'] = $i + 1;
}
unset($stu);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>মার্কশিট - <?= htmlspecialchars($institute_name) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        body { background-color: #f8f9fa; font-size: 14px; }
        .marksheet { background: #fff; padding: 20px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #ccc; }
        .subject-table th, .subject-table td { text-align: center; font-size: 13px; }
        .badge-pass { background-color: #28a745; color: white; padding: 2px 6px; border-radius: 5px; }
        .badge-fail { background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 5px; }
        .print-btn { margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="text-center mb-4">
        <h3><?= $institute_name ?></h3>
        <p><?= $institute_address ?></p>
        <h4><?= $exam ?> - <?= $class ?> (<?= $year ?>)</h4>
    </div>

    <div class="text-end no-print">
        <button onclick="window.print()" class="btn btn-primary print-btn">প্রিন্ট করুন</button>
    </div>

    <?php foreach ($all_students as $student): ?>
        <div class="marksheet">
            <div class="row mb-3">
                <div class="col-md-6"><strong>নাম:</strong> <?= htmlspecialchars($student['name']) ?></div>
                <div class="col-md-3"><strong>রোল:</strong> <?= htmlspecialchars($student['roll']) ?></div>
                <div class="col-md-3"><strong>মেরিট:</strong> <?= $student['merit'] ?></div>
            </div>

            <table class="table table-bordered subject-table">
                <thead class="table-light">
                    <tr>
                        <th>বিষয়</th>
                        <th>সৃজনশীল</th>
                        <th>বহুনির্বাচনী</th>
                        <th>ব্যবহারিক</th>
                        <th>মোট</th>
                        <th>GPA</th>
                        <th>স্ট্যাটাস</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student['subjects'] as $subject): ?>
                        <tr>
                            <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                            <td><?= $subject['creative'] ?></td>
                            <td><?= $subject['objective'] ?></td>
                            <td><?= $subject['practical'] ?></td>
                            <td><strong><?= $subject['total'] ?></strong></td>
                            <td><?= number_format($subject['gpa'], 2) ?></td>
                            <td>
                                <?php if ($subject['is_fail']): ?>
                                    <span class="badge-fail">F</span>
                                <?php else: ?>
                                    <span class="badge-pass">P</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="row text-center mt-3">
                <div class="col-md-4"><strong>মোট নম্বর:</strong> <?= $student['total_marks'] ?></div>
                <div class="col-md-4"><strong>GPA:</strong> <?= $student['gpa'] ?></div>
                <div class="col-md-4"><strong>ফেল সাবজেক্ট:</strong> <?= $student['fail_count'] ?></div>
            </div>

            <div class="text-center mt-3">
                <?php if ($student['fail_count'] == 0): ?>
                    <div class="alert alert-success">পাস ✅</div>
                <?php else: ?>
                    <div class="alert alert-danger">ফেল ❌</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
