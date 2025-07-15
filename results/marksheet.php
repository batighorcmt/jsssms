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

// প্রতিষ্ঠানের তথ্য
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";
$institute_logo = "../assets/logo.png";

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

// Load remaining subjects
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

$display_subjects = [];
$merged_pass_marks = [];
$merged_added = [];
$insert_after_codes = [
    'Bangla' => '102',
    'English' => '108'
];

// Fetch highest marks for each subject
$subject_highest_marks = [];
foreach ($subjects as $sub) {
    $subject_id = $sub['subject_id'];
    $query = "SELECT 
                SUM(creative_marks + objective_marks + practical_marks) as total 
              FROM marks 
              WHERE subject_id = '$subject_id' AND exam_id = '$exam_id'
              GROUP BY student_id
              ORDER BY total DESC
              LIMIT 1";
    $result = mysqli_query($conn, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $subject_highest_marks[$sub['subject_code']] = $row['total'];
    } else {
        $subject_highest_marks[$sub['subject_code']] = 0;
    }
}

// Calculate highest marks for merged subjects
foreach ($merged_subjects as $group_name => $sub_codes) {
    $highest = 0;
    foreach ($students as $stu) {
        $student_id = $stu['student_id'];
        if (isset($merged_marks[$student_id][$group_name])) {
            $total = $merged_marks[$student_id][$group_name]['creative'] + 
                     $merged_marks[$student_id][$group_name]['objective'] + 
                     $merged_marks[$student_id][$group_name]['practical'];
            if ($total > $highest) {
                $highest = $total;
            }
        }
    }
    $subject_highest_marks[$group_name] = $highest;
}

foreach ($subjects as $sub) {
    $code = $sub['subject_code'];
    $display_subjects[] = array_merge($sub, ['is_merged' => false]);
    

    foreach ($merged_subjects as $group_name => $sub_codes) {
        if (in_array($code, $sub_codes) && $code === $insert_after_codes[$group_name] && !in_array($group_name, $merged_added)) {
            // Calculate merged subject pass marks only once
            if (!isset($merged_pass_marks[$group_name])) {
                $creative_pass = 0;
                $objective_pass = 0;
                $practical_pass = 0;
                $max_marks = 0;
                $type = 'Compulsory';

                foreach ($subjects as $s) {
                    if (in_array($s['subject_code'], $merged_subjects[$group_name])) {
                        $creative_pass += $s['creative_pass'] ?? 0;
                        $objective_pass += $s['objective_pass'] ?? 0;
                        $practical_pass += $s['practical_pass'] ?? 0;
                        $max_marks += $s['max_marks'] ?? 0;
                        if (isset($s['type'])) {
                            $type = $s['type'];
                        }
                    }
                }

                $merged_pass_marks[$group_name] = [
                    'creative_pass' => $creative_pass,
                    'objective_pass' => $objective_pass,
                    'practical_pass' => $practical_pass,
                    'max_marks' => $max_marks,
                    'type' => $type
                ];
            }

            // Insert merged subject now
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
                'type' => $merged_pass_marks[$group_name]['type'],
                'is_merged' => true
            ];
            $merged_added[] = $group_name;
        }
    }
}

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

// ✅ ফেল চেক ফাংশন
function isFail($marks, $pass_mark) {
    return $marks < $pass_mark || $marks == 0;
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

// ✅ পাস স্ট্যাটাস চেক ফাংশন - UPDATED TO NEW CONDITION
function getPassStatus($class_id, $marks, $pass_marks, $pass_type = 'total') {
    // Calculate total obtained marks
    $total_obtained = ($marks['creative'] ?? 0) + ($marks['objective'] ?? 0) + ($marks['practical'] ?? 0);
    
    // Calculate total pass marks required
    $total_pass_marks = ($pass_marks['creative'] ?? 0) + ($pass_marks['objective'] ?? 0) + ($pass_marks['practical'] ?? 0);
    
    // New condition: total obtained marks must be >= total pass marks
    return $total_obtained >= $total_pass_marks;
}

// প্রথমে সেকশন ডাটা fetch করুন
$section_query = "SELECT id, section_name FROM sections";
$section_result = mysqli_query($conn, $section_query);
$sections = [];
while ($row = mysqli_fetch_assoc($section_result)) {
    $sections[$row['id']] = $row['section_name'];
}

// শিক্ষার্থী ডাটা fetch করার সময়
$query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year";
if (!empty($search_roll)) {
    $query .= " AND roll_no = '$search_roll'";
}

$students_q = mysqli_query($conn, $query);
if (!$students_q) {
    echo "<div class='alert alert-danger container mt-4'>Error fetching students: " . mysqli_error($conn) . "</div>";
    exit;
}

$students = [];
while ($stu = mysqli_fetch_assoc($students_q)) {
    $students[] = $stu;
}

// Process student data for mark sheets
$all_students = [];
foreach ($students as $stu) {
    $student_id = $stu['student_id'];
    $total_marks = 0;
    $fail_count = 0;
    
    $compulsory_gpa_total = 0;
    $compulsory_gpa_subjects = 0;
    $optional_gpas = [];
    
    $student_subjects = [];
    
    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null;
        if (!$subject_code) continue;
        
        $c = $o = $p = 0;
        $sub_total = 0;
        $gpa = 0;
        $is_fail = false;
        
        if ($sub['is_merged']) {
            $group = $sub['subject_name'];
            $c = $merged_marks[$student_id][$group]['creative'] ?? 0;
            $o = $merged_marks[$student_id][$group]['objective'] ?? 0;
            $p = $merged_marks[$student_id][$group]['practical'] ?? 0;
        } else {
            $c = $sub['has_creative'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'creative') : 0;
            $o = $sub['has_objective'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'objective') : 0;
            $p = $sub['has_practical'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'practical') : 0;
        }
        
        $sub_total = $c + $o + $p;
        
        if (!in_array($subject_code, $excluded_subject_codes)) {
            $pass_type = $sub['pass_type'] ?? 'total';
            $pass_marks = [
                'creative' => $sub['creative_pass'] ?? 0,
                'objective' => $sub['objective_pass'] ?? 0,
                'practical' => $sub['practical_pass'] ?? 0
            ];
            
            $marks_arr = ['creative' => $c, 'objective' => $o, 'practical' => $p];
            $is_pass = getPassStatus($class_id, $marks_arr, $pass_marks, $pass_type);
            
            if (!$is_pass) {
                $fail_count++;
                $is_fail = true;
            }
            
            $gpa = subjectGPA($sub_total, $sub['max_marks']);
            
            if (($sub['type'] ?? 'Compulsory') === 'Compulsory') {
                $compulsory_gpa_total += $gpa;
                $compulsory_gpa_subjects++;
            } elseif (($sub['type'] ?? 'Optional') === 'Optional') {
                $optional_gpas[] = $gpa;
            }
            
            $total_marks += $sub_total;
        }
        
        $highest_in_class = $subject_highest_marks[$subject_code] ?? 0;
        
        $student_subjects[] = [
            'name' => $sub['subject_name'],
            'code' => $subject_code,
            'c_marks' => $c,
            'o_marks' => $o,
            'p_marks' => $p,
            'total' => $sub_total,
            'highest_in_class' => $highest_in_class,
            'gpa' => $gpa,
            'is_fail' => $is_fail,
            'is_merged' => $sub['is_merged'] ?? false,
            'has_creative' => $sub['has_creative'] ?? false,
            'has_objective' => $sub['has_objective'] ?? false,
            'has_practical' => $sub['has_practical'] ?? false,
            'c_pass' => $sub['creative_pass'] ?? 0,
            'o_pass' => $sub['objective_pass'] ?? 0,
            'p_pass' => $sub['practical_pass'] ?? 0,
            'max_marks' => $sub['max_marks'] ?? 0
        ];
    }
    
    // Calculate optional bonus
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
    $status = ($fail_count == 0) ? 'Passed' : 'Failed';
    
    $all_students[] = [
        'id' => $student_id,
        'name' => $stu['student_name'],
        'roll' => $stu['roll_no'],
        'section_id' => $stu['section_id'],
        'section_name' => $sections[$stu['section_id']] ?? 'N/A',
        'subjects' => $student_subjects,
        'total_marks' => $total_marks,
        'gpa' => $final_gpa,
        'status' => $status,
        'fail_count' => $fail_count
    ];
}

// Calculate merit positions
usort($all_students, function ($a, $b) {
    if ($a['fail_count'] !== $b['fail_count']) {
        return $a['fail_count'] - $b['fail_count'];
    }
    return $b['total_marks'] - $a['total_marks'];
});

$merit_positions = [];
$position = 1;
foreach ($all_students as $student) {
    $merit_positions[$student['id']] = $position++;
}

// Assign merit positions to students
foreach ($all_students as &$student) {
    $student['merit'] = $merit_positions[$student['id']];
}
unset($student);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>মার্কশিট - <?= $institute_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <style>
        @font-face {
        font-family: 'BenSenHandwriting';
        src: url('../assets/fonts/BenSenHandwriting.ttf') format('truetype');
        }

        body {
            background-color: #f0f8ff;
            font-family: 'BenSenHandwriting', Segoe UI, Tahoma, Geneva, Verdana, sans-serif;
        }
        
        @media print {
            @page {
                size: A4;
                margin: 0.5cm;
            }
            body {
                background-color: white;
                font-size: 10px !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .no-print {
                display: none !important;
            }
            .marksheet-container {
                max-width: 100% !important;
                padding: 10px !important;
                margin: 0 !important;
                border: none !important;
                box-shadow: none !important;
                page-break-inside: avoid;
                page-break-after: always;
            }
            .subject-table th,
            .subject-table td {
                padding: 3px !important;
                font-size: 8px !important;
            }
            .student-info,
            .summary-card {
                padding: 5px !important;
                margin-bottom: 5px !important;
            }
            .gpa-display {
                font-size: 16px !important;
            }
            .status-display {
                font-size: 14px !important;
                padding: 5px !important;
            }
            .institute-header {
                margin-bottom: 5px !important;
                padding-bottom: 3px !important;
            }
            .institute-header h4 {
                font-size: 14px !important;
                margin-bottom: 2px !important;
            }
            .institute-header p {
                font-size: 10px !important;
                margin-bottom: 2px !important;
            }
            .footer-note {
                font-size: 8px !important;
                margin-top: 5px !important;
            }
            .merit-badge {
                font-size: 12px !important;
                padding: 3px 8px !important;
            }
            .page-break {
                display: none !important;
            }
        }
        
        .marksheet-container {
            width: 210mm;
            min-height: 297mm;
            margin: 10px auto;
            padding: 15mm;
            background-color: white;
            border: 1px solid #3498db;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .institute-header {
            text-align: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
        }
        .institute-header h4 {
            color: #2c3e50;
            font-weight: 700;
            margin-bottom: 5px;
        }
        .institute-header p {
            color: #7f8c8d;
            margin-bottom: 5px;
        }
        .institute-header img {
        max-height: 60px;
        width: auto;
        object-fit: contain;
        } 

        .institute-header .d-flex {
            flex-wrap: wrap;
            justify-content: center;
            align-items: center;
            gap: 15px;
        }

        @media print {
            .institute-header img {
                max-height: 40px;
            }
        }
        .student-info {
            background-color: #e3f2fd;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        .subject-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .subject-table th {
            background-color: #3498db;
            color: white;
            text-align: center;
            vertical-align: middle;
            padding: 5px;
        }
        .subject-table td {
            padding: 5px;
            text-align: center;
            border: 1px solid #dee2e6;
        }
        .summary-card {
            background-color: #e8f4f8;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
        }
        .fail-mark {
            color: #e74c3c;
            font-weight: bold;
        }
        .badge-pass {
            background-color: #2ecc71;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
        }
        .badge-fail {
            background-color: #e74c3c;
            color: white;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 10px;
        }
        .merit-badge {
            background-color: #f39c12;
            color: white;
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 14px;
            font-weight: bold;
        }
        .footer-note {
            text-align: center;
            margin-top: 15px;
            padding-top: 8px;
            border-top: 1px dashed #95a5a6;
            color: #7f8c8d;
            font-size: 11px;
        }
        .print-controls {
            margin-bottom: 20px;
            text-align: center;
        }
        .gpa-display {
            font-size: 20px;
            font-weight: bold;
            color: #2980b9;
            text-align: center;
            margin: 5px 0;
        }
        .status-display {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            padding: 6px;
            border-radius: 5px;
        }
        .status-passed {
            background-color: #d5f5e3;
            color: #27ae60;
        }
        .status-failed {
            background-color: #fadbd8;
            color: #c0392b;
        }
        .search-form {
            max-width: 500px;
            margin: 20px auto;
            padding: 20px;
            background-color: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        .compact-table {
            font-size: 11px;
        }
        .compact-table th, .compact-table td {
            padding: 3px 5px;
        }
        .print-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .highest-mark {
            font-weight: bold;
            color: #27ae60;
        }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="print-controls no-print">
        <h3 class="mb-4"><?= $institute_name ?> - মার্কশিট সিস্টেম</h3>
        <h4 class="mb-3"><?= $exam ?> - <?= $class ?> (<?= $year ?>)</h4>
        
        <div class="search-form">
            <form method="get" class="mb-4">
                <input type="hidden" name="exam_id" value="<?= $exam_id ?>">
                <input type="hidden" name="class_id" value="<?= $class_id ?>">
                <input type="hidden" name="year" value="<?= $year ?>">
                
                <div class="input-group mb-3">
                    <input type="text" class="form-control form-control-lg" 
                           placeholder="শিক্ষার্থীর রোল নম্বর দিন" 
                           name="search_roll" 
                           value="<?= htmlspecialchars($search_roll) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i> অনুসন্ধান
                    </button>
                </div>
            </form>
            
            <?php if (!empty($search_roll) && empty($all_students)): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-circle"></i> এই রোল নম্বরে কোনো শিক্ষার্থী পাওয়া যায়নি: <?= htmlspecialchars($search_roll) ?>
                </div>
            <?php endif; ?>
        </div>
        
        <button onclick="window.print()" class="btn btn-primary btn-lg no-print">
            <i class="bi bi-printer"></i> সকল মার্কশিট প্রিন্ট করুন
        </button>
    </div>

    <?php if (empty($all_students) && empty($search_roll)): ?>
        <div class="alert alert-info text-center">
            <i class="bi bi-info-circle"></i> অনুগ্রহ করে রোল নম্বর দিয়ে অনুসন্ধান করুন
        </div>
    <?php endif; ?>

    <?php foreach ($all_students as $index => $student): ?>
        <div class="marksheet-container">
            <div class="institute-header">
    <div class="d-flex align-items-center justify-content-center">
        <?php if(file_exists($institute_logo)): ?>
            <img src="<?= $institute_logo ?>" alt="Logo" style="height: 70px; margin-right: 15px;">
        <?php endif; ?>
        <div>
            <h4><?= $institute_name ?></h4>
            <p><?= $institute_address ?></p>
            <h4><?= $exam ?></h4>
        </div>
    </div>
</div>
            
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="merit-badge">
                    <i class="bi bi-trophy"></i> মেরিট পজিশন: <?= $student['merit'] ?>
                </div>
                <div class="text-end">
                    <button onclick="printSingleMarksheet(<?= $index ?>)" class="btn btn-sm btn-outline-primary no-print">
                        <i class="bi bi-printer"></i> প্রিন্ট
                    </button>
                </div>
            </div>
            
            <div class="student-info">
                <table style="width: 100%;">
                    <tr>
                        <td colspan="3" style="text-align: left; font-family: 'Kalpurush'><strong>শিক্ষার্থীর আইডি:</strong> <?= $student['id'] ?></td>
                    </tr>
                    <tr>                        
                        <td colspan="3" style="text-align: left;"><strong>শিক্ষার্থীর নাম:</strong> <?= $student['name'] ?></td>
                    </tr>
                    <tr>
                        <td style="text-align: left;"><strong>রোল নং:</strong> <?= $student['roll'] ?></td>
                        <td style="text-align: left;"><strong>শ্রেণি:</strong> <?= $class ?></td>
                        <td style="text-align: left;"><strong>শাখা:</strong> <?= $student['section_name'] ?? 'N/A' ?></td>
                    </tr>
                </table>
            </div>
            
            <table class="subject-table compact-table">
                <thead>
                    <tr>
                        <th>বিষয়</th>
                        <th>ক্রিয়েটিভ</th>
                        <th>অবজেক্টিভ</th>
                        <th>প্র্যাকটিক্যাল</th>
                        <th>মোট প্রাপ্ত নম্বর</th>
                        <th>সর্বোচ্চ প্রাপ্ত নম্বর</th>
                        <th>পাস মার্ক</th>
                        <th>জিপিএ</th>
                        <th>স্ট্যাটাস</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student['subjects'] as $subject): ?>
                        <tr>
                            <td class="text-start">
                                <?= $subject['name'] ?>
                                <?php if ($subject['is_merged']): ?>
                                    <br><small class="text-muted">(মার্জড সাবজেক্ট)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($subject['has_creative']): ?>
                                    <?php if (isFail($subject['c_marks'], $subject['c_pass'])): ?>
                                        <span class="fail-mark"><?= $subject['c_marks'] ?></span>
                                    <?php else: ?>
                                        <?= $subject['c_marks'] ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($subject['has_objective']): ?>
                                    <?php if (isFail($subject['o_marks'], $subject['o_pass'])): ?>
                                        <span class="fail-mark"><?= $subject['o_marks'] ?></span>
                                    <?php else: ?>
                                        <?= $subject['o_marks'] ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($subject['has_practical']): ?>
                                    <?php if (isFail($subject['p_marks'], $subject['p_pass'])): ?>
                                        <span class="fail-mark"><?= $subject['p_marks'] ?></span>
                                    <?php else: ?>
                                        <?= $subject['p_marks'] ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><strong><?= $subject['total'] ?></strong></td>
                            <td class="highest-mark"><?= $subject['highest_in_class'] ?></td>
                            <td><?= $subject['c_pass'] + $subject['o_pass'] + $subject['p_pass'] ?></td>
                            <td><strong><?= $subject['gpa'] > 0 ? number_format($subject['gpa'], 2) : '-' ?></strong></td>
                            <td>
                                <?php if (in_array($subject['code'], $excluded_subject_codes)): ?>
                                    -
                                <?php else: ?>
                                    <?php if ($subject['is_fail']): ?>
                                        <span class="badge-fail">F</span>
                                    <?php else: ?>
                                        <span class="badge-pass">P</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="summary-card">
                <div class="row">
                    <div class="text-center">
                <table style="border: none; width: 100%;">
                    <tr>
                        <td class="mb-2"><strong>মোট প্রাপ্ত নম্বর:</strong></td>
                        <td class="mb-2"><strong>জিপিএ:</strong></td>
                        <td class="mb-2"><strong>ফেল সাবজেক্ট:</strong></td>
                    </tr>
                    <tr>
                        <td class="fs-4"><strong><?= $student['total_marks'] ?></strong></td>
                        <td class="gpa-display"><strong><?= $student['gpa'] ?></strong></td>
                        <td class="fs-4"><strong><?= $student['fail_count'] > 0 ? $student['fail_count'] : '0' ?></strong></td>
                    </tr>
                    <tr>
                        
                        
                    </tr>
                </table>
                    </div>
    
                </div>
                
                <div class="mt-3">
                    <div class="status-display <?= $student['status'] === 'Passed' ? 'status-passed' : 'status-failed' ?>">
                        <?= $student['status'] === 'Passed' ? '<i class="bi bi-check-circle"></i> পাস' : '<i class="bi bi-x-circle"></i> ফেল' ?>
                    </div>
                </div>
            </div>
            
            <div class="footer-note">
                <table style="width: 100%;">
                    <tr>
                        <td style="text-align: left;"> প্রিন্টের তারিখ: <?= date('d/m/Y') ?> </td>
                        <td style="text-align: right;"></td>
                        <td style="text-align: center;"> <div><img src="../assets/hm_sign.jpg" alt="HM Signature" style="vertical-align: middle;"></div>
                            <div> প্রধান শিক্ষকের স্বাক্ষর </div>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="3" style="text-align: center;">মার্কশিট প্রস্তুতকারী: বাতিঘর কম্পিউটার’স, জোড়পুকুরিয়া, গাংনী, মেহেরপুর।</td>
                    </tr>
                </table>
            </div>
               
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    function printSingleMarksheet(index) {
        const originalContents = document.body.innerHTML;
        const marksheet = document.querySelectorAll('.marksheet-container')[index].outerHTML;
        
        document.body.innerHTML = `
            <style>
                @page {
                    size: A4;
                    margin: 0.5cm;
                }
                body {
                    font-family: Arial, sans-serif;
                    font-size: 14px;
                    padding: 0;
                    margin: 0;
                }
                .marksheet-container {
                    width: 100%;
                    min-height: 100%;
                    padding: 15mm;
                    margin: 0;
                    border: none;
                    box-shadow: none;
                }
                .subject-table th,
                .subject-table td {
                    padding: 3px;
                    font-size: 124px;
                }
                .student-info,
                .summary-card {
                    padding: 5px;
                    margin-bottom: 5px;
                }
                .gpa-display {
                    font-size: 16px;
                }
                .status-display {
                    font-size: 14px;
                    padding: 5px;
                }
                .institute-header {
                    margin-bottom: 5px;
                    padding-bottom: 3px;
                }
                .institute-header h4 {
                    font-size: 14px;
                    margin-bottom: 2px;
                }
                .institute-header p {
                    font-size: 10px;
                    margin-bottom: 2px;
                }
                .footer-note {
                    font-size: 8px;
                    margin-top: 5px;
                }
                .merit-badge {
                    font-size: 12px;
                    padding: 3px 8px;
                }
                .no-print {
                    display: none;
                }
                .highest-mark {
                    font-weight: bold;
                    color: #27ae60;
                }
            </style>
            ${marksheet}
        `;
        
        window.print();
        document.body.innerHTML = originalContents;
    }
</script>
</body>
</html>