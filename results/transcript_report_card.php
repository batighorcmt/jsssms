<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    exit('Access Denied');
}
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
$exam_id = $_GET['exam_id'] ?? '';

if (!$student_id || !$exam_id) {
    exit('Missing Parameters');
}

// Student Info
$stmt = $conn->prepare("SELECT s.*, c.class_name, g.group_name FROM students s
                        JOIN classes c ON s.class_id = c.id
                        LEFT JOIN groups g ON s.group_id = g.id
                        WHERE s.student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    exit('Student not found');
}

// Subjects & Marks
$stmt = $conn->prepare("SELECT sub.subject_name, sub.subject_code, sub.type,
    es.creative_marks AS creative_max, es.objective_marks AS objective_max, es.practical_marks AS practical_max,
    m.creative_marks, m.objective_marks, m.practical_marks
    FROM marks m
    JOIN exam_subjects es ON m.subject_id = es.subject_id AND es.exam_id = ?
    JOIN subjects sub ON m.subject_id = sub.id
    WHERE m.student_id = ? AND m.exam_id = ?");
$stmt->bind_param("isi", $exam_id, $student_id, $exam_id);
$stmt->execute();
$subjects = $stmt->get_result();

// GPA Calculation
function calculateGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}

$total_marks = 0;
$total_gpa = 0;
$fail_count = 0;
$subject_count = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Card</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Kalpurush', sans-serif; }
        .header { text-align: center; border-bottom: 3px solid #333; margin-bottom: 20px; padding-bottom: 10px; }
        .school-name { font-size: 26px; color: #2c3e50; font-weight: bold; }
        .school-info { font-size: 14px; color: #555; }
        .report-title { background-color: #f39c12; color: white; padding: 5px; font-size: 20px; margin-top: 10px; }
        .table th, .table td { vertical-align: middle !important; }
        .footer-note { margin-top: 30px; font-size: 13px; color: #666; text-align: center; }
        .print-btn { position: fixed; top: 20px; right: 30px; z-index: 9999; }
        .school-logo { height: 80px; }
        @media print {
            .print-btn { display: none; }
        }
    </style>
</head>
<body>
<button class="btn btn-primary print-btn" onclick="window.print()">
    <i class="bi bi-printer"></i> Print
</button>

<div class="container mt-4" id="printArea">
    <div class="header d-flex justify-content-between align-items-center">
        <img src="../assets/logo.png" class="school-logo" alt="Logo">
        <div class="text-center flex-grow-1">
            <div class="school-name">Jorepukuria Secondary School</div>
            <div class="school-info">Gangni, Meherpur</div>
            <div class="report-title">Academic Transcript</div>
        </div>
        <div style="width:80px;"></div>
    </div>

    <div class="row mb-3">
        <div class="col-md-6">
            <strong>Name:</strong> <?= htmlspecialchars($student['student_name']) ?><br>
            <strong>Student ID:</strong> <?= htmlspecialchars($student['student_id']) ?><br>
            <strong>Class:</strong> <?= htmlspecialchars($student['class_name']) ?>
        </div>
        <div class="col-md-6">
            <strong>Group:</strong> <?= htmlspecialchars($student['group_name']) ?><br>
            <strong>Roll No:</strong> <?= htmlspecialchars($student['roll_no']) ?><br>
            <strong>Year:</strong> <?= htmlspecialchars($student['year']) ?>
        </div>
    </div>

    <table class="table table-bordered text-center">
        <thead class="table-primary">
            <tr>
                <th>Subject</th>
                <th>Creative</th>
                <th>Objective</th>
                <th>Practical</th>
                <th>Total</th>
                <th>GPA</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($row = $subjects->fetch_assoc()):
            $c = floatval($row['creative_marks']);
            $o = floatval($row['objective_marks']);
            $p = floatval($row['practical_marks']);
            $total = $c + $o + $p;
            $gpa = calculateGPA($total);
            $pass = true;

            if ($student['class_id'] >= 9) {
                if ($row['creative_max'] > 0 && $c < 8) $pass = false;
                if ($row['objective_max'] > 0 && $o < 8) $pass = false;
                if ($row['practical_max'] > 0 && $p < 10) $pass = false;
            } else {
                if ($total < 33 || $c == 0 || $o == 0) $pass = false;
            }

            if (!$pass) $fail_count++;
            else $total_gpa += $gpa;

            $total_marks += $total;
            $subject_count++;
        ?>
        <tr>
            <td><?= htmlspecialchars($row['subject_name']) ?></td>
            <td><?= $c ?></td>
            <td><?= $o ?></td>
            <td><?= $p ?></td>
            <td><?= $total ?></td>
            <td><?= number_format($gpa, 2) ?></td>
            <td class="<?= $pass ? 'text-success' : 'text-danger' ?>">
                <?= $pass ? 'Pass' : 'Fail' ?>
            </td>
        </tr>
        <?php endwhile; ?>
        </tbody>
        <tfoot class="table-warning">
            <tr>
                <th colspan="4">Total</th>
                <th><?= $total_marks ?></th>
                <th><?= ($fail_count == 0 && $subject_count > 0) ? number_format($total_gpa / $subject_count, 2) : '0.00' ?></th>
                <th><?= $fail_count == 0 ? 'Pass' : 'Fail' ?></th>
            </tr>
        </tfoot>
    </table>

    <div class="footer-note">This transcript is computer-generated and does not require signature.</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.js"></script>
</body>
</html>
