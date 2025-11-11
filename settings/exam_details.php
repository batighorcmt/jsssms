<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . BASE_URL . "auth/login.php");
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
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5 class="bn">পরীক্ষার বিস্তারিত: <?= htmlspecialchars($exam_info['exam_name']) ?> (<?= htmlspecialchars($exam_info['class_name']) ?>)</h5>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>settings/manage_exams.php">Manage Exams</a></li>
                        <li class="breadcrumb-item active">Details</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="mb-3">
                <a href="<?= BASE_URL ?>settings/manage_exams.php" class="btn btn-secondary btn-sm">← Manage Exams</a>
                <a href="<?= BASE_URL ?>settings/create_exam.php" class="btn btn-primary btn-sm">+ Create New Exam</a>
            </div>

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
        </div><!-- /.container-fluid -->
    </section>
</div><!-- /.content-wrapper -->

<script>
 (function(){
   // Read status from query string and show toast
   const params = new URLSearchParams(window.location.search);
   const status = params.get('status');
   const msg = params.get('msg');
   if (status && window.showToast){
     let title = status === 'success' ? 'সফল' : 'বার্তা';
     let body = 'অপারেশন সম্পন্ন হয়েছে';
     if (msg === 'created') body = 'পরীক্ষা সফলভাবে তৈরি করা হয়েছে।';
     if (msg === 'updated') body = 'পরীক্ষা সফলভাবে আপডেট হয়েছে।';
     if (msg === 'deleted') body = 'তথ্য সফলভাবে মুছে ফেলা হয়েছে।';
     window.showToast(title, body, status);
   }
 })();
</script>
<?php include '../includes/footer.php'; ?>
