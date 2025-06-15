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
<?php
include '../includes/sidebar.php';
?>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">মেধা তালিকা তৈরি করুন</h5>
        </div>
        <div class="card-body">
            <form id="meritForm" class="row g-3">
                <div class="col-md-4">
                    <label for="exam_id" class="form-label">পরীক্ষা</label>
                    <select name="exam_id" id="exam_id" class="form-select" required>
                        <option value="">নির্বাচন করুন</option>
                        <?php
                        $examResult = $conn->query("SELECT id, exam_name FROM exams ORDER BY id DESC");
                        while ($exam = $examResult->fetch_assoc()):
                        ?>
                            <option value="<?= $exam['id'] ?>"><?= htmlspecialchars($exam['exam_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="class_id" class="form-label">শ্রেণি</label>
                    <select name="class_id" id="class_id" class="form-select" required>
                        <option value="">নির্বাচন করুন</option>
                        <?php
                        $classResult = $conn->query("SELECT id, class_name FROM classes ORDER BY id ASC");
                        while ($class = $classResult->fetch_assoc()):
                        ?>
                            <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label for="year" class="form-label">সাল</label>
                    <select name="year" id="year" class="form-select" required>
                        <option value="">নির্বাচন করুন</option>
                        <?php
                        for ($y = date('Y'); $y >= 2020; $y--):
                        ?>
                            <option value="<?= $y ?>"><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-12 text-end mt-3">
                    <button type="submit" class="btn btn-success">মেধা তালিকা তৈরি করুন</button>
                </div>
            </form>
        </div>
    </div>

    <div class="mt-4" id="printArea"></div>
</div>

<script>
document.getElementById('meritForm').addEventListener('submit', function(e) {
    e.preventDefault();

    let exam_id = document.getElementById('exam_id').value;
    let class_id = document.getElementById('class_id').value;
    let year = document.getElementById('year').value;

    if (!exam_id || !class_id || !year) {
        alert("অনুগ্রহ করে সব তথ্য দিন।");
        return;
    }

    const formData = new FormData();
    formData.append('exam_id', exam_id);
    formData.append('class_id', class_id);
    formData.append('year', year);

    fetch('generate_merit_data.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.text())
    .then(data => {
        document.getElementById('printArea').innerHTML = data;
    })
    .catch(err => {
        alert("লোড করতে সমস্যা হয়েছে: " + err);
    });
});

// ✅ প্রিন্ট ফাংশন
function printMerit() {
    var printContents = document.getElementById('printArea').innerHTML;
    var newWindow = window.open('', '', 'height=800,width=1000');
    newWindow.document.write('<html><head><title>Merit List</title>');
    newWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
    newWindow.document.write('</head><body>');
    newWindow.document.write(printContents);
    newWindow.document.write('</body></html>');
    newWindow.document.close();
    newWindow.focus();
    newWindow.print();
    newWindow.close();
}

</script>

<?php include '../includes/footer.php'; ?>



