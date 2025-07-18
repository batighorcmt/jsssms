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

$merged_marks = [];
$individual_subjects = [];
$excluded_subject_codes = ['101', '102', '107', '108'];

// Load individual subject marks
foreach ($merged_subjects as $group_name => $sub_codes) {
    $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));

    $sql = "SELECT m.student_id, m.subject_id, s.subject_code, 
                   m.creative_marks, m.objective_marks, m.practical_marks, gm.type, gm.group_name
            FROM marks m
            JOIN subjects s ON m.subject_id = s.id
            JOIN students stu ON m.student_id = stu.student_id
            JOIN subject_group_map gm ON gm.subject_id = s.id
            WHERE m.exam_id = ?
            AND stu.class_id = ?
            AND stu.year = ?
            AND s.subject_code IN ($placeholders)";

    $stmt = $conn->prepare($sql);

    $types = 'sss' . str_repeat('s', count($sub_codes));
    $params = array_merge([$exam_id, $class_id, $year], $sub_codes);

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid = $row['student_id'];
        $sub_code = $row['subject_code'];

        // Collect individual subject marks
        if (!isset($individual_subjects[$sid][$sub_code])) {
            $individual_subjects[$sid][$sub_code] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $individual_subjects[$sid][$sub_code]['creative'] += $row['creative_marks'];
        $individual_subjects[$sid][$sub_code]['objective'] += $row['objective_marks'];
        $individual_subjects[$sid][$sub_code]['practical'] += $row['practical_marks'];

        // Add to merged group
        if (!isset($merged_marks[$sid][$group_name])) {
            $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $merged_marks[$sid][$group_name]['creative'] += $row['creative_marks'];
        $merged_marks[$sid][$group_name]['objective'] += $row['objective_marks'];
        $merged_marks[$sid][$group_name]['practical'] += $row['practical_marks'];
    }
}

// Load subjects for calculation
$subjects_q = mysqli_query($conn, "
    SELECT 
        s.id as subject_id,
        s.subject_name,
        s.subject_code, 
        s.has_creative,
        s.has_objective,
        s.has_practical,
        es.creative_pass,
        es.objective_pass,
        es.practical_pass,
        es.total_marks as max_marks,
        gm.group_name,
        gm.class_id,
        es.pass_type
    FROM exam_subjects es 
    JOIN subjects s ON es.subject_id = s.id
    JOIN subject_group_map gm ON gm.subject_id = s.id 
    WHERE es.exam_id = '$exam_id' 
      AND gm.class_id = '$class_id'
    ORDER BY FIELD(gm.type, 'Compulsory', 'Optional'), s.subject_code
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $subjects[] = $row;
}

// Calculate merged pass marks
$merged_pass_marks = [];
foreach ($merged_subjects as $group_name => $sub_codes) {
    $creative_pass = 0;
    $objective_pass = 0;
    $practical_pass = 0;
    $max_marks = 0;
    $pass_type = 'total';

    foreach ($subjects as $s) {
        if (in_array($s['subject_code'], $sub_codes)) {
            $creative_pass += $s['creative_pass'] ?? 0;
            $objective_pass += $s['objective_pass'] ?? 0;
            $practical_pass += $s['practical_pass'] ?? 0;
            $max_marks += $s['max_marks'] ?? 0;
            $pass_type = $s['pass_type'] ?? 'total';
        }
    }

    $merged_pass_marks[$group_name] = [
        'creative_pass' => $creative_pass,
        'objective_pass' => $objective_pass,
        'practical_pass' => $practical_pass,
        'max_marks' => $max_marks,
        'pass_type' => $pass_type
    ];
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <style>
        th, td { vertical-align: middle !important; font-size: 13px; padding: 2px; }
        @media print {
            .no-print { display: none; }
            table { font-size: 11px; padding: 2px; }
        }
        table { width: 100%; border-collapse: collapse; }
        .text-center { text-align: center; }
        .table-bordered th, .table-bordered td { border: 1px solid #dee2e6; }
        .table-primary { background-color: #cfe2ff; }
        .badge-pass, .badge-fail {
            display: inline-block;
            width: 22px;
            height: 22px;
            line-height: 22px;
            text-align: center;
            border-radius: 50%;
            font-size: 14px;
            color: #fff;
            font-weight: bold;
        }
        .badge-pass { background-color: #28a745; }
        .badge-fail { background-color: #dc3545; }
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
                <th>#</th>
                <th>Student Name</th>
                <th>Roll</th>
                <th>Total Marks</th>
                <th>GPA</th>
                <th>Status</th>
                <th>Fail Count</th>
                <th>Merit Position</th>
            </tr>
        </thead>
        <tbody>
        <?php
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

        // ✅ GPA ক্যালকুলেটর
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

        // ✅ পাস স্ট্যাটাস চেক ফাংশন
        function getPassStatus($marks, $pass_marks, $pass_type = 'total') {
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

        $all_students = [];
        $query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year" . " ORDER BY roll_no";
        $students_q = mysqli_query($conn, $query);
        
        if (!$students_q) {
            echo "<tr><td colspan='8' class='text-danger'>Error fetching students: " . mysqli_error($conn) . "</td></tr>";
            exit;
        }

        while ($stu = mysqli_fetch_assoc($students_q)) {
            $total_marks = 0;
            $fail_count = 0;
            $compulsory_gpa_total = 0;
            $compulsory_gpa_subjects = 0;
            $optional_gpas = [];

            // Process merged subjects (Bangla and English)
            foreach ($merged_subjects as $group_name => $sub_codes) {
                $c = $merged_marks[$stu['student_id']][$group_name]['creative'] ?? 0;
                $o = $merged_marks[$stu['student_id']][$group_name]['objective'] ?? 0;
                $p = $merged_marks[$stu['student_id']][$group_name]['practical'] ?? 0;
                $sub_total = $c + $o + $p;
                
                $pass_marks = [
                    'creative' => $merged_pass_marks[$group_name]['creative_pass'],
                    'objective' => $merged_pass_marks[$group_name]['objective_pass'],
                    'practical' => $merged_pass_marks[$group_name]['practical_pass']
                ];
                
                $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
                $pass_type = $merged_pass_marks[$group_name]['pass_type'] ?? 'total';
                
                // Check fail status for merged subject
                if (!getPassStatus($marks_arr, $pass_marks, $pass_type)) {
                    $fail_count++;
                }
                
                // Calculate GPA for merged subject
                $gpa = subjectGPA($sub_total, $merged_pass_marks[$group_name]['max_marks']);
                $compulsory_gpa_total += $gpa;
                $compulsory_gpa_subjects++;
                
                $total_marks += $sub_total;
            }

            // Process other subjects
            foreach ($subjects as $sub) {
                $subject_code = $sub['subject_code'] ?? null;
                if (!$subject_code || in_array($subject_code, $excluded_subject_codes)) {
                    continue;
                }

                $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'creative') : 0;
                $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'objective') : 0;
                $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'practical') : 0;
                $sub_total = $c + $o + $p;

                $pass_marks = [
                    'creative' => $sub['creative_pass'] ?? 0,
                    'objective' => $sub['objective_pass'] ?? 0,
                    'practical' => $sub['practical_pass'] ?? 0
                ];
                
                $pass_type = $sub['pass_type'] ?? 'total';
                $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
                
                // Check fail status
                if (!getPassStatus($marks_arr, $pass_marks, $pass_type)) {
                    $fail_count++;
                }

                // Calculate GPA
                $gpa = subjectGPA($sub_total, $sub['max_marks']);
                if (($sub['type'] ?? 'Compulsory') === 'Compulsory') {
                    $compulsory_gpa_total += $gpa;
                    $compulsory_gpa_subjects++;
                } elseif (($sub['type'] ?? 'Optional') === 'Optional') {
                    $optional_gpas[] = $gpa;
                }

                $total_marks += $sub_total;
            }

            // Calculate final GPA
            $optional_bonus = 0.00;
            if (!empty($optional_gpas)) {
                $max_optional = max($optional_gpas);
                if ($max_optional > 2.00) {
                    $optional_bonus = $max_optional - 2.00;
                }
            }

            $final_gpa = 0.00;
            if ($compulsory_gpa_subjects > 0) {
                $final_gpa = ($compulsory_gpa_total + $optional_bonus) / $compulsory_gpa_subjects;
            }
            if ($fail_count > 0) $final_gpa = 0.00;
            $final_gpa = number_format(min($final_gpa, 5.00), 2);

            $fail_display = $fail_count > 0 ? $fail_count : "";
            $status = ($fail_count == 0)
                ? '<i class="bi bi-check-circle-fill text-success"></i> Passed'
                : '<i class="bi bi-x-circle-fill text-danger"></i> Failed';

            $all_students[] = [
                'fail' => $fail_count,
                'total' => $total_marks,
                'roll' => $stu['roll_no'],
                'row' => "<tr>
                            <td></td>
                            <td>{$stu['student_name']}</td>
                            <td>{$stu['roll_no']}</td>
                            <td>$total_marks</td>
                            <td>$final_gpa</td>
                            <td>$status</td>
                            <td>$fail_display</td>
                            <td>MERIT_POS</td>
                          </tr>"
            ];
        }

        // ✅ Merit Position Logic
        $ranked_students = $all_students;
        usort($ranked_students, function ($a, $b) {
            return $a['fail'] <=> $b['fail']   // Fewer fails first
                ?: $b['total'] <=> $a['total'] // Higher totals next
                ?: $a['roll'] <=> $b['roll'];  // Lower roll numbers next
        });

        // ✅ Render rows with merit position
        foreach ($ranked_students as $i => $stu) {
            $serial = $i + 1;
            $row = preg_replace('/<td><\/td>/', "<td>$serial</td>", $stu['row'], 1);
            $merit_pos = $i + 1;
            $row = str_replace('<td>MERIT_POS</td>', "<td>$merit_pos</td>", $row);
            echo $row;
        }
        ?>
        </tbody>
    </table>
</div>
</body>
</html>