<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
?>

<div class="d-flex">
<?php include '../includes/sidebar.php';

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

        // Step 2: Insert each subject with marks
        $subject_ids = $_POST['subject_id'];
        $exam_dates = $_POST['exam_date'];
        $exam_times = $_POST['exam_time'];
        $creative_marks = $_POST['creative_marks'];
        $objective_marks = $_POST['objective_marks'];
        $practical_marks = $_POST['practical_marks'];
        $pass_types = $_POST['pass_type'];

        $all_success = true;

        foreach ($subject_ids as $i => $subject_id) {
            $exam_date = $conn->real_escape_string($exam_dates[$i]);
            $exam_time = $conn->real_escape_string($exam_times[$i]);
            $creative = (int)$creative_marks[$i];
            $objective = (int)$objective_marks[$i];
            $practical = (int)$practical_marks[$i];
            $pass_type = $conn->real_escape_string($pass_types[$i]);
            $total = $creative + $objective + $practical;

            $insert_subject_sql = "INSERT INTO exam_subjects 
                (exam_id, subject_id, exam_date, exam_time, creative_marks, objective_marks, practical_marks, pass_type, total_marks)
                VALUES 
                ($exam_id, $subject_id, '$exam_date', '$exam_time', $creative, $objective, $practical, '$pass_type', $total)";

            if (!$conn->query($insert_subject_sql)) {
                $all_success = false;
                $message = '<div class="alert alert-danger">ত্রুটি হয়েছে: ' . $conn->error . '</div>';
                break;
            }
        }

        if ($all_success) {
            $message = '<div class="alert alert-success">পরীক্ষা সফলভাবে তৈরি করা হয়েছে।</div>';
        }

    } else {
        $message = '<div class="alert alert-danger">পরীক্ষা তৈরি ব্যর্থ হয়েছে: ' . $conn->error . '</div>';
    }
}

// Load classes
$classQuery = "SELECT * FROM classes ORDER BY class_name ASC";
$classResult = $conn->query($classQuery);
?>

<div class="container mt-4">
    <h4>নতুন পরীক্ষা তৈরি করুন</h4>
    <?= $message; ?>

    <form method="POST" action="store_exam.php">
        <div class="mb-3">
            <label for="exam_name" class="form-label">পরীক্ষার নাম</label>
            <input type="text" name="exam_name" id="exam_name" class="form-control" required>
        </div>

        <div class="mb-3">
            <label for="class_id" class="form-label">শ্রেণি</label>
            <select name="class_id" id="class_id" class="form-select" required>
                <option value="">-- শ্রেণি নির্বাচন করুন --</option>
                <?php while ($class = $classResult->fetch_assoc()): ?>
                    <option value="<?= $class['id']; ?>"><?= htmlspecialchars($class['class_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label for="exam_type" class="form-label">পরীক্ষার ধরন</label>
            <select name="exam_type" id="exam_type" class="form-select" required>
                <option value="Half Yearly">অর্ধবার্ষিক</option>
                <option value="Final">বার্ষিক</option>
                <option value="Monthly">মাসিক</option>
            </select>
        </div>

        <div id="subjectsContainer" style="display:none;">
            <h5>বিষয়ভিত্তিক নম্বর নির্ধারণ</h5>
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>বিষয়</th>
                        <th>তারিখ</th>
                        <th>সময়</th>
                        <th>সৃজনশীল মার্ক</th>
                        <th>নৈর্ব্যক্তিক মার্ক</th>
                        <th>ব্যবহারিক মার্ক</th>
                        <th>মোট </th>
                        <th>সৃজনশীল পাশ মার্ক</th>
                        <th>নৈর্ব্যক্তিক পাশ মার্ক</th>
                        <th>ব্যবহারিক পাশ মার্ক</th>
                    </tr>
                </thead>
                <tbody id="subjectsTableBody">
                    <!-- AJAX দিয়ে লোড হবে -->
                </tbody>
            </table>
        </div>

        <button type="submit" class="btn btn-success" id="submitBtn" style="display:none;">সেভ করুন</button>
    </form>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
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
                    rows += `
                        <tr>
    <td>
        ${subject.subject_name}
        <input type="hidden" name="subject_id[]" value="1">
    </td>
    <td><input type="date" name="exam_date[]" class="form-control" required></td>
    <td><input type="time" name="exam_time[]" class="form-control" required></td>
    <td><input type="number" name="creative_marks[]" class="form-control creative_marks" min="0" value="0" required></td>
    <td><input type="number" name="objective_marks[]" class="form-control objective_marks" min="0" value="0" required></td>
    <td><input type="number" name="practical_marks[]" class="form-control practical_marks" min="0" value="0" required></td>
    <td><input type="number" class="form-control total_marks" value="0" readonly></td>
    <td><input type="number" name="creative_pass[]" class="form-control" min="0" value="0" required></td>
    <td><input type="number" name="objective_pass[]" class="form-control" min="0" value="0" required></td>
    <td><input type="number" name="practical_pass[]" class="form-control" min="0" value="0" required></td>
</tr>

                    `;
                });

                subjectsTableBody.innerHTML = rows;
                subjectsContainer.style.display = 'block';
                submitBtn.style.display = 'inline-block';

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
</script>

<?php include '../includes/footer.php'; ?>
