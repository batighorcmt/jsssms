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
    SELECT 
        s.*, 
        es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks, es.pass_type, 
        IFNULL(gm.group_name, '') AS group_name,
        gm.type AS type,
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

// Deduplicate same subject appearing with both Compulsory and Optional mappings
$by_code = [];
foreach ($subjects as $row) {
    $code = $row['subject_code'];
    $curr_opt = strtolower((string)($row['type'] ?? ($row['subject_type'] ?? '')));
    if (!isset($by_code[$code])) {
        $by_code[$code] = $row;
        continue;
    }
    $existing_opt = strtolower((string)($by_code[$code]['type'] ?? ($by_code[$code]['subject_type'] ?? '')));
    // Prefer Compulsory over Optional
    if ($existing_opt === 'optional' && $curr_opt === 'compulsory') {
        $by_code[$code] = $row;
    }
}
$subjects = array_values($by_code);

// Build display subjects replacing 101/102 with Bangla and 107/108 with English (single rows)
$display_subjects = [];
$merged_added = [];
foreach ($subjects as $sub) {
    $code = $sub['subject_code'];

    // If this is one of the components, optionally insert merged at the right time and skip the component itself
    if (in_array($code, $excluded_subject_codes, true)) {
        foreach ($merged_subjects as $label => $codes) {
            if (in_array($code, $codes, true) && $code === $insert_after_codes[$label] && !in_array($label, $merged_added, true)) {
                // Aggregate pass marks and max marks for merged subject
                $creative_pass = 0; $objective_pass = 0; $practical_pass = 0; $max_marks = 0;
                $has_creative = 0; $has_objective = 0; $has_practical = 0;
                $merged_type = 'Compulsory';

                foreach ($subjects as $s2) {
                    if (in_array($s2['subject_code'], $codes, true)) {
                        $creative_pass += (int)($s2['creative_pass'] ?? 0);
                        $objective_pass += (int)($s2['objective_pass'] ?? 0);
                        $practical_pass += (int)($s2['practical_pass'] ?? 0);
                        $max_marks += (int)($s2['total_marks'] ?? 0);
                        $has_creative = $has_creative || (int)($s2['has_creative'] ?? 0);
                        $has_objective = $has_objective || (int)($s2['has_objective'] ?? 0);
                        $has_practical = $has_practical || (int)($s2['has_practical'] ?? 0);
                        if (isset($s2['type']) && strtolower((string)$s2['type']) === 'optional') {
                            $merged_type = 'Optional';
                        }
                    }
                }

                $display_subjects[] = [
                    'subject_name'   => $label,
                    'subject_code'   => $label,
                    'has_creative'   => $has_creative ? 1 : 0,
                    'has_objective'  => $has_objective ? 1 : 0,
                    'has_practical'  => $has_practical ? 1 : 0,
                    'creative_pass'  => $creative_pass,
                    'objective_pass' => $objective_pass,
                    'practical_pass' => $practical_pass,
                    'total_marks'    => $max_marks,
                    'pass_type'      => 'individual',
                    'type'           => $merged_type,
                    'is_merged'      => true
                ];
                $merged_added[] = $label;
            }
        }
        // Skip original 101/102/107/108 rows from display
        continue;
    }

    // Normal subject row
    $sub['is_merged'] = false;
    $display_subjects[] = $sub;
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

    foreach ($display_subjects as $sub) {
        $code = $sub['subject_code'];

        // Determine if subject applies for this student's group
        $subject_group_name = strtolower(trim($sub['group_name'] ?? ''));
        $is_merged = !empty($sub['is_merged']);

        if (!$is_merged) {
            // For normal subjects: skip if group doesn't match and it's not a common subject
            if ($subject_group_name !== $group && !in_array($subject_group_name, ['', 'none', 'null'], true)) {
                continue;
            }
        } else {
            // For merged subjects: ensure at least one component is applicable to this student's group
            $label = $sub['subject_name'];
            $codes = $merged_subjects[$label] ?? [];
            $applicable = false;
            foreach ($subjects as $sub2) {
                $sub2_group_name = strtolower(trim($sub2['group_name'] ?? ''));
                if (in_array($sub2['subject_code'], $codes, true) && ($sub2_group_name === $group || in_array($sub2_group_name, ['', 'none', 'null'], true))) {
                    $applicable = true;
                    break;
                }
            }
            if (!$applicable) continue;
        }

        // Calculate marks
        $c = 0; $o = 0; $p = 0; $sub_total = 0; $gpa = 0; $is_pass = true;

        if ($is_merged) {
            $label = $sub['subject_name'];
            $codes = $merged_subjects[$label] ?? [];

            foreach ($subjects as $sub2) {
                $sub2_group_name = strtolower(trim($sub2['group_name'] ?? ''));
                if (in_array($sub2['subject_code'], $codes, true) && ($sub2_group_name === $group || in_array($sub2_group_name, ['', 'none', 'null'], true))) {
                    $c += !empty($sub2['has_creative']) ? getMarks($student_id, $sub2['id'], $exam_id, 'creative') : 0;
                    $o += !empty($sub2['has_objective']) ? getMarks($student_id, $sub2['id'], $exam_id, 'objective') : 0;
                    $p += !empty($sub2['has_practical']) ? getMarks($student_id, $sub2['id'], $exam_id, 'practical') : 0;
                }
            }

            $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
            $pass_marks = [
                'creative' => $sub['creative_pass'] ?? 0,
                'objective' => $sub['objective_pass'] ?? 0,
                'practical' => $sub['practical_pass'] ?? 0
            ];
            $sub_total = $c + $o + $p;
            $is_pass = getPassStatus($class_id, $marks_arr, $pass_marks, 'individual');
            $gpa = subjectGPA($sub_total, $sub['total_marks'] ?? 0);
        } else {
            $c = !empty($sub['has_creative']) ? getMarks($student_id, $sub['id'], $exam_id, 'creative') : 0;
            $o = !empty($sub['has_objective']) ? getMarks($student_id, $sub['id'], $exam_id, 'objective') : 0;
            $p = !empty($sub['has_practical']) ? getMarks($student_id, $sub['id'], $exam_id, 'practical') : 0;

            $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
            $pass_marks = [
                'creative' => $sub['creative_pass'] ?? 0,
                'objective' => $sub['objective_pass'] ?? 0,
                'practical' => $sub['practical_pass'] ?? 0
            ];
            $sub_total = $c + $o + $p;
            $is_pass = getPassStatus($class_id, $marks_arr, $pass_marks, $sub['pass_type'] ?? 'total');
            $gpa = subjectGPA($sub_total, $sub['total_marks'] ?? 0);
        }

        if (!$is_pass) $fail_count++;
        $total_marks += $sub_total;

        $subject_data = [
            'subject_name' => $sub['subject_name'],
            'creative'     => $c,
            'objective'    => $o,
            'practical'    => $p,
            'total'        => $sub_total,
            'gpa'          => $gpa,
            'is_fail'      => !$is_pass
        ];

        $is_optional = false;
        $stype = strtolower((string)($sub['type'] ?? ($sub['subject_type'] ?? 'Compulsory')));
        if ($stype === 'optional') $is_optional = true;

        if ($is_optional) {
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
