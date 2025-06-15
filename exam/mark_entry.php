<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
?>

<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<div class="container mt-4">
    <h4>মার্ক এন্ট্রি</h4>
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

        <!-- section_id সিলেক্ট বাদ দেয়া হলো -->

        <div class="col-md-3">
            <label>বিষয়</label>
            <select name="subject_id" id="subject_id" class="form-control" required></select>
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

    <div id="markEntryArea" class="mt-4"></div>
</div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function() {

    // ক্লাস পরিবর্তন করলে বিষয় লোড হবে
    $('#class_id').change(function() {
        var class_id = $(this).val();
        if(class_id) {
            $.post('fetch_subjects.php', {class_id: class_id}, function(data) {
                $('#subject_id').html(data);
            });
        } else {
            $('#subject_id').html('<option value="">বিষয় নির্বাচন করুন</option>');
        }
    });

    // লোড বাটন ক্লিক করলে ছাত্র ও মার্ক এন্ট্রি ফর্ম লোড হবে
    $('#loadStudents').click(function() {
        var exam_id = $('#exam_id').val();
        var class_id = $('#class_id').val();
        var subject_id = $('#subject_id').val();
        var year = $('#year').val();

        if(!exam_id || !class_id || !subject_id || !year) {
            alert('সব ফিল্ড সিলেক্ট করুন');
            return;
        }

        $.ajax({
            url: 'load_students_mark_form.php',
            method: 'POST',
            data: {
                exam_id: exam_id,
                class_id: class_id,
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
            if(resp.status === 'error') alert(resp.message);
        } catch {
            alert('সার্ভার থেকে অজানা উত্তর এসেছে।');
        }
    });
}
</script>

<?php include '../includes/footer.php'; ?>
