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
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>তৈরিকৃত পরীক্ষার তালিকা</h4>
        <a href="create_exam.php" class="btn btn-success">+ নতুন পরীক্ষা</a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>ক্রমিক</th>
                <th>পরীক্ষার নাম</th>
                <th>শ্রেণি</th>
                <th>বছর</th>
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sql = "SELECT exams.id, exams.exam_name, exams.class_id, classes.class_name 
                    FROM exams 
                    JOIN classes ON exams.class_id = classes.id 
                    ORDER BY exams.exam_name ASC";
            $result = $conn->query($sql);
            $i = 1;
            while ($row = $result->fetch_assoc()):
                $exam_id = $row['id'];
                $class_id = $row['class_id'];
                $year = date('Y'); // চাইলে এখানে dynamic year বসাতে পারেন
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['exam_name']) ?></td>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?php echo $year; ?></td>
                <td>
                    <style>
    .custom-dropdown {
        position: relative;
        display: inline-block;
    }

    .custom-dropdown-menu {
        display: none;
        position: absolute;
        z-index: 1000;
        min-width: 160px;
        background-color: white;
        border: 1px solid #ddd;
        box-shadow: 0 2px 5px rgba(0,0,0,0.15);
    }

    .custom-dropdown-menu a {
        display: block;
        padding: 8px 12px;
        text-decoration: none;
        color: #333;
    }

    .custom-dropdown-menu a:hover {
        background-color: #f1f1f1;
    }

    .custom-dropdown.show .custom-dropdown-menu {
        display: block;
    }
</style>

<div class="custom-dropdown">
    <button class="btn btn-sm btn-primary" onclick="toggleDropdown(this)">নির্বাচন করুন</button>
    <div class="custom-dropdown-menu">
        <a href="exam_details.php?exam_id=<?= $exam_id ?>">বিস্তারিত</a>
        <a href="../results/tabulation_sheet.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">টেবুলেশন শীট রোল নং অনুসারে</a>
        <a href="../results/tabulation_sheet_sort.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">টেবুলেশন শীট মেধাক্রম অনুসারে</a>
        <a href="../results/marksheet.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">একাডেমিক ট্রান্সক্রিপ্ট</a>
        <a href="../results/merit_list_sort.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">মেরিট লিস্ট</a>
        <a href="../results/statistics.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">সার্বিক পরিসংখ্যান</a>
    </div>
</div>

<script>
    function toggleDropdown(button) {
        const dropdown = button.parentElement;
        dropdown.classList.toggle('show');
        
        // Close others
        document.querySelectorAll('.custom-dropdown').forEach(d => {
            if (d !== dropdown) d.classList.remove('show');
        });
    }

    // Optional: Close when clicking outside
    window.addEventListener('click', function(e) {
        if (!e.target.matches('.btn')) {
            document.querySelectorAll('.custom-dropdown').forEach(d => d.classList.remove('show'));
        }
    });
</script>

                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</div>
<?php include '../includes/footer.php'; ?>
