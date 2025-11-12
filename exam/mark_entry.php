<?php 
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'teacher')) {
    header("Location: " . BASE_URL . "auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
?>

<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Mark Entry</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Mark Entry</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
        <div class="card">
            <div class="card-body">
    <form id="markFilterForm" class="row g-3">
        <div class="col-md-3">
            <label>Exam</label>
            <select name="exam_id" id="exam_id" class="form-control" required>
                <option value="">Select</option>
                <?php
                $exams = $conn->query("SELECT exams.id, exams.exam_name, classes.class_name FROM exams JOIN classes ON exams.class_id = classes.id ORDER BY exams.exam_name");
                while ($exam = $exams->fetch_assoc()):
                ?>
                <option value="<?= $exam['id'] ?>"><?= htmlspecialchars($exam['exam_name'] . ' - ' . $exam['class_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label>Class</label>
            <select name="class_id" id="class_id" class="form-control" required>
                <option value="">Select</option>
                <?php
                $classes = $conn->query("SELECT * FROM classes ORDER BY class_name");
                while ($cls = $classes->fetch_assoc()):
                ?>
                <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['class_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label>Group</label>
            <select name="group" id="group" class="form-control" required>
                <option value="">Select group</option>
                <option value="none">No group</option>
                <option value="Science">Science</option>
                <option value="Humanities">Humanities</option>
                <option value="Business Studies">Business Studies</option>
            </select>
        </div>

        <div class="col-md-3">
            <label>Subject</label>
            <select name="subject_id" id="subject_id" class="form-control" required>
                <option value="">Select subject</option>
            </select>
        </div>

        <div class="col-md-3">
            <label>Year</label>
            <select name="year" id="year" class="form-control" required>
                <option value="">Select year</option>
                <?php
                $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                while ($yr = $years->fetch_assoc()):
                ?>
                <option value="<?= htmlspecialchars($yr['year']) ?>"><?= htmlspecialchars($yr['year']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-12 mt-2">
            <button type="button" id="loadStudents" class="btn btn-primary">Load</button>
        </div>
    </form>
            </div>
        </div>

        <div id="markEntryArea" class="mt-3"></div>
        </div>
    </section>
</div>

<script>
// Ensure jQuery is available (loaded in footer) before binding handlers
window.addEventListener('load', function() {
    var $ = window.jQuery;
    if (!$) return;

    // When class or group changes, load subjects
    $('#class_id, #group').change(function() {
                var class_id = $('#class_id').val();
                var group = $('#group').val();
        // Normalize group value to match database (NONE vs none)
        if (group && group.toLowerCase() === 'none') {
            group = 'NONE';
        }

                if (class_id && group) {
                    $.post('fetch_subjects.php', {
                        class_id: class_id,
                        group: group,
                        exam_id: $('#exam_id').val() // may be used for filtering by assignment
                    }, function(data) {
                        $('#subject_id').html(data);
                    });
        } else {
            $('#subject_id').html('<option value="">Select subject</option>');
        }
    });

    // Load mark entry form on click
    $('#loadStudents').click(function() {
        var exam_id = $('#exam_id').val();
        var class_id = $('#class_id').val();
        var group = $('#group').val();
        var subject_id = $('#subject_id').val();
        var year = $('#year').val();

        if (!exam_id || !class_id || !group || !subject_id || !year) {
            alert('Please select all fields');
            return;
        }

        $.ajax({
            url: 'load_students_mark_form.php',
            method: 'POST',
            data: {
                exam_id: exam_id,
                class_id: class_id,
                group: group,
                subject_id: subject_id,
                year: year
            },
            success: function(response) {
                $('#markEntryArea').html(response);
            },
            error: function() {
                alert('Failed to load data.');
            }
        });
    });

});

// Auto save mark function (called from inputs in load_students_mark_form.php)
function saveMark(student_id, exam_subject_id, field, value) {
    $.post('save_mark.php', {
        student_id: student_id,
        exam_subject_id: exam_subject_id,
        field: field,
        value: value
    }, function(res) {
        try {
            var resp = JSON.parse(res);
            if (resp.status === 'error') alert(resp.message);
        } catch {
            alert('Unknown response from server.');
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
