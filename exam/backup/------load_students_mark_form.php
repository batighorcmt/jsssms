<?php
// load_students_mark_form.php
session_start();
include '../config/db.php';

if (!isset($_POST['exam_id'], $_POST['class_id'], $_POST['year'])) {
    echo '<div class="alert alert-danger">সঠিক ডাটা পাঠানো হয়নি।</div>';
    exit;
}

$exam_id = intval($_POST['exam_id']);
$class_id = intval($_POST['class_id']);
$subject_id = intval($_POST['subject_id']);
$year = intval($_POST['year']);

// ১. শুধুমাত্র নির্দিষ্ট exam_id ও class_id এর subject গুলো নিয়ে আসছি
$sqlSubjects = "
    SELECT es.id AS subject_id, s.subject_name, es.creative_marks, es.objective_marks
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    JOIN exams e ON es.exam_id = e.id
    WHERE es.exam_id = ? AND e.class_id = ?
";
$stmt = $conn->prepare($sqlSubjects);
$stmt->bind_param("ii", $exam_id, $class_id);
$stmt->execute();
$resultSubjects = $stmt->get_result();

if ($resultSubjects->num_rows == 0) {
    echo '<div class="alert alert-warning">এই পরীক্ষার জন্য কোন বিষয় নির্ধারিত হয়নি।</div>';
    exit;
}

// যেহেতু একবারে একটি বিষয় নিয়ে কাজ করবে, প্রথম (একটি) বিষয় নিচ্ছি
$examSubject = $resultSubjects->fetch_assoc();
$subject_id = $examSubject['subject_id'];
$subject_name = $examSubject['subject_name'];
$creativeMax = $examSubject['creative_marks'];
$objectiveMax = $examSubject['objective_marks'];

// ২. ছাত্রদের ডাটা নিয়ে আসা
$sqlStudents = "SELECT id, student_name, father_name FROM students WHERE class_id = ? AND year = ? ORDER BY roll_no ASC";
$stmt2 = $conn->prepare($sqlStudents);
$stmt2->bind_param('ii', $class_id, $year);
$stmt2->execute();
$resultStudents = $stmt2->get_result();

if ($resultStudents->num_rows == 0) {
    echo '<div class="alert alert-warning">এই শ্রেণি ও সালের কোন ছাত্র পাওয়া যায়নি।</div>';
    exit;
}

// ৩. মার্কস টেবিল থেকে ছাত্র ও subject অনুযায়ী মার্কস নিয়ে আসা
$marks = [];
$sqlMarks = "SELECT student_id, creative_marks, objective_marks FROM marks WHERE exam_id = ? AND subject_id = ?";
$stmt3 = $conn->prepare($sqlMarks);
$stmt3->bind_param('ii', $exam_id, $subject_id);
$stmt3->execute();
$resultMarks = $stmt3->get_result();
while ($markRow = $resultMarks->fetch_assoc()) {
    $marks[$markRow['student_id']] = $markRow;
}
?>

<form id="marksEntryForm">
<table class="table table-bordered table-striped">
    <thead>
        <tr>
            <th>ক্রমিক</th>
            <th>ছাত্রের নাম</th>
            <th>পিতার নাম</th>
            <th><?= htmlspecialchars($subject_name) ?></th>
        </tr>
        <tr>
            <th></th>
            <th></th>
            <th></th>
            <th>
                সৃজনশীল (<?= $creativeMax ?>) ও নৈর্ব্যক্তিক (<?= $objectiveMax ?>)
            </th>
        </tr>
        <tr>
            <th></th>
            <th></th>
            <th></th>
            <th>
                <div class="row">
                    <div class="col-6 text-center">সৃজনশীল</div>
                    <div class="col-6 text-center">নৈর্ব্যক্তিক</div>
                </div>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; while ($student = $resultStudents->fetch_assoc()): 
            $studId = $student['id'];
            $creativeMark = isset($marks[$studId]) ? $marks[$studId]['creative_marks'] : 0;
            $objectiveMark = isset($marks[$studId]) ? $marks[$studId]['objective_marks'] : 0;
        ?>
        <tr>
            <td><?= $i++; ?></td>
            <td><?= htmlspecialchars($student['student_name']) ?></td>
            <td><?= htmlspecialchars($student['father_name']) ?></td>
            
            <td>
                <div class="row">
                    <div class="col-6">
                        <input type="number" 
                               min="0" max="<?= $creativeMax ?>" step="0.01" 
                               class="form-control mark-input" 
                               data-student-id="<?= $studId ?>" 
                               data-exam-subject-id="<?= $subject_id ?>" 
                               data-field="creative_marks"
                               value="<?= $creativeMark ?>"
                               onblur="saveMark(this)">
                    </div>
                    <div class="col-6">
                        <input type="number" 
                               min="0" max="<?= $objectiveMax ?>" step="0.01" 
                               class="form-control mark-input" 
                               data-student-id="<?= $studId ?>" 
                               data-exam-subject-id="<?= $subject_id ?>" 
                               data-field="objective_marks"
                               value="<?= $objectiveMark ?>"
                               onblur="saveMark(this)">
                    </div>
                </div>
            </td>
        </tr>
        <?php endwhile; ?>
    </tbody>
</table>
</form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function saveMark(input) {
    var student_id = input.getAttribute('data-student-id');
    var subject_id = input.getAttribute('data-exam-subject-id');
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
        value: value
    }, function(response) {
        // প্রয়োজনীয় হলে response এখানে ব্যবহার করবে
        // console.log(response);
    });
}
</script>
