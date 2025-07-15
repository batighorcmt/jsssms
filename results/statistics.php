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

// সেকশন ডাটা fetch
$sections = [];
$section_result = mysqli_query($conn, "SELECT id, section_name FROM sections");
while ($row = mysqli_fetch_assoc($section_result)) {
    $sections[$row['id']] = $row['section_name'];
}

// মার্কশিট বিশ্লেষণ ফাংশন
function analyzeMarksheets($conn, $exam_id, $class_id, $year, $sections) {
    $analysis = [
        'total_students' => 0,
        'passed' => 0,
        'failed' => 0,
        'pass_rate' => 0,
        'gpa_distribution' => [],
        'subject_stats' => [],
        'section_stats' => []
    ];

    // GPA ডিস্ট্রিবিউশন ইনিশিয়ালাইজ
    for ($i = 0; $i <= 5; $i += 0.5) {
        $analysis['gpa_distribution'][(string)$i] = 0;
    }

    // শিক্ষার্থী ডাটা fetch
    $students = [];
    $query = "SELECT s.*, sec.section_name 
              FROM students s
              LEFT JOIN sections sec ON s.section_id = sec.id
              WHERE s.class_id = $class_id AND s.year = $year";
    $result = mysqli_query($conn, $query);

    while ($stu = mysqli_fetch_assoc($result)) {
        $analysis['total_students']++;
        
        // প্রতিটি শিক্ষার্থীর মার্কশিট বিশ্লেষণ
        $student_id = $stu['student_id'];
        $section_id = $stu['section_id'];
        
        // সেকশন স্ট্যাট ইনিশিয়ালাইজ (যদি না থাকে)
        if (!isset($analysis['section_stats'][$section_id])) {
            $analysis['section_stats'][$section_id] = [
                'name' => $sections[$section_id] ?? 'N/A',
                'total' => 0,
                'passed' => 0,
                'failed' => 0
            ];
        }
        $analysis['section_stats'][$section_id]['total']++;
        
        // শিক্ষার্থীর বিষয়ভিত্তিক মার্কস fetch
        $marks_query = "SELECT m.*, s.subject_name, s.subject_code 
                        FROM marks m
                        JOIN subjects s ON m.subject_id = s.id
                        WHERE m.exam_id = $exam_id AND m.student_id = '$student_id'";
        $marks_result = mysqli_query($conn, $marks_query);
        
        $student_failed = false;
        $total_marks = 0;
        $compulsory_subjects = 0;
        $compulsory_gpa = 0;
        
        while ($mark = mysqli_fetch_assoc($marks_result)) {
            $subject_code = $mark['subject_code'];
            $total = $mark['creative_marks'] + $mark['objective_marks'] + $mark['practical_marks'];
            
            // বিষয়ভিত্তিক স্ট্যাট ইনিশিয়ালাইজ (যদি না থাকে)
            if (!isset($analysis['subject_stats'][$subject_code])) {
                $analysis['subject_stats'][$subject_code] = [
                    'name' => $mark['subject_name'],
                    'passed' => 0,
                    'failed' => 0,
                    'max_marks' => 0,
                    'min_marks' => 100,
                    'total_marks' => 0,
                    'count' => 0
                ];
            }
            
            // বিষয়ভিত্তিক স্ট্যাট আপডেট
            $analysis['subject_stats'][$subject_code]['total_marks'] += $total;
            $analysis['subject_stats'][$subject_code]['count']++;
            
            if ($total > $analysis['subject_stats'][$subject_code]['max_marks']) {
                $analysis['subject_stats'][$subject_code]['max_marks'] = $total;
            }
            
            if ($total < $analysis['subject_stats'][$subject_code]['min_marks']) {
                $analysis['subject_stats'][$subject_code]['min_marks'] = $total;
            }
            
            // পাস/ফেল চেক
            $pass_marks = 33; // ডিফল্ট পাস মার্ক (আপনার লজিক অনুযায়ী পরিবর্তন করুন)
            if ($total >= $pass_marks) {
                $analysis['subject_stats'][$subject_code]['passed']++;
            } else {
                $analysis['subject_stats'][$subject_code]['failed']++;
                $student_failed = true;
            }
        }
        
        // শিক্ষার্থীর স্ট্যাটাস আপডেট
        if ($student_failed) {
            $analysis['failed']++;
            $analysis['section_stats'][$section_id]['failed']++;
        } else {
            $analysis['passed']++;
            $analysis['section_stats'][$section_id]['passed']++;
        }
    }
    
    // পাশের হার ক্যালকুলেট
    if ($analysis['total_students'] > 0) {
        $analysis['pass_rate'] = round(($analysis['passed'] / $analysis['total_students']) * 100, 2);
    }
    
    return $analysis;
}

// ডেটা বিশ্লেষণ
$analysis = analyzeMarksheets($conn, $exam_id, $class_id, $year, $sections);
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>পরিসংখ্যান বিশ্লেষণ - <?= $institute_name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
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
            color: #2c3e50;
        }
        .stat-label {
            font-size: 1rem;
            color: #7f8c8d;
        }
        .pass-rate {
            color: #27ae60;
        }
        .fail-rate {
            color: #e74c3c;
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
    </style>
</head>
<body>
<div class="container py-4">
    <div class="institute-header">
        <h4><?= $institute_name ?></h4>
        <p><?= $institute_address ?></p>
        <h4><?= $exam ?> - <?= $class ?> (<?= $year ?>) পরিসংখ্যান বিশ্লেষণ</h4>
    </div>

    <!-- সারাংশ কার্ড -->
    <div class="row">
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="stat-value"><?= $analysis['total_students'] ?></div>
                <div class="stat-label">মোট পরীক্ষার্থী</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="stat-value pass-rate"><?= $analysis['passed'] ?></div>
                <div class="stat-label">পাস করেছে</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="stat-value fail-rate"><?= $analysis['failed'] ?></div>
                <div class="stat-label">ফেল করেছে</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card">
                <div class="stat-value"><?= $analysis['pass_rate'] ?>%</div>
                <div class="stat-label">পাশের হার</div>
            </div>
        </div>
    </div>

    <!-- পাই চার্ট - পাস/ফেল -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">পাস/ফেল অনুপাত</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="passFailChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- বার চার্ট - জিপিএ ডিস্ট্রিবিউশন -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">জিপিএ বন্টন</div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="gpaDistributionChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- বিষয়ভিত্তিক পরিসংখ্যান -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">বিষয়ভিত্তিক পরিসংখ্যান</div>
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
                                <?php foreach ($analysis['subject_stats'] as $code => $subject): ?>
                                <tr>
                                    <td><?= $subject['name'] ?></td>
                                    <td><?= $subject['passed'] ?></td>
                                    <td><?= $subject['failed'] ?></td>
                                    <td><?= $subject['count'] > 0 ? round(($subject['passed'] / $subject['count']) * 100, 2) : 0 ?>%</td>
                                    <td><?= $subject['max_marks'] ?></td>
                                    <td><?= $subject['min_marks'] ?></td>
                                    <td><?= $subject['count'] > 0 ? round($subject['total_marks'] / $subject['count'], 2) : 0 ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- শাখাভিত্তিক পরিসংখ্যান -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header">শাখাভিত্তিক পরিসংখ্যান</div>
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
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($analysis['section_stats'] as $section_id => $section): ?>
                                <tr>
                                    <td><?= $section['name'] ?></td>
                                    <td><?= $section['total'] ?></td>
                                    <td><?= $section['passed'] ?></td>
                                    <td><?= $section['failed'] ?></td>
                                    <td><?= $section['total'] > 0 ? round(($section['passed'] / $section['total']) * 100, 2) : 0 ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // পাস/ফেল চার্ট
    const passFailCtx = document.getElementById('passFailChart').getContext('2d');
    const passFailChart = new Chart(passFailCtx, {
        type: 'pie',
        data: {
            labels: ['পাস করেছে', 'ফেল করেছে'],
            datasets: [{
                data: [<?= $analysis['passed'] ?>, <?= $analysis['failed'] ?>],
                backgroundColor: ['#27ae60', '#e74c3c'],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });

    // জিপিএ ডিস্ট্রিবিউশন চার্ট
    const gpaDistributionCtx = document.getElementById('gpaDistributionChart').getContext('2d');
    const gpaDistributionChart = new Chart(gpaDistributionCtx, {
        type: 'bar',
        data: {
            labels: ['0', '1', '2', '3', '4', '5'],
            datasets: [{
                label: 'শিক্ষার্থী সংখ্যা',
                data: [10, 15, 20, 25, 15, 5], // এখানে আপনার ডেটা বসাতে হবে
                backgroundColor: '#3498db',
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
</script>
</body>
</html>