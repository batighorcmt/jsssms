<?php  
session_start();
if ($_SESSION['role'] !== 'super_admin') {
    die('Access Denied');
}
include '../config/db.php';

$student_id = $_POST['student_id'];
$exam_id = $_POST['exam_id'];
$class_id = $_POST['class_id'];
$year = $_POST['year'];

function getGPA($marks) {
    if ($marks >= 80) return 5.00;
    elseif ($marks >= 70) return 4.00;
    elseif ($marks >= 60) return 3.50;
    elseif ($marks >= 50) return 3.00;
    elseif ($marks >= 40) return 2.00;
    elseif ($marks >= 33) return 1.00;
    else return 0.00;
}

$subject_groups = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

// Student Info
$stmt = $conn->prepare("SELECT student_name, roll_no, class_id FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

$exam = $conn->query("SELECT exam_name FROM exams WHERE id = $exam_id")->fetch_assoc();
$class = $conn->query("SELECT class_name FROM classes WHERE id = $class_id")->fetch_assoc();

$sql = "SELECT es.*, s.subject_name, s.subject_code, s.type, s.id AS subject_id
        FROM exam_subjects es
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = $exam_id AND s.class_id = $class_id";
$subjects = $conn->query($sql);

$grouped_subjects = [];
$individual_subjects = [];
$total_marks = 0;
$total_gpa = 0;
$subject_count = 0;
$fail_count = 0;

while ($sub = $subjects->fetch_assoc()) {
    $code = $sub['subject_code'];
    $subject_id = $sub['subject_id'];

    $marks = $conn->query("SELECT creative_marks, objective_marks, practical_marks 
                           FROM marks 
                           WHERE student_id='$student_id' AND exam_id=$exam_id AND subject_id=$subject_id")->fetch_assoc();

    $c = $marks['creative_marks'] ?? 0;
    $o = $marks['objective_marks'] ?? 0;
    $p = $marks['practical_marks'] ?? 0;
    $t = $c + $o + $p;

    $key = null;
    foreach ($subject_groups as $group => $codes) {
        if (in_array($code, $codes)) {
            $key = $group;
            break;
        }
    }

    if ($key) {
        if (!isset($grouped_subjects[$key])) {
            $grouped_subjects[$key] = ['subject_name' => $key, 'creative' => 0, 'objective' => 0, 'practical' => 0, 'total' => 0, 'count' => 0, 'subs' => []];
        }
        $grouped_subjects[$key]['creative'] += $c;
        $grouped_subjects[$key]['objective'] += $o;
        $grouped_subjects[$key]['practical'] += $p;
        $grouped_subjects[$key]['total'] += $t;
        $grouped_subjects[$key]['count']++;
        $grouped_subjects[$key]['subs'][] = ['subject_name' => $sub['subject_name'], 'subject_code' => $code, 'c' => $c, 'o' => $o, 'p' => $p, 't' => $t, 'original' => $sub];
    } else {
        $individual_subjects[] = ['subject_name' => $sub['subject_name'], 'subject_code' => $code, 'c' => $c, 'o' => $o, 'p' => $p, 't' => $t, 'original' => $sub];
    }
}

?>
<div class="container">
    <h2>Transcript for <?php echo $student['student_name']; ?> (Roll: <?php echo $student['roll_no']; ?>)</h2>
    <p>Exam: <?php echo $exam['exam_name']; ?> | Class: <?php echo $class['class_name']; ?> | Year: <?php echo $year; ?></p>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Subject</th>
                <th>Creative Marks</th>
                <th>Objective Marks</th>
                <th>Practical Marks</th>
                <th>Total Marks</th>
                <th>GPA</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped_subjects as $group): ?>
                <tr>
                    <td colspan="6" class="bg-light"><strong><?php echo $group['subject_name']; ?></strong></td>
                </tr>
                <?php foreach ($group['subs'] as $sub): ?>
                    <?php
                    $total = $sub['c'] + $sub['o'] + $sub['p'];
                    $converted = ($total / 100) * 100; // Assuming total marks is 100
                    $gpa = getGPA($converted);
                    ?>
                    <tr>
                        <td><?php echo $sub['subject_name']; ?> (<?php echo $sub['subject_code']; ?>)</td>
                        <td><?php echo $sub['c']; ?></td>
                        <td><?php echo $sub['o']; ?></td>
                        <td><?php echo $sub['p']; ?></td>
                        <td><?php echo $total; ?></td>
                        <td><?php echo number_format($gpa, 2); ?></td>
                    </tr>
                    <?php
                    $total_marks += $total;
                    $total_gpa += $gpa;
                    if ($gpa == 0) {
                        $fail_count++;
                    }
                    ?>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <?php foreach ($individual_subjects as $sub): ?>
                <?php
                $total = $sub['c'] + $sub['o'] + $sub['p'];
                $converted = ($total / 100) * 100; // Assuming total marks is 100
                $gpa = getGPA($converted);  
                ?>
                <tr>
                    <td><?php echo $sub['subject_name']; ?> (<?php echo $sub['subject_code']; ?>)</td>
                    <td><?php echo $sub['c']; ?></td>
                    <td><?php echo $sub['o']; ?></td>
                    <td><?php echo $sub['p']; ?></td>
                    <td><?php echo $total; ?></td>
                    <td><?php echo number_format($gpa, 2); ?></td>
                </tr>
                <?php
                $total_marks += $total;
                $total_gpa += $gpa;
                if ($gpa == 0) {
                    $fail_count++;
                }
                ?>      
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td><strong>Total</strong></td>
                <td colspan="4"><?php echo $total_marks; ?></td>
                <td><?php echo number_format($total_gpa / count($grouped_subjects) + count($individual_subjects), 2); ?></td>
            </tr>
            <tr>
                <td colspan="5"><strong>Fail Subjects</strong></td>
                <td><?php echo $fail_count; ?></td>
            </tr>       
        </tfoot>
    </table>    
    <button class="btn btn-primary" onclick="printTranscript()">Print Transcript</button>
    <script>
        function printTranscript() {
            window.print();
        }
    </script>
</div>