<?php
session_start();
if ($_SESSION['role'] !== 'super_admin') {
    die('Access Denied');
}
include '../config/db.php';
include '../includes/header.php';
?>
<div class="d-flex">
    <?php include '../includes/sidebar.php'; ?>
</div>

<div class="container mt-4">
    <h4 class="mb-4">🎓 Student Transcript Viewer</h4>
    <form id="transcriptForm" class="row g-3">
        <div class="col-md-3">
            <label>Exam</label>
            <select class="form-select" name="exam_id" id="exam_id" required>
                <option value="">Select Exam</option>
                <?php
                $exams = $conn->query("SELECT id, exam_name FROM exams");
                while ($row = $exams->fetch_assoc()) {
                    echo "<option value='{$row['id']}'>{$row['exam_name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-3">
            <label>Class</label>
            <select class="form-select" name="class_id" id="class_id" required>
                <option value="">Select Class</option>
                <?php
                $classes = $conn->query("SELECT id, class_name FROM classes");
                while ($row = $classes->fetch_assoc()) {
                    echo "<option value='{$row['id']}'>{$row['class_name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-2">
            <label>Year</label>
            <select class="form-select" name="year" id="year" required>
                <option value="">Select Year</option>
                <?php
                for ($y = date('Y'); $y >= 2020; $y--) {
                    echo "<option value='$y'>$y</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-4">
            <label>Student</label>
            <select class="form-select" name="student_id" id="student_id" required>
                <option value="">Select Student</option>
            </select>
        </div>
    </form>

    <div id="transcript_result" class="mt-4"></div>
</div>

<script>
document.getElementById('class_id').addEventListener('change', loadStudents);
document.getElementById('year').addEventListener('change', loadStudents);

function loadStudents() {
    let classId = document.getElementById('class_id').value;
    let year = document.getElementById('year').value;

    if (classId && year) {
        fetch('get_students.php?class_id=' + classId + '&year=' + year)
            .then(res => res.text())
            .then(data => {
                document.getElementById('student_id').innerHTML = data;
            });
    }
}

document.getElementById('student_id').addEventListener('change', function () {
    let form = document.getElementById('transcriptForm');
    let formData = new FormData(form);

    fetch('load_transcript.php', {
        method: 'POST',
        body: formData
    }).then(res => res.text()).then(html => {
        document.getElementById('transcript_result').innerHTML = html;
    });
});
</script>
