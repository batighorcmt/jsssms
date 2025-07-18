<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

include '../config/db.php';

// Get student ID from URL
$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    $_SESSION['error'] = "শিক্ষার্থী ID পাওয়া যায়নি";
    header("Location: manage_student.php");
    exit;
}

// Fetch student data
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

if (!$student) {
    $_SESSION['error'] = "শিক্ষার্থী পাওয়া যায়নি";
    header("Location: manage_student.php");
    exit;
}

// Fetch all classes for dropdown
$classes = $conn->query("SELECT * FROM classes");

// Fetch sections for the student's class
$sections = [];
if ($student['class_id']) {
    $section_stmt = $conn->prepare("SELECT * FROM sections WHERE class_id = ?");
    $section_stmt->bind_param("i", $student['class_id']);
    $section_stmt->execute();
    $sections = $section_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<?php include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

<div class="container-fluid py-4">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">শিক্ষার্থী তথ্য সম্পাদনা করুন</h4>
                <a href="manage_students.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left"></i> ফিরে যান
                </a>
            </div>
        </div>
        
        <div class="card-body">
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form action="update_student.php" method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($student['student_id']) ?>">
                
                <div class="row g-3">
                    <!-- Personal Information Section -->
                    <div class="col-md-12">
                        <h5 class="text-primary mb-3"><i class="bi bi-person-vcard"></i> ব্যক্তিগত তথ্য</h5>
                    </div>

                    <!-- Student Name -->
                    <div class="col-md-6">
                        <label class="form-label">শিক্ষার্থীর নাম <span class="text-danger">*</span></label>
                        <input type="text" name="student_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['student_name']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে শিক্ষার্থীর নাম লিখুন</div>
                    </div>

                    <!-- Father's Name -->
                    <div class="col-md-6">
                        <label class="form-label">পিতার নাম <span class="text-danger">*</span></label>
                        <input type="text" name="father_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['father_name']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে পিতার নাম লিখুন</div>
                    </div>

                    <!-- Mother's Name -->
                    <div class="col-md-6">
                        <label class="form-label">মাতার নাম <span class="text-danger">*</span></label>
                        <input type="text" name="mother_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['mother_name']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে মাতার নাম লিখুন</div>
                    </div>

                    <!-- Date of Birth -->
                    <div class="col-md-6">
                        <label class="form-label">জন্ম তারিখ <span class="text-danger">*</span></label>
                        <input type="date" name="date_of_birth" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['date_of_birth']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে জন্ম তারিখ নির্বাচন করুন</div>
                    </div>

                    <!-- Gender -->
                    <div class="col-md-4">
                        <label class="form-label">লিঙ্গ <span class="text-danger">*</span></label>
                        <select name="gender" class="form-select form-select-lg" required>
                            <option value="">নির্বাচন করুন</option>
                            <option value="Male" <?= $student['gender'] == 'Male' ? 'selected' : '' ?>>পুরুষ</option>
                            <option value="Female" <?= $student['gender'] == 'Female' ? 'selected' : '' ?>>মহিলা</option>
                            <option value="Other" <?= $student['gender'] == 'Other' ? 'selected' : '' ?>>অন্যান্য</option>
                        </select>
                        <div class="invalid-feedback">অনুগ্রহ করে লিঙ্গ নির্বাচন করুন</div>
                    </div>

                    <!-- Religion -->
                    <div class="col-md-4">
                        <label class="form-label">ধর্ম <span class="text-danger">*</span></label>
                        <select name="religion" class="form-select form-select-lg" required>
                            <option value="">নির্বাচন করুন</option>
                            <option value="Islam" <?= $student['religion'] == 'Islam' ? 'selected' : '' ?>>ইসলাম</option>
                            <option value="Hindu" <?= $student['religion'] == 'Hindu' ? 'selected' : '' ?>>হিন্দু</option>
                            <option value="Christian" <?= $student['religion'] == 'Christian' ? 'selected' : '' ?>>খ্রিষ্টান</option>
                            <option value="Buddhist" <?= $student['religion'] == 'Buddhist' ? 'selected' : '' ?>>বৌদ্ধ</option>
                        </select>
                        <div class="invalid-feedback">অনুগ্রহ করে ধর্ম নির্বাচন করুন</div>
                    </div>

                    <!-- Blood Group -->
                    <div class="col-md-4">
                        <label class="form-label">ব্লাড গ্রুপ</label>
                        <select name="blood_group" class="form-select form-select-lg">
                            <option value="">নির্বাচন করুন</option>
                            <option value="A+" <?= $student['blood_group'] == 'A+' ? 'selected' : '' ?>>A+</option>
                            <option value="A-" <?= $student['blood_group'] == 'A-' ? 'selected' : '' ?>>A-</option>
                            <option value="B+" <?= $student['blood_group'] == 'B+' ? 'selected' : '' ?>>B+</option>
                            <option value="B-" <?= $student['blood_group'] == 'B-' ? 'selected' : '' ?>>B-</option>
                            <option value="O+" <?= $student['blood_group'] == 'O+' ? 'selected' : '' ?>>O+</option>
                            <option value="O-" <?= $student['blood_group'] == 'O-' ? 'selected' : '' ?>>O-</option>
                            <option value="AB+" <?= $student['blood_group'] == 'AB+' ? 'selected' : '' ?>>AB+</option>
                            <option value="AB-" <?= $student['blood_group'] == 'AB-' ? 'selected' : '' ?>>AB-</option>
                        </select>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="col-md-12 mt-4">
                        <h5 class="text-primary mb-3"><i class="bi bi-telephone"></i> যোগাযোগের তথ্য</h5>
                    </div>

                    <!-- Mobile Number -->
                    <div class="col-md-6">
                        <label class="form-label">মোবাইল নাম্বার</label>
                        <input type="text" name="mobile_no" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['mobile_no']) ?>">
                    </div>

                    <!-- Address -->
                    <div class="col-md-6">
                        <label class="form-label">গ্রাম</label>
                        <input type="text" name="village" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['village']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">ডাকঘর</label>
                        <input type="text" name="post_office" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['post_office']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">উপজেলা</label>
                        <input type="text" name="upazilla" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['upazilla']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">জেলা</label>
                        <input type="text" name="district" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['district']) ?>">
                    </div>

                    <!-- Academic Information Section -->
                    <div class="col-md-12 mt-4">
                        <h5 class="text-primary mb-3"><i class="bi bi-book"></i> একাডেমিক তথ্য</h5>
                    </div>

                    <!-- Class -->
                    <div class="col-md-4">
                        <label class="form-label">শ্রেণি <span class="text-danger">*</span></label>
                        <select name="class_id" id="class_id" class="form-select form-select-lg" required>
                            <option value="">নির্বাচন করুন</option>
                            <?php while ($class = $classes->fetch_assoc()): ?>
                                <option value="<?= $class['id'] ?>" 
                                    <?= $student['class_id'] == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div class="invalid-feedback">অনুগ্রহ করে শ্রেণি নির্বাচন করুন</div>
                    </div>

                    <!-- Section -->
                    <div class="col-md-4">
                        <label class="form-label">শাখা <span class="text-danger">*</span></label>
                        <select name="section_id" id="section_id" class="form-select form-select-lg" required>
                            <option value="">শাখা নির্বাচন করুন</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= $section['id'] ?>" 
                                    <?= $student['section_id'] == $section['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($section['section_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">অনুগ্রহ করে শাখা নির্বাচন করুন</div>
                    </div>

                    <!-- Group -->
                    <div class="col-md-4">
                        <label class="form-label">গ্রুপ</label>
                        <select name="student_group" class="form-select form-select-lg">
                            <option value="">নির্বাচন করুন</option>
                            <option value="none" <?= $student['student_group'] == 'none' ? 'selected' : '' ?>>কোন গ্রুপ না</option>
                            <option value="Science" <?= $student['student_group'] == 'Science' ? 'selected' : '' ?>>বিজ্ঞান</option>
                            <option value="Humanities" <?= $student['student_group'] == 'Humanities' ? 'selected' : '' ?>>মানবিক</option>
                            <option value="Business Studies" <?= $student['student_group'] == 'Business Studies' ? 'selected' : '' ?>>ব্যবসায় শিক্ষা</option>
                        </select>
                    </div>

                    <!-- Roll No & Year -->
                    <div class="col-md-4">
                        <label class="form-label">রোল নং <span class="text-danger">*</span></label>
                        <input type="text" name="roll_no" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['roll_no']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে রোল নং লিখুন</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">শিক্ষাবর্ষ <span class="text-danger">*</span></label>
                        <input type="text" name="year" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['year']) ?>" required>
                        <div class="invalid-feedback">অনুগ্রহ করে শিক্ষাবর্ষ লিখুন</div>
                    </div>

                    <!-- Photo -->
                    <div class="col-md-4">
                        <label class="form-label">ছবি</label>
                        <input type="file" name="student_photo" id="student_photo" class="form-control form-control-lg" 
                               accept="image/*" onchange="previewImage(this)">
                        <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($student['student_photo']) ?>
                        
                        <div class="mt-3 text-center">
                            <div id="imagePreview" style="width: 150px; height: 180px; border: 1px dashed #ccc; margin: 0 auto; position: relative;">
                                <?php if ($student['student_photo']): ?>
                                    <img src="../uploads/students/<?= htmlspecialchars($student['student_photo']) ?>" 
                                         alt="Current Photo" style="max-width: 100%; max-height: 100%; object-fit: cover;">
                                <?php else: ?>
                                    <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                                        <i class="bi bi-person-bounding-box" style="font-size: 3rem;"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <small class="text-muted d-block mt-1">ছবির সাইজ সর্বোচ্চ 500KB</small>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="reset" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-counterclockwise"></i> রিসেট করুন
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle"></i> আপডেট করুন
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<script>
    // AJAX to load sections based on class
    document.getElementById('class_id').addEventListener('change', function () {
        var classId = this.value;
        fetch('../ajax/get_sections.php?class_id=' + classId)
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

    // Image preview function
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        const file = input.files[0];
        
        if (file) {
            const reader = new FileReader();
            
            reader.onload = function(e) {
                preview.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100%; max-height: 100%; object-fit: cover;">`;
            }
            
            reader.readAsDataURL(file);
        }
    }

    // Form validation
    (function () {
        'use strict'
        
        // Fetch all the forms we want to apply custom Bootstrap validation styles to
        var forms = document.querySelectorAll('.needs-validation')
        
        // Loop over them and prevent submission
        Array.prototype.slice.call(forms)
            .forEach(function (form) {
                form.addEventListener('submit', function (event) {
                    if (!form.checkValidity()) {
                        event.preventDefault()
                        event.stopPropagation()
                    }
                    
                    form.classList.add('was-validated')
                }, false)
            })
    })()
</script>

<?php include '../includes/footer.php'; ?>