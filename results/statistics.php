<?php
include '../config/db.php';

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get parameters
$exam_id = mysqli_real_escape_string($conn, $_GET['exam_id'] ?? '');
$class_id = mysqli_real_escape_string($conn, $_GET['class_id'] ?? '');
$year = mysqli_real_escape_string($conn, $_GET['year'] ?? '');

// Validate parameters
if (!$exam_id || !$class_id || !$year) {
    die("<div class='alert alert-danger'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল সঠিকভাবে প্রদান করুন</div>");
}

// Institution info
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";
$institute_logo = "../assets/logo.png";

// Get exam and class info
$exam_query = mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'");
$exam = mysqli_fetch_assoc($exam_query);
if (!$exam) die("<div class='alert alert-danger'>পরীক্ষার তথ্য পাওয়া যায়নি</div>");

$class_query = mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'");
$class = mysqli_fetch_assoc($class_query);
if (!$class) die("<div class='alert alert-danger'>শ্রেণির তথ্য পাওয়া যায়নি</div>");

// Fetch sections
$sections = [];
$section_result = mysqli_query($conn, "SELECT id, section_name FROM sections");
while ($row = mysqli_fetch_assoc($section_result)) {
    $sections[$row['id']] = $row['section_name'];
}

// Main analysis function
function analyzeResults($conn, $exam_id, $class_id, $year, $sections) {
    $analysis = [
        'total_students' => 0,
        'passed' => 0,
        'failed' => 0,
        'pass_rate' => 0,
        'gpa_distribution' => array_fill(0, 11, 0), // GPA 0.0 to 5.0 in 0.5 increments
        'subject_stats' => [],
        'section_stats' => [],
        'gpa_ranges' => [
            '5.00' => 0,
            '4.00-4.99' => 0,
            '3.50-3.99' => 0,
            '3.00-3.49' => 0,
            '2.00-2.99' => 0,
            '1.00-1.99' => 0,
            '0.00-0.99' => 0
        ]
    ];

    // Get all students
    $students_query = mysqli_query($conn, 
        "SELECT s.student_id, s.student_name, s.roll_no, s.section_id, sec.section_name 
         FROM students s
         LEFT JOIN sections sec ON s.section_id = sec.id
         WHERE s.class_id='$class_id' AND s.year='$year'"
    );

    while ($student = mysqli_fetch_assoc($students_query)) {
        $analysis['total_students']++;
        $student_id = $student['student_id'];
        $section_id = $student['section_id'];
        
        // Initialize section stats if not exists
        if (!isset($analysis['section_stats'][$section_id])) {
            $analysis['section_stats'][$section_id] = [
                'name' => $sections[$section_id] ?? 'N/A',
                'total' => 0,
                'passed' => 0,
                'failed' => 0,
                'gpa_total' => 0
            ];
        }
        $analysis['section_stats'][$section_id]['total']++;
        
        // Calculate student result (same function as marksheet)
        $result = calculateStudentResult($conn, $exam_id, $student_id);
        $gpa = $result['gpa'];
        $status = $result['status'];
        
        // Update GPA distribution
        $gpa_index = (int)($gpa * 2);
        if ($gpa_index >= 0 && $gpa_index <= 10) {
            $analysis['gpa_distribution'][$gpa_index]++;
        }
        
        // Update GPA ranges
        if ($gpa == 5.00) {
            $analysis['gpa_ranges']['5.00']++;
        } elseif ($gpa >= 4.00) {
            $analysis['gpa_ranges']['4.00-4.99']++;
        } elseif ($gpa >= 3.50) {
            $analysis['gpa_ranges']['3.50-3.99']++;
        } elseif ($gpa >= 3.00) {
            $analysis['gpa_ranges']['3.00-3.49']++;
        } elseif ($gpa >= 2.00) {
            $analysis['gpa_ranges']['2.00-2.99']++;
        } elseif ($gpa >= 1.00) {
            $analysis['gpa_ranges']['1.00-1.99']++;
        } else {
            $analysis['gpa_ranges']['0.00-0.99']++;
        }
        
        // Update pass/fail counts
        if ($status === 'Passed') {
            $analysis['passed']++;
            $analysis['section_stats'][$section_id]['passed']++;
        } else {
            $analysis['failed']++;
            $analysis['section_stats'][$section_id]['failed']++;
        }
        
        // Update section GPA total
        $analysis['section_stats'][$section_id]['gpa_total'] += $gpa;
        
        // Update subject stats
        updateSubjectStats($conn, $analysis, $exam_id, $student_id, $status);
    }
    
    // Calculate pass rate
    if ($analysis['total_students'] > 0) {
        $analysis['pass_rate'] = round(($analysis['passed'] / $analysis['total_students']) * 100, 2);
        
        // Calculate average GPA per section
        foreach ($analysis['section_stats'] as &$section) {
            if ($section['total'] > 0) {
                $section['avg_gpa'] = round($section['gpa_total'] / $section['total'], 2);
            } else {
                $section['avg_gpa'] = 0;
            }
        }
    }
    
    return $analysis;
}

// Same function as in marksheet.php
function calculateStudentResult($conn, $exam_id, $student_id) {
    // Implement the same GPA calculation logic as your marksheet page
    // This should return ['gpa' => value, 'status' => 'Passed/Failed', 'fail_count' => number]
    
    // Sample implementation (replace with your actual logic):
    $gpa = 0;
    $status = 'Failed';
    $fail_count = 0;
    
    // Your actual GPA calculation logic here...
    
    return [
        'gpa' => $gpa,
        'status' => $status,
        'fail_count' => $fail_count
    ];
}

// Update subject-wise statistics
function updateSubjectStats($conn, &$analysis, $exam_id, $student_id, $student_status) {
    $marks_query = mysqli_query($conn, 
        "SELECT m.subject_id, s.subject_name, s.subject_code,
                m.creative_marks, m.objective_marks, m.practical_marks,
                es.creative_pass, es.objective_pass, es.practical_pass,
                es.total_marks as max_marks
         FROM marks m
         JOIN subjects s ON m.subject_id = s.id
         JOIN exam_subjects es ON m.subject_id = es.subject_id AND es.exam_id='$exam_id'
         WHERE m.exam_id='$exam_id' AND m.student_id='$student_id'"
    );
    
    while ($mark = mysqli_fetch_assoc($marks_query)) {
        $subject_code = $mark['subject_code'];
        $total_marks = $mark['creative_marks'] + $mark['objective_marks'] + $mark['practical_marks'];
        $total_pass = $mark['creative_pass'] + $mark['objective_pass'] + $mark['practical_pass'];
        $is_passed = ($total_marks >= $total_pass);
        
        // Initialize subject stats if not exists
        if (!isset($analysis['subject_stats'][$subject_code])) {
            $analysis['subject_stats'][$subject_code] = [
                'name' => $mark['subject_name'],
                'passed' => 0,
                'failed' => 0,
                'max_marks' => 0,
                'min_marks' => $mark['max_marks'],
                'total_marks' => 0,
                'count' => 0,
                'pass_rate' => 0,
                'avg_marks' => 0
            ];
        }
        
        // Update subject stats
        $analysis['subject_stats'][$subject_code]['total_marks'] += $total_marks;
        $analysis['subject_stats'][$subject_code]['count']++;
        
        if ($total_marks > $analysis['subject_stats'][$subject_code]['max_marks']) {
            $analysis['subject_stats'][$subject_code]['max_marks'] = $total_marks;
        }
        
        if ($total_marks < $analysis['subject_stats'][$subject_code]['min_marks']) {
            $analysis['subject_stats'][$subject_code]['min_marks'] = $total_marks;
        }
        
        if ($is_passed) {
            $analysis['subject_stats'][$subject_code]['passed']++;
        } else {
            $analysis['subject_stats'][$subject_code]['failed']++;
        }
    }
}

// Perform analysis
$analysis = analyzeResults($conn, $exam_id, $class_id, $year, $sections);

// Prepare data for charts
$gpa_labels = ['0.0', '0.5', '1.0', '1.5', '2.0', '2.5', '3.0', '3.5', '4.0', '4.5', '5.0'];
$gpa_data = $analysis['gpa_distribution'];

$section_labels = [];
$section_pass_rates = [];
$section_avg_gpa = [];

foreach ($analysis['section_stats'] as $section) {
    $section_labels[] = $section['name'];
    $section_pass_rates[] = ($section['total'] > 0) ? round(($section['passed'] / $section['total']) * 100) : 0;
    $section_avg_gpa[] = $section['avg_gpa'] ?? 0;
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>পরিসংখ্যান বিশ্লেষণ - <?= $institute_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @font-face {
            font-family: 'BenSenHandwriting';
            src: url('../assets/fonts/BenSenHandwriting.ttf') format('truetype');
        }
        
        body {
            font-family: 'BenSenHandwriting', 'Segoe UI', Tahoma, sans-serif;
            background-color: #f8f9fa;
        }
        .card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            border: none;
        }
        .card-header {
            background-color: #3498db;
            color: white;
            border-radius: 10px 10px 0 0 !important;
            font-weight: bold;
        }
        .stat-card {
            text-align: center;
            padding: 20px;
        }
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
        }
        .stat-label {
            font-size: 1rem;
            color: #6c757d;
        }
        .pass-rate {
            color: #28a745;
        }
        .fail-rate {
            color: #dc3545;
        }
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 20px;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        .table thead th {
            background-color: #3498db;
            color: white;
            vertical-align: middle;
        }
        .institute-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 2px solid #3498db;
        }
        .institute-header h4 {
            color: #2c3e50;
            font-weight: 700;
        }
        .institute-header img {
            max-height: 80px;
            width: auto;
            margin-bottom: 10px;
        }
        .progress {
            height: 25px;
        }
        .subject-row:hover {
            background-color: #f8f9fa;
        }
        .gpa-badge {
            font-size: 0.9rem;
            padding: 0.35rem 0.6rem;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="institute-header">
        <div class="d-flex align-items-center justify-content-center">
            <?php if(file_exists($institute_logo)): ?>
                <img src="<?= $institute_logo ?>" alt="Logo" class="me-3">
            <?php endif; ?>
            <div>
                <h4><?= $institute_name ?></h4>
                <p><?= $institute_address ?></p>
                <h4><?= $exam['exam_name'] ?> - <?= $class['class_name'] ?> (<?= $year ?>) পরিসংখ্যান বিশ্লেষণ</h4>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="row">
        <div class="col-md-3 mb-4">
            <div class="card stat-card">
                <div class="stat-value"><?= $analysis['total_students'] ?></div>
                <div class="stat-label">মোট পরীক্ষার্থী</div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card stat-card">
                <div class="stat-value pass-rate"><?= $analysis['passed'] ?></div>
                <div class="stat-label">পাস করেছে</div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card stat-card">
                <div class="stat-value fail-rate"><?= $analysis['failed'] ?></div>
                <div class="stat-label">ফেল করেছে</div>
            </div>
        </div>
        <div class="col-md-3 mb-4">
            <div class="card stat-card">
                <div class="stat-value"><?= $analysis['pass_rate'] ?>%</div>
                <div class="stat-label">পাশের হার</div>
            </div>
        </div>
    </div>

    <!-- GPA Distribution -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-graph-up"></i> জিপিএ বন্টন
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="gpaDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-percent"></i> জিপিএ রেঞ্জ অনুযায়ী শিক্ষার্থী
                </div>
                <div class="card-body">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>জিপিএ রেঞ্জ</th>
                                <th>শিক্ষার্থী সংখ্যা</th>
                                <th>শতকরা</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($analysis['gpa_ranges'] as $range => $count): ?>
                                <?php $percentage = $analysis['total_students'] > 0 ? round(($count / $analysis['total_students']) * 100, 2) : 0; ?>
                                <tr>
                                    <td><?= $range ?></td>
                                    <td><?= $count ?></td>
                                    <td>
                                        <div class="progress">
                                            <div class="progress-bar" role="progressbar" 
                                                 style="width: <?= $percentage ?>%" 
                                                 aria-valuenow="<?= $percentage ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="100">
                                                <?= $percentage ?>%
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Section-wise Performance -->
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-people-fill"></i> শাখাভিত্তিক পাশের হার
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="sectionPassRateChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-award"></i> শাখাভিত্তিক গড় জিপিএ
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="sectionGpaChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section-wise Statistics -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-table"></i> শাখাভিত্তিক পরিসংখ্যান
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>শাখা</th>
                            <th>মোট পরীক্ষার্থী</th>
                            <th>পাস করেছে</th>
                            <th>ফেল করেছে</th>
                            <th>পাশের হার</th>
                            <th>গড় জিপিএ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['section_stats'] as $section): ?>
                            <tr>
                                <td><?= $section['name'] ?></td>
                                <td><?= $section['total'] ?></td>
                                <td><?= $section['passed'] ?></td>
                                <td><?= $section['failed'] ?></td>
                                <td>
                                    <?php $pass_rate = $section['total'] > 0 ? round(($section['passed'] / $section['total']) * 100, 2) : 0; ?>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= $pass_rate ?>%" 
                                             aria-valuenow="<?= $pass_rate ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                            <?= $pass_rate ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-primary gpa-badge">
                                        <?= $section['avg_gpa'] ?? 0 ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Subject-wise Statistics -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-journal-bookmark-fill"></i> বিষয়ভিত্তিক পরিসংখ্যান
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover">
                    <thead>
                        <tr>
                            <th>বিষয়</th>
                            <th>পাস করেছে</th>
                            <th>ফেল করেছে</th>
                            <th>পাশের হার</th>
                            <th>সর্বোচ্চ নম্বর</th>
                            <th>সর্বনিম্ন নম্বর</th>
                            <th>গড় নম্বর</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($analysis['subject_stats'] as $subject): ?>
                            <?php 
                                $pass_rate = $subject['count'] > 0 ? round(($subject['passed'] / $subject['count']) * 100, 2) : 0;
                                $avg_marks = $subject['count'] > 0 ? round($subject['total_marks'] / $subject['count'], 2) : 0;
                            ?>
                            <tr class="subject-row">
                                <td><?= $subject['name'] ?></td>
                                <td><?= $subject['passed'] ?></td>
                                <td><?= $subject['failed'] ?></td>
                                <td>
                                    <div class="progress">
                                        <div class="progress-bar" role="progressbar" 
                                             style="width: <?= $pass_rate ?>%" 
                                             aria-valuenow="<?= $pass_rate ?>" 
                                             aria-valuemin="0" 
                                             aria-valuemax="100">
                                            <?= $pass_rate ?>%
                                        </div>
                                    </div>
                                </td>
                                <td><?= $subject['max_marks'] ?></td>
                                <td><?= $subject['min_marks'] ?></td>
                                <td><?= $avg_marks ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center text-muted mb-4">
        <p>প্রস্তুতকারক: বাতিঘর কম্পিউটার'স, জোড়পুকুরিয়া, গাংনী, মেহেরপুর</p>
        <p>প্রিন্টের তারিখ: <?= date('d/m/Y') ?></p>
    </div>
</div>

<script>
    // GPA Distribution Chart
    const gpaCtx = document.getElementById('gpaDistributionChart').getContext('2d');
    const gpaChart = new Chart(gpaCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($gpa_labels) ?>,
            datasets: [{
                label: 'শিক্ষার্থী সংখ্যা',
                data: <?= json_encode($gpa_data) ?>,
                backgroundColor: 'rgba(54, 162, 235, 0.7)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'শিক্ষার্থী সংখ্যা'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'জিপিএ'
                    }
                }
            }
        }
    });

    // Section Pass Rate Chart
    const sectionPassCtx = document.getElementById('sectionPassRateChart').getContext('2d');
    const sectionPassChart = new Chart(sectionPassCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($section_labels) ?>,
            datasets: [{
                label: 'পাশের হার (%)',
                data: <?= json_encode($section_pass_rates) ?>,
                backgroundColor: 'rgba(75, 192, 192, 0.7)',
                borderColor: 'rgba(75, 192, 192, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 100,
                    title: {
                        display: true,
                        text: 'পাশের হার (%)'
                    }
                }
            }
        }
    });

    // Section GPA Chart
    const sectionGpaCtx = document.getElementById('sectionGpaChart').getContext('2d');
    const sectionGpaChart = new Chart(sectionGpaCtx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($section_labels) ?>,
            datasets: [{
                label: 'গড় জিপিএ',
                data: <?= json_encode($section_avg_gpa) ?>,
                backgroundColor: 'rgba(153, 102, 255, 0.7)',
                borderColor: 'rgba(153, 102, 255, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 5,
                    title: {
                        display: true,
                        text: 'গড় জিপিএ'
                    }
                }
            }
        }
    });
</script>
</body>
</html>