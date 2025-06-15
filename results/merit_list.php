<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">মেরিট লিস্ট রিপোর্ট</h5>
        </div>
        <div class="card-body">
            <form id="meritForm" method="POST">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">পরীক্ষা</label>
                        <select class="form-select" name="exam_id" required>
                            <option value="">-- নির্বাচন করুন --</option>
                            <?php
                            $res = $conn->query("SELECT id, exam_name FROM exams ORDER BY id DESC");
                            while ($row = $res->fetch_assoc()):
                            ?>
                                <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['exam_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">শ্রেণি</label>
                        <select class="form-select" name="class_id" required>
                            <option value="">-- নির্বাচন করুন --</option>
                            <?php
                            $res = $conn->query("SELECT id, class_name FROM classes ORDER BY id ASC");
                            while ($row = $res->fetch_assoc()):
                            ?>
                                <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['class_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">সাল</label>
                        <select class="form-select" name="year" required>
                            <option value="">-- নির্বাচন করুন --</option>
                            <?php
                            $res = $conn->query("SELECT DISTINCT year FROM students ORDER BY year DESC");
                            while ($row = $res->fetch_assoc()):
                            ?>
                                <option value="<?= $row['year'] ?>"><?= $row['year'] ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-success">মেরিট লিস্ট তৈরি করুন</button>
                </div>
            </form>

            <div id="resultArea" class="mt-5"></div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
document.getElementById('meritForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    fetch('generate_merit_data.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        document.getElementById('resultArea').innerHTML = data;
    })
    .catch(err => {
        document.getElementById('resultArea').innerHTML = '<div class="alert alert-danger">লোড করতে সমস্যা হয়েছে!</div>';
    });
});
</script>
