<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}
include '../config/db.php';
?>
<?php include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<?php
// Add subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $class_id = $_POST['class_id'];
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $group = $_POST['group'];
    $type = $_POST['type'];
    $status = $_POST['status'];
    $default_marks = $_POST['default_marks'];
    $has_creative = $_POST['has_creative'];
    $has_objective = $_POST['has_objective'];
    $has_practical = $_POST['has_practical'];
    $pass_type = $_POST['pass_type'];

    $stmt = $conn->prepare("INSERT INTO subjects (class_id, subject_name, subject_code, `group`, type, status, default_marks, has_creative, has_objective, has_practical, pass_type) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssiiiis", $class_id, $subject_name, $subject_code, $group, $type, $status, $default_marks, $has_creative, $has_objective, $has_practical, $pass_type);
    $stmt->execute();
    header("Location: manage_subjects.php");
    exit();
}

// Update subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = $_POST['id'];
    $class_id = $_POST['class_id'];
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $group = $_POST['group'];
    $type = $_POST['type'];
    $status = $_POST['status'];
    $default_marks = $_POST['default_marks'];
    $has_creative = $_POST['has_creative'];
    $has_objective = $_POST['has_objective'];
    $has_practical = $_POST['has_practical'];
    $pass_type = $_POST['pass_type'];

    $stmt = $conn->prepare("UPDATE subjects SET class_id=?, subject_name=?, subject_code=?, `group`=?, type=?, status=?, default_marks=?, has_creative=?, has_objective=?, has_practical=?, pass_type=? WHERE id=?");
    $stmt->bind_param("isssssiiiisi", $class_id, $subject_name, $subject_code, $group, $type, $status, $default_marks, $has_creative, $has_objective, $has_practical, $pass_type, $id);
    $stmt->execute();
    header("Location: manage_subjects.php");
    exit();
}

// Delete subject
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM subjects WHERE id=$id");
    header("Location: manage_subjects.php");
    exit();
}

// Fetch subjects
$subjects = $conn->query("SELECT s.*, c.class_name FROM subjects s JOIN classes c ON s.class_id = c.id ORDER BY s.id DESC");
$classes = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body>
<div class="container mt-4">
    <h4 class="mb-3">বিষয় ব্যবস্থাপনা (Manage Subjects)</h4>

    <!-- Add/Edit Form -->
    <form action="manage_subjects.php" method="POST" class="card p-3 mb-4">
        <input type="hidden" name="action" value="add" id="form_action">
        <input type="hidden" name="id" id="subject_id">

        <div class="row">
            <div class="col-md-4">
                <label class="form-label">শ্রেণি</label>
                <select name="class_id" class="form-select" required id="class_id">
                    <option value="">নির্বাচন করুন</option>
                    <?php while($row = $classes->fetch_assoc()): ?>
                        <option value="<?= $row['id'] ?>"><?= $row['class_name'] ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">বিষয়ের নাম</label>
                <input type="text" name="subject_name" class="form-control" id="subject_name" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">বিষয় কোড</label>
                <input type="text" name="subject_code" class="form-control" id="subject_code" required>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-md-4">
                <label class="form-label">গ্রুপ</label>
                <select name="group" class="form-control" id="group">
                    <option value="">নির্বাচন করুন</option>
                    <option value="none">কোন গ্রুপ না</option>
                    <option value="Science">বিজ্ঞান</option>
                    <option value="Humanities">মানবিক</option>
                    <option value="Business Studies">ব্যবসায় শিক্ষা</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">ধরন</label>
                <select name="type" class="form-select" id="type" required>
                    <option value="Compulsory">Compulsory</option>
                    <option value="Optional">Optional</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">স্ট্যাটাস</label>
                <select name="status" class="form-select" id="status" required>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-md-3">
                <label class="form-label">সৃজনশীল আছে?</label>
                <select name="has_creative" class="form-select" id="has_creative" required>
                    <option value="1">হ্যাঁ</option>
                    <option value="0">না</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">নৈর্ব্যক্তিক আছে?</label>
                <select name="has_objective" class="form-select" id="has_objective" required>
                    <option value="1">হ্যাঁ</option>
                    <option value="0">না</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">ব্যবহারিক আছে?</label>
                <select name="has_practical" class="form-select" id="has_practical" required>
                    <option value="1">হ্যাঁ</option>
                    <option value="0">না</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">পাস টাইপ</label>
                <select name="pass_type" class="form-select" id="pass_type" required>
                    <option value="total">মোট নাম্বার</option>
                    <option value="individual">আলাদা আলাদা</option>
                </select>
            </div>
        </div>

        <div class="row mt-2">
            <div class="col-md-4">
                <label class="form-label">ডিফল্ট পূর্ণমান</label>
                <input type="number" name="default_marks" class="form-control" id="default_marks" value="100" required>
            </div>
        </div>

        <div class="row mt-3">
            <div class="col-md-12 d-flex justify-content-end">
                <button type="submit" class="btn btn-primary" id="submit_btn">Add Subject</button>
            </div>
        </div>
    </form>

    <!-- Subject List -->
    <table class="table table-bordered" id="example1">
        <thead>
            <tr>
                <th>#</th>
                <th>শ্রেণি</th>
                <th>বিষয়</th>
                <th>কোড</th>
                <th>গ্রুপ</th>
                <th>ধরন</th>
                <th>পূর্ণমান</th>
                <th>সৃজনশীল</th>
                <th>নৈর্ব্যক্তিক</th>
                <th>ব্যবহারিক</th>
                <th>পাস টাইপ</th>
                <th>স্ট্যাটাস</th>
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php $i=1; while($row = $subjects->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= $row['class_name'] ?></td>
                <td><?= $row['subject_name'] ?></td>
                <td><?= $row['subject_code'] ?></td>
                <td><?= $row['group'] ?></td>
                <td><?= $row['type'] ?></td>
                <td><?= $row['default_marks'] ?></td>
                <td><?= $row['has_creative'] ? '✔' : '✘' ?></td>
                <td><?= $row['has_objective'] ? '✔' : '✘' ?></td>
                <td><?= $row['has_practical'] ? '✔' : '✘' ?></td>
                <td><?= $row['pass_type'] ?></td>
                <td><?= $row['status'] ?></td>
                <td>
                    <button class="btn btn-sm btn-info" onclick='editSubject(<?= json_encode($row) ?>)'>Edit</button>
                    <a href="?delete=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</a>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<script>
function editSubject(data) {
    document.getElementById('form_action').value = 'edit';
    document.getElementById('subject_id').value = data.id;
    document.getElementById('class_id').value = data.class_id;
    document.getElementById('subject_name').value = data.subject_name;
    document.getElementById('subject_code').value = data.subject_code;
    document.getElementById('group').value = data.group;
    document.getElementById('type').value = data.type;
    document.getElementById('status').value = data.status;

    document.getElementById('default_marks').value = data.default_marks;
    document.getElementById('has_creative').value = data.has_creative;
    document.getElementById('has_objective').value = data.has_objective;
    document.getElementById('has_practical').value = data.has_practical;
    document.getElementById('pass_type').value = data.pass_type;

    document.getElementById('submit_btn').innerText = 'Update Subject';
}
</script>
</body>
</html>
