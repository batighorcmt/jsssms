<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . BASE_URL . "auth/login.php");
    exit();
}

include '../config/db.php';
// Handle POST before any HTML output to avoid "headers already sent"
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_name = $conn->real_escape_string($_POST['exam_name']);
    $class_id = (int)$_POST['class_id'];
    $exam_type = $conn->real_escape_string($_POST['exam_type']);
    $created_by = $_SESSION['user_id'] ?? 0;

    // Step 1: Insert into exams table
    $insert_exam_sql = "INSERT INTO exams (exam_name, class_id, exam_type, created_by, created_at)
                        VALUES ('$exam_name', $class_id, '$exam_type', $created_by, NOW())";
    if ($conn->query($insert_exam_sql)) {
        $exam_id = $conn->insert_id;

        // Ensure teacher_id column exists in exam_subjects
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'teacher_id'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN teacher_id INT NULL AFTER subject_id"); }
        }
        // Ensure pass mark columns exist
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'creative_pass'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN creative_pass INT NOT NULL DEFAULT 0 AFTER creative_marks"); }
        }
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'objective_pass'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN objective_pass INT NOT NULL DEFAULT 0 AFTER objective_marks"); }
        }
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'practical_pass'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN practical_pass INT NOT NULL DEFAULT 0 AFTER practical_marks"); }
        }
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'total_pass'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN total_pass INT NOT NULL DEFAULT 0 AFTER total_marks"); }
        }
        // Ensure mark entry deadline column exists
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'mark_entry_deadline'")) {
            if ($chk->num_rows === 0) { @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN mark_entry_deadline DATE NULL AFTER exam_time"); }
        }

        // Step 2: Insert each subject with marks and assigned teacher
        $subject_ids = $_POST['subject_id'];
        $exam_dates = $_POST['exam_date'];
        $exam_times = $_POST['exam_time'];
        $creative_marks = $_POST['creative_marks'];
        $objective_marks = $_POST['objective_marks'];
        $practical_marks = $_POST['practical_marks'];
        $pass_types = $_POST['pass_type'];
        $creative_pass = $_POST['creative_pass'] ?? [];
        $objective_pass = $_POST['objective_pass'] ?? [];
        $practical_pass = $_POST['practical_pass'] ?? [];
        $total_pass = $_POST['total_pass'] ?? [];
        $teacher_ids = $_POST['teacher_id'] ?? [];
        $deadlines = $_POST['mark_entry_deadline'] ?? [];

        $all_success = true;

        foreach ($subject_ids as $i => $subject_id) {
            $exam_date = $conn->real_escape_string($exam_dates[$i]);
            $exam_time = $conn->real_escape_string($exam_times[$i]);
            $creative = (int)$creative_marks[$i];
            $objective = (int)$objective_marks[$i];
            $practical = (int)$practical_marks[$i];
            $pass_type = $conn->real_escape_string($pass_types[$i]);
            $total = $creative + $objective + $practical;
            $c_pass = isset($creative_pass[$i]) ? (int)$creative_pass[$i] : 0;
            $o_pass = isset($objective_pass[$i]) ? (int)$objective_pass[$i] : 0;
            $p_pass = isset($practical_pass[$i]) ? (int)$practical_pass[$i] : 0;
            $t_pass = isset($total_pass[$i]) ? (int)$total_pass[$i] : 0;

            $teacher_id = isset($teacher_ids[$i]) ? (int)$teacher_ids[$i] : 0;
            $deadline = isset($deadlines[$i]) ? $deadlines[$i] : null;
            if ($deadline && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$deadline,$dm)) {
                $deadline = $dm[3].'-'.$dm[2].'-'.$dm[1];
            }
            if ($deadline) { $deadline = $conn->real_escape_string($deadline); }
            $insert_subject_sql = "INSERT INTO exam_subjects 
                (exam_id, subject_id, teacher_id, exam_date, exam_time, mark_entry_deadline, creative_marks, objective_marks, practical_marks, creative_pass, objective_pass, practical_pass, pass_type, total_marks, total_pass)
                VALUES 
                ($exam_id, $subject_id, " . ($teacher_id ?: 'NULL') . ", '$exam_date', '$exam_time', " . ($deadline ? "'".$deadline."'" : 'NULL') . ", $creative, $objective, $practical, $c_pass, $o_pass, $p_pass, '$pass_type', $total, $t_pass)";

            if (!$conn->query($insert_subject_sql)) {
                $all_success = false;
                $message = '<div class="alert alert-danger">ত্রুটি হয়েছে: ' . $conn->error . '</div>';
                break;
            }
        }

        if ($all_success) {
            header("Location: exam_details.php?exam_id=".$exam_id."&status=success&msg=created");
            exit();
        }

    } else {
        $message = 'পরীক্ষা তৈরি ব্যর্থ হয়েছে: ' . $conn->error;
    }
}

include '../includes/header.php';
?>

<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="bn">নতুন পরীক্ষা তৈরি করুন</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>settings/manage_exams.php">Manage Exams</a></li>
                        <li class="breadcrumb-item active">Create</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

<?php
// Load classes
$classQuery = "SELECT * FROM classes ORDER BY class_name ASC";
$classResult = $conn->query($classQuery);
// Load teachers (for assigning per subject)
$teachers = [];
if ($tres = $conn->query("SELECT id, name FROM teachers ORDER BY name ASC")) {
    while ($t = $tres->fetch_assoc()) { $teachers[] = $t; }
}
?>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body">
                    <?php if(!empty($message)) echo '<div class="d-none" id="serverMessage" data-type="error">'. htmlspecialchars($message) .'</div>'; ?>
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="exam_name" class="form-label">পরীক্ষার নাম</label>
                                <input type="text" name="exam_name" id="exam_name" class="form-control" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="class_id" class="form-label">শ্রেণি</label>
                                <select name="class_id" id="class_id" class="form-control" required>
                                    <option value="">-- শ্রেণি নির্বাচন করুন --</option>
                                    <?php while ($class = $classResult->fetch_assoc()): ?>
                                        <option value="<?= $class['id']; ?>"><?= htmlspecialchars($class['class_name']); ?></option>
                                    <?php endwhile; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="exam_type" class="form-label">পরীক্ষার ধরন</label>
                                <select name="exam_type" id="exam_type" class="form-control" required>
                                    <option value="Half Yearly">অর্ধবার্ষিক</option>
                                    <option value="Final">বার্ষিক</option>
                                    <option value="Monthly">মাসিক</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="subjects_without_fourth" class="form-label">মোট বিষয় সংখ্যা (চতুর্থ বিষয় ছাড়া)</label>
                                <input type="number" name="subjects_without_fourth" id="subjects_without_fourth" class="form-control" min="1" placeholder="উদাহরণ: 6">
                                <small class="text-muted">টেবুলেশন শীটে মোট GPA গণনার সময় এই সংখ্যাটি দ্বারা ভাগ করা হবে (চতুর্থ/ঐচ্ছিক বিষয় বাদে)।</small>
                            </div>
                        </div>

                        <div id="subjectsContainer" style="display:none;">
                            <h5 class="mt-4 mb-2">বিষয়ভিত্তিক নম্বর নির্ধারণ</h5>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-striped">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Subject</th>
                                            <th style="min-width:110px;">Exam Date</th>
                                            <th style="min-width:130px;">Mark Entry Deadline</th>
                                            <th style="min-width:90px;">Exam Time</th>
                                            <th>সৃজনশীল</th>
                                            <th>নৈর্ব্যক্তিক</th>
                                            <th>ব্যবহারিক</th>
                                            <th>Total</th>
                                            <th>Creative Pass</th>
                                            <th>Objective Pass</th>
                                            <th>Practical Pass</th>
                                            <th>Pass Type</th>
                                            <th>Subject Teacher</th>
                                        </tr>
                                    </thead>
                                    <tbody id="subjectsTableBody">
                                        <!-- AJAX দিয়ে লোড হবে -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success" id="submitBtn" style="display:none;">Save</button>
                    </form>
                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </div><!-- /.container-fluid -->
    </section>
</div><!-- /.content-wrapper -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Expose teachers list for the per-subject dropdown
    const TEACHERS = <?php echo json_encode($teachers); ?>;
    const teacherOptions = TEACHERS.map(t => `<option value="${t.id}">${t.name.replace(/</g,'&lt;').replace(/>/g,'&gt;')}</option>`).join('');
    const classSelect = document.getElementById('class_id');
    const subjectsContainer = document.getElementById('subjectsContainer');
    const subjectsTableBody = document.getElementById('subjectsTableBody');
    const submitBtn = document.getElementById('submitBtn');

    classSelect.addEventListener('change', function () {
        const classId = this.value;
        if (!classId) {
            subjectsContainer.style.display = 'none';
            submitBtn.style.display = 'none';
            return;
        }

        fetch('fetch_subjects.php?class_id=' + classId)
            .then(res => res.json())
            .then(data => {
                if (data.length === 0) {
                    subjectsTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">এই শ্রেণিতে কোনো বিষয় পাওয়া যায়নি।</td></tr>';
                    subjectsContainer.style.display = 'block';
                    submitBtn.style.display = 'none';
                    return;
                }

                let rows = '';
                data.forEach(subject => {
                    const hasC = Number(subject.has_creative) === 1;
                    const hasO = Number(subject.has_objective) === 1;
                    const hasP = Number(subject.has_practical) === 1;
                    const passType = (subject.pass_type && String(subject.pass_type).trim() !== '') ? String(subject.pass_type) : 'total';

                    rows += `
                        <tr>
                            <td>
                                ${subject.subject_name}
                                <input type="hidden" name="subject_id[]" value="${subject.id}">
                            </td>
                            <td><input type="text" name="exam_date[]" class="form-control date-input" placeholder="dd/mm/yyyy"></td>
                            <td><input type="text" name="mark_entry_deadline[]" class="form-control date-input" placeholder="dd/mm/yyyy"></td>
                            <td><input type="time" name="exam_time[]" class="form-control" ></td>
                            <td>
                                <input type="number" name="creative_marks[]" class="form-control creative_marks" min="0" value="0" ${hasC ? '' : 'disabled'} required>
                                ${hasC ? '' : '<input type="hidden" name="creative_marks[]" value="0">'}
                            </td>
                            <td>
                                <input type="number" name="objective_marks[]" class="form-control objective_marks" min="0" value="0" ${hasO ? '' : 'disabled'} required>
                                ${hasO ? '' : '<input type="hidden" name="objective_marks[]" value="0">'}
                            </td>
                            <td>
                                <input type="number" name="practical_marks[]" class="form-control practical_marks" min="0" value="0" ${hasP ? '' : 'disabled'} required>
                                ${hasP ? '' : '<input type="hidden" name="practical_marks[]" value="0">'}
                            </td>
                            <td><input type="number" class="form-control total_marks" value="0" readonly></td>
                            <td>
                                <input type="number" name="creative_pass[]" class="form-control" min="0" value="0" ${hasC ? '' : 'disabled'} required>
                                ${hasC ? '' : '<input type="hidden" name="creative_pass[]" value="0">'}
                            </td>
                            <td>
                                <input type="number" name="objective_pass[]" class="form-control" min="0" value="0" ${hasO ? '' : 'disabled'} required>
                                ${hasO ? '' : '<input type="hidden" name="objective_pass[]" value="0">'}
                            </td>
                            <td>
                                <input type="number" name="practical_pass[]" class="form-control" min="0" value="0" ${hasP ? '' : 'disabled'} required>
                                ${hasP ? '' : '<input type="hidden" name="practical_pass[]" value="0">'}
                            </td>
                            <td>
                                <select name="pass_type[]" class="form-control">
                                    <option value="total" ${passType === 'total' ? 'selected' : ''}>মোট নাম্বার</option>
                                    <option value="individual" ${passType === 'individual' ? 'selected' : ''}>আলাদা আলাদা</option>
                                </select>
                            </td>
                            <td>
                                <select name="teacher_id[]" class="form-control">
                                    <option value="">-- শিক্ষক --</option>
                                    ${teacherOptions}
                                </select>
                            </td>
                        </tr>
                    `;
                });

                subjectsTableBody.innerHTML = rows;
                subjectsContainer.style.display = 'block';
                submitBtn.style.display = 'inline-block';

                // Initialize datepickers for dynamically added date inputs
                if (window.setupDateInputs) { window.setupDateInputs(subjectsTableBody); }

                // Mark total update
                document.querySelectorAll('.creative_marks, .objective_marks, .practical_marks').forEach(input => {
                    input.addEventListener('input', updateTotals);
                });

                updateTotals();
            })
            .catch(() => {
                subjectsTableBody.innerHTML = '<tr><td colspan="8" class="text-center text-danger">বিষয় লোড করতে ব্যর্থ।</td></tr>';
                subjectsContainer.style.display = 'block';
                submitBtn.style.display = 'none';
            });
    });

    function updateTotals() {
        const rows = subjectsTableBody.querySelectorAll('tr');
        rows.forEach(row => {
            const c = parseInt(row.querySelector('.creative_marks').value) || 0;
            const o = parseInt(row.querySelector('.objective_marks').value) || 0;
            const p = parseInt(row.querySelector('.practical_marks').value) || 0;
            row.querySelector('.total_marks').value = c + o + p;
        });
    }
});
// Toast trigger if serverMessage exists
if (document.getElementById('serverMessage')) {
    var sm = document.getElementById('serverMessage');
    if (window.showToast) window.showToast('ত্রুটি', sm.innerHTML, 'error');
}
</script>

<?php include '../includes/footer.php'; ?>
