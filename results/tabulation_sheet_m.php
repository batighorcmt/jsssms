
<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    die("Access Denied");
}

include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? null;
$class_id = $_GET['class_id'] ?? null;
$year = $_GET['year'] ?? null;

if (!$exam_id || !$class_id || !$year) {
    die("Invalid request");
}

// Helper functions
function getGPA($percentage) {
    if ($percentage >= 80) return 5.00;
    elseif ($percentage >= 70) return 4.00;
    elseif ($percentage >= 60) return 3.50;
    elseif ($percentage >= 50) return 3.00;
    elseif ($percentage >= 40) return 2.00;
    elseif ($percentage >= 33) return 1.00;
    return 0.00;
}

function getGrade($gpa) {
    if ($gpa == 5.00) return "A+";
    elseif ($gpa >= 4.00) return "A";
    elseif ($gpa >= 3.50) return "A-";
    elseif ($gpa >= 3.00) return "B";
    elseif ($gpa >= 2.00) return "C";
    elseif ($gpa >= 1.00) return "D";
    return "F";
}

function isGroupMergedSubject($code) {
    return in_array($code, [101, 102, 107, 108]);
}

// Fetch students
$sql = "SELECT DISTINCT s.student_id, s.student_name, s.roll_no
        FROM students s
        JOIN marks m ON s.student_id = m.student_id
        WHERE s.class = ? AND s.year = ?
        ORDER BY s.roll_no ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("is", $class_id, $year);
$stmt->execute();
$result = $stmt->get_result();

$students = [];
while ($row = $result->fetch_assoc()) {
    $students[$row['student_id']] = [
        'name' => $row['student_name'],
        'roll' => $row['roll_no'],
        'marks' => [],
        'merged' => []
    ];
}

// Fetch marks
$sql = "SELECT m.student_id, m.subject_id, s.subject_code, s.subject_name,
               es.total_marks, es.creative_pass, es.objective_pass, es.practical_pass,
               m.creative_marks, m.objective_marks, m.practical_marks
        FROM marks m
        JOIN subjects s ON m.subject_id = s.id
        JOIN exam_subjects es ON m.subject_id = es.subject_id AND es.exam_id = ?
        WHERE m.exam_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $exam_id, $exam_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $sid = $row['student_id'];
    $code = $row['subject_code'];

    $total = $row['creative_marks'] + $row['objective_marks'] + $row['practical_marks'];
    $converted = ($total / $row['total_marks']) * 100;
    $gpa = getGPA($converted);
    $status = 'Passed';

    if ($class_id >= 9) {
        if ($row['creative_marks'] < $row['creative_pass'] ||
            $row['objective_marks'] < $row['objective_pass'] ||
            $row['practical_marks'] < $row['practical_pass']) {
            $status = 'Failed';
            $gpa = 0.00;
        }
    } else {
        if ($converted < 33) {
            $status = 'Failed';
            $gpa = 0.00;
        }
    }

    $students[$sid]['marks'][$code] = [
        'name' => $row['subject_name'],
        'code' => $code,
        'total' => $total,
        'converted' => $converted,
        'gpa' => $gpa,
        'status' => $status
    ];

    // Handle merged
    if (in_array($code, [101, 102])) {
        $students[$sid]['merged']['BAN'] ??= ['total' => 0, 'marks' => [], 'full' => 0];
        $students[$sid]['merged']['BAN']['total'] += $total;
        $students[$sid]['merged']['BAN']['marks'][] = $total;
        $students[$sid]['merged']['BAN']['full'] += $row['total_marks'];
    }

    if (in_array($code, [107, 108])) {
        $students[$sid]['merged']['ENG'] ??= ['total' => 0, 'marks' => [], 'full' => 0];
        $students[$sid]['merged']['ENG']['total'] += $total;
        $students[$sid]['merged']['ENG']['marks'][] = $total;
        $students[$sid]['merged']['ENG']['full'] += $row['total_marks'];
    }
}

// Output table
echo "<table border='1' cellpadding='4' cellspacing='0'>";
echo "<tr><th>Roll</th><th>Name</th><th>Subject</th><th>Marks</th><th>GPA</th><th>Status</th></tr>";

foreach ($students as $sid => $data) {
    $roll = $data['roll'];
    $name = $data['name'];

    foreach ($data['marks'] as $code => $info) {
        echo "<tr>
            <td>{$roll}</td>
            <td>{$name}</td>
            <td>{$info['name']} ({$code})</td>
            <td>{$info['total']}</td>
            <td>{$info['gpa']}</td>
            <td>{$info['status']}</td>
        </tr>";
    }

    foreach ($data['merged'] as $group => $m) {
        $converted = ($m['total'] / $m['full']) * 100;
        $gpa = getGPA($converted);
        $status = $gpa > 0 ? 'Passed' : 'Failed';
        echo "<tr style='background:#eef'>
            <td>{$roll}</td>
            <td>{$name}</td>
            <td><strong>{$group} (Merged)</strong></td>
            <td><strong>{$m['total']}</strong></td>
            <td><strong>{$gpa}</strong></td>
            <td><strong>{$status}</strong></td>
        </tr>";
    }
}
echo "</table>";
?>
