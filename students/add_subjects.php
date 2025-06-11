<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

$student_id = $_GET['student_id'] ?? '';

$student = null;
$subjects = [];
$assigned_subjects = [];

if ($student_id) {
    $stmt = $conn->prepare("SELECT s.*, c.class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    if ($student_result->num_rows > 0) {
        $student = $student_result->fetch_assoc();
        $class_id = $student['class_id'];
        $group = $student['student_group'];

        $stmt = $conn->prepare("SELECT * FROM subjects WHERE class_id = ? AND (`group` = ? OR `group` = '' OR `group` IS NULL)");
        $stmt->bind_param("is", $class_id, $group);
        $stmt->execute();
        $subjects_result = $stmt->get_result();
        while ($row = $subjects_result->fetch_assoc()) {
            $subjects[] = $row;
        }

        $stmt = $conn->prepare("SELECT subject_id FROM student_subjects WHERE student_id = ?");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $assigned_result = $stmt->get_result();
        while ($row = $assigned_result->fetch_assoc()) {
            $assigned_subjects[] = $row['subject_id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'];
    $selected_subjects = $_POST['subjects'] ?? [];

    // পূর্বের বিষয়গুলো মুছে ফেলুন
    $del = $conn->prepare("DELETE FROM student_subjects WHERE student_id = ?");
    $del->bind_param("s", $student_id);
    $del->execute();

    // নতুন বিষয় যুক্ত করুন
    $stmt = $conn->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
    foreach ($selected_subjects as $sub_id) {
        $stmt->bind_param("si", $student_id, $sub_id);
        $stmt->execute();
    }

    header("Location: ../manage_students.php?success=1");
    exit();
}
?>
<?php include '../includes/header.php'; ?>
<div class="d-flex"><?php include '../includes/sidebar.php'; ?>
<div class="container mt-4">
    <h4>বিষয় নির্বাচন</h4>

    <form method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" name="student_id" class="form-control" placeholder="Student ID" value="<?= htmlspecialchars($student_id) ?>" required>
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
    </form>

    <?php if ($student): ?>
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">বিষয়সমূহ সফলভাবে সংরক্ষিত হয়েছে।</div>
        <?php endif; ?>

        <div class="card p-3 mb-4">
            <h5>শিক্ষার্থীর তথ্য</h5>
            <p><strong>নাম:</strong> <?= $student['student_name'] ?></p>
            <p><strong>শ্রেণি:</strong> <?= $student['class_name'] ?></p>
            <p><strong>গ্রুপ:</strong> <?= $student['student_group'] ?></p>
        </div>

        <form method="POST">
            <input type="hidden" name="student_id" value="<?= $student_id ?>">
            <div class="card p-3">
                <h5>বিষয় তালিকা</h5>
                <?php foreach ($subjects as $sub): ?>
                    <?php
                        $is_compulsory = $sub['type'] === 'Compulsory';
                        $is_checked = in_array($sub['id'], $assigned_subjects) || $is_compulsory;
                    ?>
                    <div class="form-check">
                        <input class="form-check-input optional-subject" type="checkbox"
                            <?= $is_checked ? 'checked' : '' ?>
                            <?= $is_compulsory ? 'readonly' : '' ?>
                            name="subjects[]" value="<?= $sub['id'] ?>"
                            id="subject_<?= $sub['id'] ?>">
                        <label class="form-check-label" for="subject_<?= $sub['id'] ?>">
                            <?= htmlspecialchars($sub['subject_name']) ?> (<?= $sub['type'] ?>)
                        </label>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-success mt-3">Save Subjects</button>
            </div>
        </form>
    <?php elseif ($student_id): ?>
        <div class="alert alert-danger">কোনো শিক্ষার্থী খুঁজে পাওয়া যায়নি!</div>
    <?php endif; ?>
</div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    let optionalSubjects = document.querySelectorAll(".optional-subject:not([readonly])");
    optionalSubjects.forEach(cb => {
        cb.addEventListener("change", function () {
            let selected = Array.from(optionalSubjects).filter(c => c.checked);
            if (selected.length > 1) {
                alert("শুধুমাত্র একটি ঐচ্ছিক বিষয় নির্বাচন করা যাবে!");
                cb.checked = false;
            }
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>
