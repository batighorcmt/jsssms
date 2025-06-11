<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}
include '../config/db.php';

// ছাত্র তালিকা
$students = $conn->query("SELECT id, student_name, student_id, class_id FROM students WHERE status = 'Active' ORDER BY student_name ASC");

$selected_student = null;
$subjects = [];
$assigned_subjects = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'];
    $subjects = $_POST['subjects'] ?? [];

    // আগের বিষয় মুছে ফেলা
    $conn->query("DELETE FROM student_subjects WHERE student_id = '$student_id'");

    // নতুন বিষয় যুক্ত করা
    foreach ($subjects as $subject_id) {
        $stmt = $conn->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
        $stmt->bind_param("si", $student_id, $subject_id);
        $stmt->execute();
    }

    $_SESSION['success'] = "বিষয় সফলভাবে সংরক্ষণ/আপডেট হয়েছে!";
    header("Location: add_subjects.php?student_id=" . $student_id);
    exit();
}

// যদি student_id URL এ থাকে তাহলে তার তথ্য ও বিষয় আনো
if (isset($_GET['student_id'])) {
    $student_id = $_GET['student_id'];

    // ছাত্র তথ্য
    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $selected_student = $res->fetch_assoc();

    if ($selected_student) {
        $class_id = $selected_student['class_id'];

        // ঐ শ্রেণির বিষয়
        $subjects = $conn->query("SELECT * FROM subjects WHERE class_id = $class_id AND status='Active'");

        // আগে নির্বাচিত বিষয়
        $result = $conn->query("SELECT subject_id FROM student_subjects WHERE student_id = '$student_id'");
        while ($row = $result->fetch_assoc()) {
            $assigned_subjects[] = $row['subject_id'];
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>
<div class="container mt-4">
    <h4>শিক্ষার্থীদের বিষয় নির্বাচন / আপডেট</h4>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <form method="POST" action="add_subjects.php">
        <div class="row mb-3">
            <div class="col-md-6">
                <label class="form-label">শিক্ষার্থী নির্বাচন করুন</label>
                <select name="student_id" class="form-select" required onchange="this.form.submit()">
                    <option value="">-- একজন শিক্ষার্থী নির্বাচন করুন --</option>
                    <?php foreach ($students as $row): ?>
                        <option value="<?= $row['student_id'] ?>" <?= (isset($selected_student) && $selected_student['student_id'] == $row['student_id']) ? 'selected' : '' ?>>
                            <?= $row['student_name'] ?> (<?= $row['student_id'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php if ($selected_student && $subjects->num_rows > 0): ?>
            <div class="mb-3">
                <label class="form-label">বিষয়সমূহ নির্বাচন করুন</label>
                <div class="row">
                    <?php foreach ($subjects as $subject): ?>
                        <div class="col-md-4">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="subjects[]" value="<?= $subject['id'] ?>"
                                    id="subject<?= $subject['id'] ?>"
                                    <?= in_array($subject['id'], $assigned_subjects) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="subject<?= $subject['id'] ?>">
                                    <?= $subject['subject_name'] ?> (<?= $subject['subject_code'] ?>)
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-success">বিষয় সংরক্ষণ করুন</button>
        <?php elseif (isset($selected_student)): ?>
            <div class="alert alert-warning">এই শ্রেণির জন্য কোনো বিষয় পাওয়া যায়নি।</div>
        <?php endif; ?>
    </form>
</div>
</div>
