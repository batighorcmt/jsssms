<?php 
session_start();
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'super_admin' && $_SESSION['role'] !== 'teacher')) {
        header("Location: ../auth/login.php");
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
                    <h1 class="bn">মার্ক এন্ট্রি</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="/jsssms/dashboard.php">Home</a></li>
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
            <label>পরীক্ষা</label>
            <select name="exam_id" id="exam_id" class="form-control" required>
                <option value="">সিলেক্ট করুন</option>
                <?php
                $exams = $conn->query("SELECT * FROM exams ORDER BY exam_name");
                while ($exam = $exams->fetch_assoc()):
                ?>
                <option value="<?= $exam['id'] ?>"><?= htmlspecialchars($exam['exam_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label>শ্রেণি</label>
            <select name="class_id" id="class_id" class="form-control" required>
                <option value="">সিলেক্ট করুন</option>
                <?php
                $classes = $conn->query("SELECT * FROM classes ORDER BY class_name");
                while ($cls = $classes->fetch_assoc()):
                ?>
                <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['class_name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label>গ্রুপ</label>
            <select name="group" id="group" class="form-control" required>
                <option value="">গ্রুপ নির্বাচন করুন</option>
                <option value="none">কোন গ্রুপ নই</option>
                <option value="Science">বিজ্ঞান</option>
                <option value="Humanities">মানবিক</option>
                <option value="Business Studies">ব্যবসায় শিক্ষা</option>
            </select>
        </div>

        <div class="col-md-3">
            <label>বিষয়</label>
            <select name="subject_id" id="subject_id" class="form-control" required>
                <option value="">বিষয় নির্বাচন করুন</option>
            </select>
        </div>

        <div class="col-md-3">
            <label>সাল</label>
            <select name="year" id="year" class="form-control" required>
                <option value="">সাল নির্বাচন</option>
                <?php
                $years = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                while ($yr = $years->fetch_assoc()):
                ?>
                <option value="<?= htmlspecialchars($yr['year']) ?>"><?= htmlspecialchars($yr['year']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="col-md-12 mt-2">
            <button type="button" id="loadStudents" class="btn btn-primary">লোড করুন</button>
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

    // শ্রেণি অথবা গ্রুপ পরিবর্তন করলে বিষয় লোড হবে
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
                group: group
            }, function(data) {
                $('#subject_id').html(data);
            });
        } else {
            $('#subject_id').html('<option value="">বিষয় নির্বাচন করুন</option>');
        }
    });

    // লোড বাটনে ক্লিক করলে মার্ক এন্ট্রি ফর্ম লোড হবে
    $('#loadStudents').click(function() {
        var exam_id = $('#exam_id').val();
        var class_id = $('#class_id').val();
        var group = $('#group').val();
        var subject_id = $('#subject_id').val();
        var year = $('#year').val();

        if (!exam_id || !class_id || !group || !subject_id || !year) {
            alert('সব ফিল্ড সিলেক্ট করুন');
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
                alert('ডাটা লোড করতে সমস্যা হয়েছে।');
            }
        });
    });

});

// মার্ক অটো সেভ ফাংশন (load_students_mark_form.php-র ইনপুটে কল করা হবে)
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
            alert('সার্ভার থেকে অজানা উত্তর এসেছে।');
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
