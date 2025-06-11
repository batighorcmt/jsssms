<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<div class="container mt-4">
    <h4>তৈরিকৃত পরীক্ষার তালিকা</h4>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>ক্রমিক</th>
                <th>পরীক্ষার নাম</th>
                <th>শ্রেণি</th>
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
            $sql = "
                SELECT 
                    exams.id AS exam_id,
                    exams.exam_name,
                    classes.class_name,
                    exam_subjects.id AS exam_subject_id,
                    subjects.subject_name,
                    exam_subjects.exam_date,
                    exam_subjects.exam_time,
                    exam_subjects.creative_marks,
                    exam_subjects.objective_marks,
                    exam_subjects.practical_marks,
                    exam_subjects.total_marks
                FROM exams
                JOIN classes ON exams.class_id = classes.id
                JOIN exam_subjects ON exams.id = exam_subjects.exam_id
                JOIN subjects ON exam_subjects.subject_id = subjects.id
                ORDER BY exams.exam_name, classes.class_name, subjects.subject_name
            ";
            $result = $conn->query($sql);
            $i = 1;
            while ($row = $result->fetch_assoc()):
            ?>
                <tr>
                    <td><?= $i++; ?></td>
                    <td><?= htmlspecialchars($row['exam_name']) ?></td>
                    <td><?= htmlspecialchars($row['class_name']) ?></td>
                    <td><?= htmlspecialchars($row['subject_name']) ?></td>
                    <td><?= htmlspecialchars($row['exam_date']) ?></td>
                    <td><?= htmlspecialchars($row['exam_time']) ?></td>
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
