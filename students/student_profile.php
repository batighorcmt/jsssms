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

if ($student_id) {
    // শিক্ষার্থীর তথ্য সংগ্রহ
    $stmt = $conn->prepare("SELECT s.*, c.class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $student = $result->fetch_assoc();
    }

    // বিষয় তালিকা সংগ্রহ
    $stmt = $conn->prepare("SELECT sub.subject_name, sub.subject_code, sub.type 
                            FROM student_subjects ss
                            JOIN subjects sub ON ss.subject_id = sub.id
                            WHERE ss.student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<?php include '../includes/header.php'; ?>
<div class="d-flex">
    <?php include '../includes/sidebar.php'; ?>
    <div class="container mt-4">
        <h4>শিক্ষার্থীর প্রোফাইল</h4>

        <?php if ($student): ?>
            <div class="card p-3 mb-4">
                <h5>শিক্ষার্থীর তথ্য</h5>
                <p><strong>নাম:</strong> <?= htmlspecialchars($student['student_name']) ?></p>
                <p><strong>রোল:</strong> <?= htmlspecialchars($student['roll']) ?></p>
                <p><strong>শ্রেণি:</strong> <?= htmlspecialchars($student['class_name']) ?></p>
                <p><strong>বিভাগ:</strong> <?= htmlspecialchars($student['student_group']) ?></p>
                <p><strong>মোবাইল:</strong> <?= htmlspecialchars($student['mobile']) ?></p>
                <p><strong>ঠিকানা:</strong> গ্রাম-<?= htmlspecialchars($student['village']) ?>, 
                    উপজেলা-<?= htmlspecialchars($student['upazilla']) ?>, জেলা-<?= htmlspecialchars($student['district']) ?>
                </p>
                <p><strong>জন্ম তারিখ:</strong> <?= htmlspecialchars($student['dob']) ?></p>
                <p><strong>ধর্ম:</strong> <?= htmlspecialchars($student['religion']) ?></p>
                <p><strong>রক্তের গ্রুপ:</strong> <?= htmlspecialchars($student['blood_group']) ?></p>
            </div>

            <div class="card p-3">
                <h5>পঠিত বিষয়সমূহ</h5>
                <ul>
                    <?php foreach ($subjects as $sub): ?>
                        <li>
                            <?= htmlspecialchars($sub['subject_name']) ?> (<?= $sub['type'] ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">কোনো শিক্ষার্থী খুঁজে পাওয়া যায়নি!</div>
        <?php endif; ?>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
