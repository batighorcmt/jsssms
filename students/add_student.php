<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

include '../config/db.php';
?>

<?php include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<div class="container mt-4">
    <h4>শিক্ষার্থী যুক্ত করুন</h4>
    <form action="save_student.php" method="POST" enctype="multipart/form-data">
        <div class="row">
            <!-- Student Name -->
            <div class="col-md-6 mb-3">
                <label>শিক্ষার্থীর নাম</label>
                <input type="text" name="student_name" class="form-control" required>
            </div>

            <!-- Father's Name -->
            <div class="col-md-6 mb-3">
                <label>পিতার নাম</label>
                <input type="text" name="father_name" class="form-control" required>
            </div>

            <!-- Mother's Name -->
            <div class="col-md-6 mb-3">
                <label>মাতার নাম</label>
                <input type="text" name="mother_name" class="form-control" required>
            </div>

            <!-- Date of Birth -->
            <div class="col-md-6 mb-3">
                <label>জন্ম তারিখ</label>
                <input type="date" name="date_of_birth" class="form-control" required>
            </div>

            <!-- Gender -->
            <div class="col-md-4 mb-3">
                <label>লিঙ্গ</label>
                <select name="gender" class="form-control" required>
                    <option value="">নির্বাচন করুন</option>
                    <option value="Male">পুরুষ</option>
                    <option value="Female">মহিলা</option>
                    <option value="Other">অন্যান্য</option>
                </select>
            </div>

            <!-- Religion -->
            <div class="col-md-4 mb-3">
                <label>ধর্ম</label>
                <select name="religion" class="form-control" required>
                    <option value="">নির্বাচন করুন</option>
                    <option value="Islam">ইসলাম</option>
                    <option value="Hindu">হিন্দু</option>
                    <option value="Christian">খ্রিষ্টান</option>
                    <option value="Buddhist">বৌদ্ধ</option>
                </select>
            </div>

            <!-- Blood Group -->
            <div class="col-md-4 mb-3">
                <label>ব্লাড গ্রুপ</label>
                <select name="blood_group" class="form-control">
                    <option value="">নির্বাচন করুন</option>
                    <option value="A+">A+</option>
                    <option value="A-">A-</option>
                    <option value="B+">B+</option>
                    <option value="B-">B-</option>
                    <option value="O+">O+</option>
                    <option value="O-">O-</option>
                    <option value="AB+">AB+</option>
                    <option value="AB-">AB-</option>
                </select>
            </div>

            <!-- Mobile Number -->
            <div class="col-md-6 mb-3">
                <label>মোবাইল নাম্বার</label>
                <input type="text" name="mobile_no" class="form-control">
            </div>

            <!-- Address -->
            <div class="col-md-6 mb-3">
                <label>গ্রাম</label>
                <input type="text" name="village" class="form-control">
            </div>

            <div class="col-md-4 mb-3">
                <label>ডাকঘর</label>
                <input type="text" name="post_office" class="form-control">
            </div>

            <div class="col-md-4 mb-3">
                <label>উপজেলা</label>
                <input type="text" name="upazilla" class="form-control">
            </div>

            <div class="col-md-4 mb-3">
                <label>জেলা</label>
                <input type="text" name="district" class="form-control">
            </div>

            <!-- Class -->
            <div class="col-md-4 mb-3">
                <label>শ্রেণি</label>
                <select name="class_id" id="class_id" class="form-control" required>
                    <option value="">নির্বাচন করুন</option>
                    <?php
                    $result = $conn->query("SELECT * FROM classes");
                    while ($row = $result->fetch_assoc()) {
                        echo "<option value='{$row['id']}'>{$row['class_name']}</option>";
                    }
                    ?>
                </select>
            </div>

            <!-- Section -->
            <div class="col-md-4 mb-3">
                <label>শাখা</label>
                <select name="section_id" id="section_id" class="form-control" required>
                    <option value="">প্রথমে শ্রেণি নির্বাচন করুন</option>
                </select>
            </div>

            <!-- Group -->
            <div class="col-md-4 mb-3">
                <label>গ্রুপ</label>
                <select name="student_group" class="form-control">
                    <option value="">নির্বাচন করুন</option>
                    <option value="none">কোন গ্রুপ না</option>
                    <option value="Science">বিজ্ঞান</option>
                    <option value="Humanities">মানবিক</option>
                    <option value="Business Studies">ব্যবসায় শিক্ষা</option>
                </select>
            </div>

            <!-- Roll No & Year -->
            <div class="col-md-4 mb-3">
                <label>রোল নং</label>
                <input type="text" name="roll_no" class="form-control" required>
            </div>

            <div class="col-md-4 mb-3">
                <label>শিক্ষাবর্ষ</label>
                <input type="text" value="2025" name="year" class="form-control" required>
            </div>

            <!-- Photo -->
            <div class="col-md-4 mb-3">
                <label>ছবি</label>
                <input type="file" name="student_photo" class="form-control">
            </div>
        </div>

        <button type="submit" class="btn btn-primary">Add Student</button>
    </form>
</div>
</div>

<script>
    // AJAX to load sections based on class
    document.getElementById('class_id').addEventListener('change', function () {
        var classId = this.value;
        fetch('ajax/get_sections.php?class_id=' + classId)
            .then(res => res.json())
            .then(data => {
                let section = document.getElementById('section_id');
                section.innerHTML = '<option value="">শাখা নির্বাচন করুন</option>';
                data.forEach(row => {
                    let option = document.createElement('option');
                    option.value = row.id;
                    option.textContent = row.section_name;
                    section.appendChild(option);
                });
            });
    });
</script>

<?php include '../includes/footer.php'; ?>
