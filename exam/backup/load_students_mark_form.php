<?php
session_start();
include '../config/db.php';

if (!isset($_POST['exam_id'], $_POST['class_id'], $_POST['year'])) {
    echo '<div class="alert alert-danger">সঠিক ডাটা পাঠানো হয়নি।</div>';
    exit;
}

$exam_id = intval($_POST['exam_id']);
$class_id = intval($_POST['class_id']);
$year = intval($_POST['year']);

// ছাত্রদের ডাটা আনছি
$sqlStudents = "SELECT id, student_name, father_name, roll_no FROM students WHERE class_id = ? AND year = ? ORDER BY roll_no ASC";
$stmt1 = $conn->prepare($sqlStudents);
$stmt1->bind_param('ii', $class_id, $year);
$stmt1->execute();
$resultStudents = $stmt1->get_result();

$students = [];
while ($row = $resultStudents->fetch_assoc()) {
    $students[] = $row;
}

if (count($students) === 0) {
    echo '<div class="alert alert-warning">এই শ্রেণি ও সালের কোন ছাত্র পাওয়া যায়নি।</div>';
    exit;
}

// বিষয়গুলোর ডাটা আনছি
$sqlSubjects = "
    SELECT es.id AS exam_subject_id, s.id AS subject_id, s.subject_name, es.creative_marks, es.objective_marks
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    JOIN exams e ON es.exam_id = e.id
    WHERE es.exam_id = ? AND e.class_id = ?
";
$stmt2 = $conn->prepare($sqlSubjects);
$stmt2->bind_param("ii", $exam_id, $class_id);
$stmt2->execute();
$resultSubjects = $stmt2->get_result();

if ($resultSubjects->num_rows == 0) {
    echo '<div class="alert alert-warning">এই পরীক্ষার জন্য কোন বিষয় নির্ধারিত হয়নি।</div>';
    exit;
}

// মার্কস টেবিল থেকে সব মার্কস নিয়ে নিচ্ছি
$allMarks = [];
$sqlMarks = "SELECT student_id, subject_id, creative_marks, objective_marks FROM marks WHERE exam_id = ?";
$stmt3 = $conn->prepare($sqlMarks);
$stmt3->bind_param('i', $exam_id);
$stmt3->execute();
$resultMarks = $stmt3->get_result();
while ($markRow = $resultMarks->fetch_assoc()) {
    $allMarks[$markRow['student_id']][$markRow['subject_id']] = $markRow;
}
?>

<form id="marksEntryForm">
<?php while ($subject = $resultSubjects->fetch_assoc()):
    $subject_id = $subject['subject_id'];
    $subject_name = $subject['subject_name'];
    $creativeMax = $subject['creative_marks'];
    $objectiveMax = $subject['objective_marks'];
?>
<h5 class="mt-4"><?= htmlspecialchars($subject_name) ?> (সৃজনশীল <?= $creativeMax ?> / নৈর্ব্যক্তিক <?= $objectiveMax ?>)</h5>
<table class="table table-bordered table-sm">
    <thead>
        <tr>
            <th>ক্রমিক</th>
            <th>রোল নং</th>
            <th>নাম</th>
            <th>পিতার নাম</th>
            <th>সৃজনশীল</th>
            <th>নৈর্ব্যক্তিক</th>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; foreach ($students as $student): 
            $studId = $student['id'];
            $creativeMark = isset($allMarks[$studId][$subject_id]) ? $allMarks[$studId][$subject_id]['creative_marks'] : 0;
            $objectiveMark = isset($allMarks[$studId][$subject_id]) ? $allMarks[$studId][$subject_id]['objective_marks'] : 0;
        ?>
        <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($student['roll_no']) ?></td>
            <td><?= htmlspecialchars($student['student_name']) ?></td>
            <td><?= htmlspecialchars($student['father_name']) ?></td>
            <td>
                <input type="number"
                       min="0" max="<?= $creativeMax ?>" step="0.01"
                       class="form-control form-control-sm mark-input"
                       data-student-id="<?= $studId ?>"
                       data-subject-id="<?= $subject_id ?>"
                       data-field="creative_marks"
                       value="<?= $creativeMark ?>"
                       onblur="saveMark(this)">
            </td>
            <td>
                <input type="number"
                       min="0" max="<?= $objectiveMax ?>" step="0.01"
                       class="form-control form-control-sm mark-input"
                       data-student-id="<?= $studId ?>"
                       data-subject-id="<?= $subject_id ?>"
                       data-field="objective_marks"
                       value="<?= $objectiveMark ?>"
                       onblur="saveMark(this)">
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endwhile; ?>
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function saveMark(input) {
    var student_id = input.getAttribute('data-student-id');
    var subject_id = input.getAttribute('data-subject-id');
    var field = input.getAttribute('data-field');
    var value = input.value;

    var min = parseFloat(input.min);
    var max = parseFloat(input.max);
    var valFloat = parseFloat(value);

    if (isNaN(valFloat) || valFloat < min || valFloat > max) {
        alert('নম্বর অবশ্যই ' + min + ' থেকে ' + max + ' এর মধ্যে হতে হবে।');
        input.value = 0;
        input.focus();
        return;
    }

    $.post('save_mark.php', {
        student_id: student_id,
        subject_id: subject_id,
        field: field,
        value: value,
        exam_id: <?= $exam_id ?>
    }, function(response) {
        // প্রয়োজন হলে response ব্যবহার করা যাবে
    });
}
</script>
