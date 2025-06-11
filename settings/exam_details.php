<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';

$exam_id = intval($_GET['exam_id'] ?? 0);
if ($exam_id <= 0) {
    echo '<div class="alert alert-danger">অবৈধ পরীক্ষা আইডি।</div>';
    exit;
}

// পরীক্ষার নাম ও শ্রেণি
$exam_info = $conn->query("SELECT exams.exam_name, classes.class_name 
                          FROM exams 
                          JOIN classes ON exams.class_id = classes.id 
                          WHERE exams.id = $exam_id")->fetch_assoc();

if (!$exam_info) {
    echo '<div class="alert alert-danger">পরীক্ষার তথ্য পাওয়া যায়নি।</div>';
    exit;
}
?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<div class="container mt-4">
    <h5>পরীক্ষার বিস্তারিত: <?= htmlspecialchars($exam_info['exam_name']) ?> (<?= htmlspecialchars($exam_info['class_name']) ?>)</h5>
    <a href="exam_list.php" class="btn btn-sm btn-secondary mb-3">← ফিরে যান</a>

    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>ক্রমিক</th>
                <th>বিষয়</th>
                <th>তারিখ</th>
                <th>সময়</th>
                <th>সৃজনশীল</th>
                <th>নৈর্ব্যক্তিক</th>
                <th>ব্যবহারিক</th>
                <th>মোট</th>
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sql = "SELECT es.id AS exam_subject_id, s.subject_name, es.exam_date, es.exam_time, 
                           es.creative_marks, es.objective_marks, es.practical_marks, es.total_marks
                    FROM exam_subjects es 
                    JOIN subjects s ON es.subject_id = s.id
                    WHERE es.exam_id = $exam_id
                    ORDER BY es.exam_date ASC";
            $result = $conn->query($sql);
            $i = 1;
            while ($row = $result->fetch_assoc()):
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['subject_name']) ?></td>
                <td><?= date('d/m/Y', strtotime($row['exam_date'])) ?></td>
                <td><?= date('h:i A', strtotime($row['exam_time'])) ?></td>
                <td><?= $row['creative_marks'] ?></td>
                <td><?= $row['objective_marks'] ?></td>
                <td><?= $row['practical_marks'] ?></td>
                <td><?= $row['total_marks'] ?></td>
                <td>
                    <a href="edit_exam.php?id=<?= $row['exam_subject_id'] ?>" class="btn btn-sm btn-info">এডিট</a>
                    <a href="delete_exam.php?id=<?= $row['exam_subject_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('আপনি কি নিশ্চিতভাবে ডিলিট করতে চান?')">ডিলিট</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>
<?php include '../includes/footer.php'; ?>
