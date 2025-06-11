<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    $_SESSION['error'] = "Invalid Exam Subject ID!";
    header("Location: manage_exams.php");
    exit();
}

// Get existing exam_subject data
$sql = "SELECT es.*, e.exam_name, e.class_id, s.subject_name 
        FROM exam_subjects es 
        JOIN exams e ON es.exam_id = e.id
        JOIN subjects s ON es.subject_id = s.id
        WHERE es.id = $id";
$result = $conn->query($sql);
if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Exam not found!";
    header("Location: manage_exams.php");
    exit();
}
$exam = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_date = $_POST['exam_date'];
    $exam_time = $_POST['exam_time'];
    $creative_marks = $_POST['creative_marks'] ?? 0;
    $objective_marks = $_POST['objective_marks'] ?? 0;
    $practical_marks = $_POST['practical_marks'] ?? 0;
    $total_marks = $creative_marks + $objective_marks + $practical_marks;

    $update = $conn->prepare("UPDATE exam_subjects SET exam_date=?, exam_time=?, creative_marks=?, objective_marks=?, practical_marks=?, total_marks=? WHERE id=?");
    $update->bind_param("ssiiiii", $exam_date, $exam_time, $creative_marks, $objective_marks, $practical_marks, $total_marks, $id);
    if ($update->execute()) {
        $_SESSION['success'] = "পরীক্ষা সফলভাবে আপডেট হয়েছে!";
        header("Location: manage_exams.php");
        exit();
    } else {
        $error = "Something went wrong!";
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container mt-4">
    <h4>পরীক্ষা এডিট করুন - <?= htmlspecialchars($exam['exam_name']) ?></h4>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?= $error; ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label>পরীক্ষার তারিখ</label>
            <input type="date" name="exam_date" class="form-control" value="<?= $exam['exam_date'] ?>" required>
        </div>
        <div class="mb-3">
            <label>পরীক্ষার সময়</label>
            <input type="text" name="exam_time" class="form-control" value="<?= $exam['exam_time'] ?>" required>
        </div>
        <div class="mb-3">
            <label>সৃজনশীল নম্বর</label>
            <input type="number" name="creative_marks" class="form-control" value="<?= $exam['creative_marks'] ?>" min="0">
        </div>
        <div class="mb-3">
            <label>নৈর্ব্যক্তিক নম্বর</label>
            <input type="number" name="objective_marks" class="form-control" value="<?= $exam['objective_marks'] ?>" min="0">
        </div>
        <div class="mb-3">
            <label>ব্যবহারিক নম্বর</label>
            <input type="number" name="practical_marks" class="form-control" value="<?= $exam['practical_marks'] ?>" min="0">
        </div>
        <button type="submit" class="btn btn-success">আপডেট করুন</button>
        <a href="manage_exams.php" class="btn btn-secondary">বাতিল</a>
    </form>
</div>

<?php include '../includes/footer.php'; ?>
