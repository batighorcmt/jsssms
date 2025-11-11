<?php
session_start();
include '../config/db.php';

$exam_id = intval($_POST['exam_id']);
$class_id = intval($_POST['class_id']);
$subject_id = intval($_POST['subject_id']);
$year = intval($_POST['year']);

// ✅ Subject Details
$sql = "SELECT s.subject_name, es.creative_marks, es.objective_marks, es.practical_marks
        FROM exam_subjects es 
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.exam_id = ? AND es.subject_id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $exam_id, $subject_id);
$stmt->execute();
$subject = $stmt->get_result()->fetch_assoc();

if (!$subject) {
    echo '<div class="alert alert-warning">এই বিষয়টি পরীক্ষার সাথে যুক্ত নয়।</div>';
    exit;
}

$subject_name = $subject['subject_name'];
$creativeMax = $subject['creative_marks'];
$objectiveMax = $subject['objective_marks'];
$practicalMax = $subject['practical_marks'];

// ✅ Students List (who selected this subject)
$sql2 = "SELECT s.id, s.student_name, s.father_name, s.roll_no, s.student_id 
         FROM students s
         JOIN student_subjects ss ON s.student_id = ss.student_id
         WHERE s.class_id = ? AND s.year = ? AND ss.subject_id = ?
         ORDER BY s.roll_no ASC";

$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param('iii', $class_id, $year, $subject_id);
$stmt2->execute();
$students = $stmt2->get_result();

if ($students->num_rows === 0) {
    echo '<div class="alert alert-warning">এই বিষয়ে নির্বাচিত কোনো ছাত্র পাওয়া যায়নি।</div>';
    exit;
}

// ✅ Existing Marks
$marks = [];
$sql3 = "SELECT student_id, creative_marks, objective_marks, practical_marks FROM marks WHERE exam_id = ? AND subject_id = ?";
$stmt3 = $conn->prepare($sql3);
$stmt3->bind_param("ii", $exam_id, $subject_id);
$stmt3->execute();
$resultMarks = $stmt3->get_result();
while ($row = $resultMarks->fetch_assoc()) {
    $marks[$row['student_id']] = $row;
}
?>

<!-- ✅ Display Form -->
<div class="card mt-4 shadow">
    <div class="card-header bg-primary text-white">
        <strong><?= htmlspecialchars($subject_name) ?></strong> 
        (CQ: <?= $creativeMax ?> / MCQ: <?= $objectiveMax ?> / PR: <?= $practicalMax ?>)
    </div>
    <div class="card-body p-0">
        <form id="marksEntryForm">
            <table class="table table-bordered table-striped table-hover mb-0">
                <thead class="thead-light text-center">
                    <tr>
                        <th>Roll</th>
                        <th>Name</th>
                        <?php if ($creativeMax > 0): ?><th>CQ</th><?php endif; ?>
                        <?php if ($objectiveMax > 0): ?><th>MCQ</th><?php endif; ?>
                        <?php if ($practicalMax > 0): ?><th>PR</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($student = $students->fetch_assoc()): 
                        $sid = $student['student_id'];
                        $creative = $marks[$sid]['creative_marks'] ?? 0;
                        $objective = $marks[$sid]['objective_marks'] ?? 0;
                        $practical = $marks[$sid]['practical_marks'] ?? 0;
                    ?>
                    <tr>
                        <td class="text-center"><?= htmlspecialchars($student['roll_no']) ?></td>
                        <td><?= htmlspecialchars($student['student_name']) ?></td>
                        <?php if ($creativeMax > 0): ?>
                        <td><input type="number" min="0" max="<?= $creativeMax ?>" step="0.01"
                                   class="form-control form-control-sm text-center"
                                   data-student-id="<?= $sid ?>" 
                                   data-subject-id="<?= $subject_id ?>" 
                                   data-field="creative_marks"
                                   value="<?= $creative ?>"
                                   onblur="saveMark(this)">
                        </td>
                        <?php endif; ?>
                        <?php if ($objectiveMax > 0): ?>
                        <td><input type="number" min="0" max="<?= $objectiveMax ?>" step="0.01"
                                   class="form-control form-control-sm text-center"
                                   data-student-id="<?= $sid ?>" 
                                   data-subject-id="<?= $subject_id ?>" 
                                   data-field="objective_marks"
                                   value="<?= $objective ?>"
                                   onblur="saveMark(this)">
                        </td>
                        <?php endif; ?>
                        <?php if ($practicalMax > 0): ?>
                        <td><input type="number" min="0" max="<?= $practicalMax ?>" step="0.01"
                                   class="form-control form-control-sm text-center"
                                   data-student-id="<?= $sid ?>" 
                                   data-subject-id="<?= $subject_id ?>" 
                                   data-field="practical_marks"
                                   value="<?= $practical ?>"
                                   onblur="saveMark(this)">
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </form>
    </div>
</div>

<script>
function saveMark(input) {
    let student_id = input.dataset.studentId;
    let subject_id = input.dataset.subjectId;
    let field = input.dataset.field;
    let value = input.value;

    let min = parseFloat(input.min);
    let max = parseFloat(input.max);
    let val = parseFloat(value);

    if (isNaN(val) || val < min || val > max) {
        alert('নম্বর অবশ্যই ' + min + ' থেকে ' + max + ' এর মধ্যে হতে হবে।');
        input.value = 0;
        input.focus();
        return;
    }

    $.post('save_mark.php', {
        exam_id: <?= $exam_id ?>,
        student_id: student_id,
        subject_id: subject_id,
        field: field,
        value: value
    });
}
</script>
