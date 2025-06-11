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
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sql = "SELECT exams.id, exams.exam_name, classes.class_name 
                    FROM exams 
                    JOIN classes ON exams.class_id = classes.id 
                    ORDER BY exams.exam_name ASC";
            $result = $conn->query($sql);
            $i = 1;
            while ($row = $result->fetch_assoc()):
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['exam_name']) ?></td>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td>
                    <a href="exam_details.php?exam_id=<?= $row['id'] ?>" class="btn btn-sm btn-primary">বিস্তারিত</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>
<?php include '../includes/footer.php'; ?>
