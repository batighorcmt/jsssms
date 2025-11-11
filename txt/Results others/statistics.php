<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
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

// ✅ পাস স্ট্যাটাস চেক ফাংশন
function getPassStatus($class_id, $marks, $pass_marks, $pass_type = 'total') {
    // মোট মার্কস
    $total = ($marks['creative'] ?? 0) + ($marks['objective'] ?? 0) + ($marks['practical'] ?? 0);

    if ($pass_type === 'total') {
        return $total >= 33; // সাধারণভাবে মোট 33 পেলেই পাস
    } else {
        // প্রতিটি অংশে আলাদা পাস লাগবে
        if (isset($pass_marks['creative']) && ($marks['creative'] < $pass_marks['creative'] || $marks['creative'] == 0)) return false;
        if (isset($pass_marks['objective']) && ($marks['objective'] < $pass_marks['objective'] || $marks['objective'] == 0)) return false;
        if (isset($pass_marks['practical']) && $pass_marks['practical'] > 0 && ($marks['practical'] < $pass_marks['practical'] || $marks['practical'] == 0)) return false;

        return true;
    }
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

// পরিসংখ্যান সংগ্রহ
$total_students = 0;
$passed_students = 0;
$failed_students = 0;
$pass_rate = 0;

$subject_stats = [];
$gpa_distribution = [
    '5.00' => 0,
    '4.00-4.99' => 0,
    '3.50-3.99' => 0,
    '3.00-3.49' => 0,
    '2.00-2.99' => 0,
    '1.00-1.99' => 0,
    '0.00' => 0
];

// ছাত্রদের ডেটা সংগ্রহ
$students_data = [];
$query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year";
$students_q = mysqli_query($conn, $query);

while ($stu = mysqli_fetch_assoc($students_q)) {
    $total_students++;
    $student_id = $stu['student_id'];
    $students_data[$student_id] = [
        'name' => $stu['student_name'],
        'roll' => $stu['roll_no'],
        'total_marks' => 0,
        'gpa' => 0,
        'status' => 'Passed',
        'fail_count' => 0,
        'subject_results' => []
    ];

    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null;
        if (!$subject_code) continue;

        $c = $o = $p = 0;
        $sub_total = 0;
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
            
            if ($pass_type === 'total') {
                $required_total = ($sub['creative_pass'] ?? 0) + ($sub['objective_pass'] ?? 0) + ($sub['practical_pass'] ?? 0);
                if ($sub_total < $required_total) {
                    $is_fail = true;
                    $students_data[$student_id]['fail_count']++;
                }
            } else {
                if (($sub['has_creative'] && $c < ($sub['creative_pass'] ?? 0)) ||
                    ($sub['has_objective'] && $o < ($sub['objective_pass'] ?? 0)) ||
                    ($sub['has_practical'] && $p < ($sub['practical_pass'] ?? 0))) {
                    $is_fail = true;
                    $students_data[$student_id]['fail_count']++;
                }
            }

            $gpa = subjectGPA($sub_total, $sub['max_marks']);
            $students_data[$student_id]['total_marks'] += $sub_total;

            // বিষয়ভিত্তিক পরিসংখ্যান
            if (!isset($subject_stats[$subject_code])) {
                $subject_stats[$subject_code] = [
                    'name' => $sub['subject_name'],
                    'passed' => 0,
                    'failed' => 0,
                    'max_marks' => 0,
                    'min_marks' => 100,
                    'total_marks' => 0
                ];
            }

            $subject_stats[$subject_code]['total_marks'] += $sub_total;
            
            if ($is_fail) {
                $subject_stats[$subject_code]['failed']++;
            } else {
                $subject_stats[$subject_code]['passed']++;
            }

            if ($sub_total > $subject_stats[$subject_code]['max_marks']) {
                $subject_stats[$subject_code]['max_marks'] = $sub_total;
            }
            if ($sub_total < $subject_stats[$subject_code]['min_marks']) {
                $subject_stats[$subject_code]['min_marks'] = $sub_total;
            }
        }
    }

    // GPA গণনা
    $compulsory_gpa_total = 0;
    $compulsory_gpa_subjects = 0;
    $optional_gpas = [];

    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null;
        if (!$subject_code || in_array($subject_code, $excluded_subject_codes)) continue;

        $sub_total = 0;
        if ($sub['is_merged']) {
            $group = $sub['subject_name'];
            $sub_total = ($merged_marks[$student_id][$group]['creative'] ?? 0) + 
                         ($merged_marks[$student_id][$group]['objective'] ?? 0) + 
                         ($merged_marks[$student_id][$group]['practical'] ?? 0);
        } else {
            $c = $sub['has_creative'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'creative') : 0;
            $o = $sub['has_objective'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'objective') : 0;
            $p = $sub['has_practical'] ? getMarks($student_id, $sub['subject_id'], $exam_id, 'practical') : 0;
            $sub_total = $c + $o + $p;
        }

        $gpa = subjectGPA($sub_total, $sub['max_marks']);

        if (($sub['type'] ?? 'Compulsory') === 'Compulsory') {
            $compulsory_gpa_total += $gpa;
            $compulsory_gpa_subjects++;
        } elseif (($sub['type'] ?? 'Optional') === 'Optional') {
            $optional_gpas[] = $gpa;
        }
    }

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
    if ($students_data[$student_id]['fail_count'] > 0) $final_gpa = 0.00;

    $students_data[$student_id]['gpa'] = number_format(min($final_gpa, 5.00), 2);
    
    if ($students_data[$student_id]['fail_count'] > 0) {
        $students_data[$student_id]['status'] = 'Failed';
        $failed_students++;
    } else {
        $passed_students++;
    }

    // GPA ডিস্ট্রিবিউশন
    $gpa_value = (float)$students_data[$student_id]['gpa'];
    if ($gpa_value == 5.00) {
        $gpa_distribution['5.00']++;
    } elseif ($gpa_value >= 4.00) {
        $gpa_distribution['4.00-4.99']++;
    } elseif ($gpa_value >= 3.50) {
        $gpa_distribution['3.50-3.99']++;
    } elseif ($gpa_value >= 3.00) {
        $gpa_distribution['3.00-3.49']++;
    } elseif ($gpa_value >= 2.00) {
        $gpa_distribution['2.00-2.99']++;
    } elseif ($gpa_value >= 1.00) {
        $gpa_distribution['1.00-1.99']++;
    } else {
        $gpa_distribution['0.00']++;
    }
}

// পাসের হার গণনা
if ($total_students > 0) {
    $pass_rate = round(($passed_students / $total_students) * 100, 2);
}

// মেরিট পজিশন নির্ধারণ
usort($students_data, function ($a, $b) {
    if ($a['fail_count'] == $b['fail_count']) {
        return $b['total_marks'] <=> $a['total_marks'];
    }
    return $a['fail_count'] <=> $b['fail_count'];
});

$position = 1;
foreach ($students_data as &$student) {
    $student['position'] = $position++;
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>পরিসংখ্যান রিপোর্ট</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @media print {
            body {
                font-size: 11pt;
                padding: 0;
                margin: 0;
                background: white;
                color: black;
            }
            .no-print, .btn { 
                display: none !important; 
            }
            .page-break { 
                page-break-after: always;
                break-after: page;
            }
            .container-fluid {
                width: 100%;
                padding: 0 10px;
            }
            .stat-card {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            table {
                page-break-inside: auto;
                break-inside: auto;
            }
            tr {
                page-break-inside: avoid;
                break-inside: avoid;
            }
            .table-responsive {
                overflow: visible !important;
            }
            .chart-container {
                height: 250px !important;
            }
        }
        
        body {
            font-family: 'SolaimanLipi', 'Bangla', Arial, sans-serif;
            background-color: #f5f7fa;
        }
        .stat-card {
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #e0e0e0;
            background: white;
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .stat-card h5 {
            font-size: 0.95rem;
            font-weight: 600;
            border-bottom: 1px dashed #ddd;
            padding-bottom: 8px;
            margin-bottom: 12px;
            color: #444;
        }
        .stat-value {
            font-size: 1.6rem;
            font-weight: 700;
            text-align: center;
            margin: 8px 0;
        }
        .stat-label {
            text-align: center;
            font-size: 0.85rem;
            color: #666;
        }
        .pass-rate {
            color: #2e7d32;
        }
        .fail-rate {
            color: #c62828;
        }
        .chart-container {
            position: relative;
            height: 280px;
            margin-bottom: 20px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            border: 1px solid #e0e0e0;
        }
        .table {
            font-size: 0.9rem;
        }
        .table th {
            background-color: #3f51b5;
            color: white;
            font-weight: 500;
            padding: 10px 8px;
        }
        .table td {
            padding: 8px;
            vertical-align: middle;
        }
        .subject-pass-rate {
            font-weight: 600;
            color: #2e7d32;
        }
        .subject-fail-rate {
            font-weight: 600;
            color: #c62828;
        }
        .header {
            background-color: #3f51b5;
            padding: 15px 0;
            margin-bottom: 25px;
            color: white;
            text-align: center;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .institute-info h4 {
            margin-bottom: 5px;
            font-weight: 600;
            font-size: 1.4rem;
        }
        .institute-info p {
            margin-bottom: 5px;
            color: #e0e0e0;
            font-size: 0.95rem;
        }
        .report-title {
            font-weight: 600;
            font-size: 1.2rem;
            margin: 15px 0;
            padding: 8px;
            background-color: rgba(255,255,255,0.2);
            border-radius: 5px;
            display: inline-block;
        }
        .badge-pass {
            background-color: #2e7d32;
            padding: 5px 8px;
            font-size: 0.8rem;
        }
        .badge-fail {
            background-color: #c62828;
            padding: 5px 8px;
            font-size: 0.8rem;
        }
        .section-title {
            color: #3f51b5;
            font-weight: 600;
            margin: 20px 0 15px;
            padding-bottom: 5px;
            border-bottom: 2px solid #3f51b5;
            font-size: 1.1rem;
        }
        .print-header {
            display: none;
        }
        @media print {
            .print-header {
                display: block;
                text-align: center;
                margin-bottom: 15px;
            }
            .print-header h4 {
                font-size: 1.2rem;
                margin: 0;
                padding: 0;
            }
            .print-header p {
                font-size: 0.9rem;
                margin: 3px 0;
                padding: 0;
            }
            .header {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="institute-info">
            <h4><?= $institute_name ?></h4>
            <p><?= $institute_address ?></p>
            <div class="report-title"><?= $exam ?> - <?= $class ?> (<?= $year ?>) পরিসংখ্যান রিপোর্ট</div>
        </div>
    </div>

    <div class="print-header">
        <h4><?= $institute_name ?></h4>
        <p><?= $institute_address ?></p>
        <p><?= $exam ?> - <?= $class ?> (<?= $year ?>) পরিসংখ্যান রিপোর্ট</p>
    </div>

    <div class="container-fluid" style="max-width: 1200px;">
        <div class="row mb-3 no-print">
            <div class="col-12 text-center">
                <button onclick="window.print()" class="btn btn-primary btn-sm">
                    <i class="bi bi-printer"></i> প্রিন্ট করুন
                </button>
                <button onclick="window.close()" class="btn btn-danger btn-sm ms-2">
                    <i class="bi bi-x-circle"></i> বন্ধ করুন
                </button>
            </div>
        </div>

        <!-- সার্বিক পরিসংখ্যান -->
        <div class="row mb-3">
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <h5><i class="bi bi-people-fill text-primary"></i> মোট পরীক্ষার্থী</h5>
                    <div class="stat-value text-primary"><?= $total_students ?></div>
                    <div class="stat-label">জন</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <h5><i class="bi bi-check-circle-fill text-success"></i> পাস করেছে</h5>
                    <div class="stat-value pass-rate"><?= $passed_students ?></div>
                    <div class="stat-label">জন (<?= $pass_rate ?>%)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <h5><i class="bi bi-x-circle-fill text-danger"></i> ফেল করেছে</h5>
                    <div class="stat-value fail-rate"><?= $failed_students ?></div>
                    <div class="stat-label">জন (<?= 100 - $pass_rate ?>%)</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card">
                    <h5><i class="bi bi-graph-up-arrow text-info"></i> পাসের হার</h5>
                    <div class="stat-value pass-rate"><?= $pass_rate ?>%</div>
                    <div class="stat-label">সর্বমোট</div>
                </div>
            </div>
        </div>

        <!-- পাই চার্ট -->
        <div class="row mb-3 page-break">
            <div class="col-md-6">
                <div class="chart-container">
                    <canvas id="passFailChart"></canvas>
                </div>
            </div>
            <div class="col-md-6">
                <div class="chart-container">
                    <canvas id="gpaDistributionChart"></canvas>
                </div>
            </div>
        </div>

        <!-- বিষয়ভিত্তিক পরিসংখ্যান -->
        <div class="row mb-3">
            <div class="col-12">
                <h5 class="section-title"><i class="bi bi-book-half"></i> বিষয়ভিত্তিক পরিসংখ্যান</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th width="25%">বিষয়ের নাম</th>
                                <th width="12%">পাস করেছে</th>
                                <th width="12%">ফেল করেছে</th>
                                <th width="13%">পাসের হার</th>
                                <th width="12%">সর্বোচ্চ</th>
                                <th width="12%">সর্বনিম্ন</th>
                                <th width="14%">গড় নম্বর</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subject_stats as $code => $subject): 
                                $total = $subject['passed'] + $subject['failed'];
                                $pass_rate = $total > 0 ? round(($subject['passed'] / $total) * 100, 2) : 0;
                                $avg_marks = $total > 0 ? round($subject['total_marks'] / $total, 2) : 0;
                            ?>
                                <tr>
                                    <td><?= $subject['name'] ?></td>
                                    <td class="text-center"><?= $subject['passed'] ?></td>
                                    <td class="text-center"><?= $subject['failed'] ?></td>
                                    <td class="text-center <?= $pass_rate < 50 ? 'subject-fail-rate' : 'subject-pass-rate' ?>">
                                        <?= $pass_rate ?>%
                                    </td>
                                    <td class="text-center"><?= $subject['max_marks'] ?></td>
                                    <td class="text-center"><?= $subject['min_marks'] ?></td>
                                    <td class="text-center"><?= $avg_marks ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- GPA ডিস্ট্রিবিউশন -->
        <div class="row mb-3 page-break">
            <div class="col-12">
                <h5 class="section-title"><i class="bi bi-bar-chart-line"></i> GPA বন্টন</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th width="30%">GPA রেঞ্জ</th>
                                <th width="35%">ছাত্র/ছাত্রী সংখ্যা</th>
                                <th width="35%">শতকরা হার</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gpa_distribution as $range => $count): 
                                $percentage = $total_students > 0 ? round(($count / $total_students) * 100, 2) : 0;
                            ?>
                                <tr>
                                    <td><?= $range ?></td>
                                    <td>
                                        <div class="progress" style="height: 20px;">
                                            <div class="progress-bar 
                                                <?= $range == '5.00' ? 'bg-success' : '' ?>
                                                <?= $range == '4.00-4.99' ? 'bg-info' : '' ?>
                                                <?= $range == '3.50-3.99' ? 'bg-primary' : '' ?>
                                                <?= $range == '3.00-3.49' ? 'bg-warning' : '' ?>
                                                <?= $range == '2.00-2.99' ? 'bg-secondary' : '' ?>
                                                <?= $range == '1.00-1.99' ? 'bg-danger' : '' ?>
                                                <?= $range == '0.00' ? 'bg-dark' : '' ?>"
                                                role="progressbar" style="width: <?= $percentage ?>%" 
                                                aria-valuenow="<?= $percentage ?>" aria-valuemin="0" aria-valuemax="100">
                                                <?= $count ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= $percentage ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- টপ পারফর্মার্স -->
        <div class="row mb-3">
            <div class="col-12">
                <h5 class="section-title"><i class="bi bi-trophy"></i> শীর্ষ ১০ শিক্ষার্থী</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th width="10%">মর্যাদাক্রম</th>
                                <th width="15%">রোল নং</th>
                                <th width="30%">নাম</th>
                                <th width="15%">মোট নম্বর</th>
                                <th width="15%">GPA</th>
                                <th width="15%">স্ট্যাটাস</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $top_students = array_slice($students_data, 0, 10);
                            foreach ($top_students as $student): 
                                $status_class = $student['status'] === 'Passed' ? 'badge-pass' : 'badge-fail';
                            ?>
                                <tr>
                                    <td class="text-center"><?= $student['position'] ?></td>
                                    <td class="text-center"><?= $student['roll'] ?></td>
                                    <td><?= $student['name'] ?></td>
                                    <td class="text-center"><?= $student['total_marks'] ?></td>
                                    <td class="text-center"><?= $student['gpa'] ?></td>
                                    <td class="text-center">
                                        <span class="badge rounded-pill <?= $status_class ?>">
                                            <?= $student['status'] ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        // পাস-ফেল চার্ট
        const passFailCtx = document.getElementById('passFailChart').getContext('2d');
        const passFailChart = new Chart(passFailCtx, {
            type: 'doughnut',
            data: {
                labels: ['পাস করেছে (<?= $passed_students ?>)', 'ফেল করেছে (<?= $failed_students ?>)'],
                datasets: [{
                    data: [<?= $passed_students ?>, <?= $failed_students ?>],
                    backgroundColor: ['#2e7d32', '#c62828'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'পাস-ফেল পরিসংখ্যান (মোট: <?= $total_students ?>)',
                        font: {
                            size: 14
                        },
                        padding: {
                            top: 10,
                            bottom: 15
                        }
                    },
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 15,
                            usePointStyle: true,
                            pointStyle: 'circle'
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const percentage = Math.round((value / <?= $total_students ?>) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });

        // GPA ডিস্ট্রিবিউশন চার্ট
        const gpaDistCtx = document.getElementById('gpaDistributionChart').getContext('2d');
        const gpaDistChart = new Chart(gpaDistCtx, {
            type: 'bar',
            data: {
                labels: ['5.00', '4.00-4.99', '3.50-3.99', '3.00-3.49', '2.00-2.99', '1.00-1.99', '0.00'],
                datasets: [{
                    label: 'ছাত্র/ছাত্রী সংখ্যা',
                    data: [<?= implode(',', $gpa_distribution) ?>],
                    backgroundColor: [
                        '#2e7d32', '#0288d1', '#0097a7', '#fbc02d', '#ffa000', '#f57c00', '#c62828'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'GPA বন্টন (মোট: <?= $total_students ?>)',
                        font: {
                            size: 14
                        },
                        padding: {
                            top: 10,
                            bottom: 15
                        }
                    },
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.dataset.label || '';
                                const value = context.raw || 0;
                                const percentage = Math.round((value / <?= $total_students ?>) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'ছাত্র/ছাত্রী সংখ্যা',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            display: false
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'GPA রেঞ্জ',
                            font: {
                                weight: 'bold'
                            }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // Adjust charts for printing
        window.addEventListener('beforeprint', () => {
            passFailChart.resize(600, 400);
            gpaDistChart.resize(600, 400);
        });
    </script>
</body>
</html>