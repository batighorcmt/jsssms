<?php  
$ALLOWED_ROLES = ['super_admin'];
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
include '../config/db.php';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="bn">Created Exams</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Exams</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>Created Exams</h4>
    <a href="<?= BASE_URL ?>settings/create_exam.php" class="btn btn-success">+ New Exam</a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
    <?php endif; ?>

    <table class="table table-bordered table-hover">
        <thead class="table-light">
            <tr>
                <th>No.</th>
                <th>Exam Name</th>
                <th>Class</th>
                <th>Year</th>
                <th>Actions</th>
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
                $year = date('Y'); // Use dynamic year if needed
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
    <button class="btn btn-sm btn-primary" onclick="toggleDropdown(this)">Actions</button>
    <div class="custom-dropdown-menu">
        <a href="<?= BASE_URL ?>settings/exam_details.php?exam_id=<?= $exam_id ?>">Details</a>
        <a href="<?= BASE_URL ?>settings/update_exam.php?exam_id=<?= $exam_id ?>">Bulk Update</a>
        <a href="<?= BASE_URL ?>exam/admit_v2.php?exam_id=<?= $exam_id ?>">Admit Card (V2)</a>
        <a href="<?= BASE_URL ?>exam/admit_v3.php?exam_id=<?= $exam_id ?>">Admit Card (V3)</a>
        <a href="<?= BASE_URL ?>exam/exam_attendance.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">Exam Attendance</a>
        <a href="<?= BASE_URL ?>exam/blank_mark_entry_form.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">Blank Mark Entry Sheet</a>
        <a href="<?= BASE_URL ?>exam/filled_mark_entry_form.php?exam_id=<?= $exam_id ?>&class_id=<?= $class_id ?>&year=<?= $year ?>">Filled Mark Entry Sheet</a>
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
    </section>
</div>
<?php include '../includes/footer.php'; ?>
