<?php
$ALLOWED_ROLES = ['super_admin'];
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
include '../config/db.php';
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Add Student</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>students/manage_students.php">Students</a></li>
                        <li class="breadcrumb-item active">Add</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="container mt-4">
                <h4 class="mb-3">Add Student</h4>
                <form action="save_student.php" method="POST" enctype="multipart/form-data">
                    <div class="row">
                        <!-- Student Name -->
                        <div class="col-md-6 mb-3">
                            <label>Student Name</label>
                            <input type="text" name="student_name" class="form-control" required>
                        </div>

                        <!-- Father's Name -->
                        <div class="col-md-6 mb-3">
                            <label>Father's Name</label>
                            <input type="text" name="father_name" class="form-control" required>
                        </div>

                        <!-- Mother's Name -->
                        <div class="col-md-6 mb-3">
                            <label>Mother's Name</label>
                            <input type="text" name="mother_name" class="form-control" required>
                        </div>

                        <!-- Date of Birth -->
                        <div class="col-md-6 mb-3">
                            <label>Date of Birth</label>
                            <input type="text" name="date_of_birth" class="form-control date-input" placeholder="dd/mm/yyyy" required>
                        </div>

                        <!-- Gender -->
                        <div class="col-md-4 mb-3">
                            <label>Gender</label>
                            <select name="gender" class="form-control" required>
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>

                        <!-- Religion -->
                        <div class="col-md-4 mb-3">
                            <label>Religion</label>
                            <select name="religion" class="form-control" required>
                                <option value="">Select</option>
                                <option value="Islam">Islam</option>
                                <option value="Hinduism">Hinduism</option>
                                <option value="Christianity">Christianity</option>
                                <option value="Buddhism">Buddhism</option>
                            </select>
                        </div>

                        <!-- Blood Group -->
                        <div class="col-md-4 mb-3">
                            <label>Blood Group</label>
                            <select name="blood_group" class="form-control">
                                <option value="">Select</option>
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
                            <label>Mobile Number</label>
                            <input type="text" name="mobile_no" class="form-control">
                        </div>

                        <!-- Address -->
                        <div class="col-md-6 mb-3">
                            <label>Village</label>
                            <input type="text" name="village" class="form-control">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>Post Office</label>
                            <input type="text" name="post_office" class="form-control">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>Upazila</label>
                            <input type="text" name="upazilla" class="form-control">
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>District</label>
                            <input type="text" name="district" class="form-control">
                        </div>

                        <!-- Class -->
                        <div class="col-md-4 mb-3">
                            <label>Class</label>
                            <select name="class_id" id="class_id" class="form-control" required>
                                <option value="">Select</option>
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
                            <label>Section</label>
                            <select name="section_id" id="section_id" class="form-control" required>
                                <option value="">Select class first</option>
                            </select>
                        </div>

                        <!-- Group -->
                        <div class="col-md-4 mb-3">
                            <label>Group</label>
                            <select name="student_group" class="form-control">
                                <option value="">Select</option>
                                <option value="None">None</option>
                                <option value="Science">Science</option>
                                <option value="Humanities">Humanities</option>
                                <option value="Business Studies">Business Studies</option>
                            </select>
                        </div>

            <!-- Roll No & Year -->
                        <div class="col-md-4 mb-3">
                            <label>Roll No</label>
                            <input type="text" name="roll_no" class="form-control" required>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>Year</label>
                            <input type="text" value="2025" name="year" class="form-control" required>
                        </div>

                        <!-- Photo -->
                        <div class="col-md-4 mb-3">
                            <label>Photo</label>
                            <input type="file" name="student_photo" id="student_photo" class="form-control" accept="image/*">
                            <small class="text-muted">JPG/PNG • Max 2MB</small>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">Add Student</button>
                </form>
            </div> <!-- /.container -->
        </div> <!-- /.container-fluid -->
    </section>
</div> <!-- /.content-wrapper -->

<script>
    // AJAX to load sections based on class
    document.getElementById('class_id').addEventListener('change', function () {
        var classId = this.value;
        fetch('../ajax/get_sections.php?class_id=' + encodeURIComponent(classId))
            .then(res => res.json())
            .then(data => {
                let section = document.getElementById('section_id');
                section.innerHTML = '<option value="">Select Section</option>';
                data.forEach(row => {
                    let option = document.createElement('option');
                    option.value = row.id;
                    option.textContent = row.section_name;
                    section.appendChild(option);
                });
            });
    });
</script>

<script>
// Client-side 2MB size check for add page
(function(){
    var inp = document.getElementById('student_photo');
    if(!inp) return;
    inp.addEventListener('change', function(){
        if (this.files && this.files[0]){
            var max = 2 * 1024 * 1024; // 2MB
            if (this.files[0].size > max){
                alert('Image is too large. Maximum allowed size is 2MB.');
                this.value = '';
            }
        }
    });
})();
</script>

<?php include '../includes/footer.php'; ?>
