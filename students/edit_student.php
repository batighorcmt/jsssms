<?php
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
$ALLOWED_ROLES = ['super_admin'];
include '../config/db.php';

// Get student ID from URL
$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    $_SESSION['error'] = "Student ID not provided";
    header("Location: manage_students.php");
    exit;
}

// Fetch student data
$stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

if (!$student) {
    $_SESSION['error'] = "Student not found";
    header("Location: manage_students.php");
    exit;
}

// Fetch all classes for dropdown
$classes = $conn->query("SELECT * FROM classes");

// Fetch sections for the student's class
$sections = [];
if (!empty($student['class_id'])) {
    $section_stmt = $conn->prepare("SELECT * FROM sections WHERE class_id = ?");
    $section_stmt->bind_param("i", $student['class_id']);
    $section_stmt->execute();
    $sections = $section_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Prepare Date of Birth display value with default 01/01/1970 when empty/invalid
function formatDobForInput($value) {
    $default = '01/01/1970';
    if (!isset($value)) return $default;
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00') return $default;

    // yyyy-mm-dd
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if ($dt && $dt->format('Y-m-d') === $value) {
            return $dt->format('d/m/Y');
        }
        return $default;
    }

    // dd/mm/yyyy
    if (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value)) {
        $dt = DateTime::createFromFormat('d/m/Y', $value);
        if ($dt) {
            return $dt->format('d/m/Y');
        }
        return $default;
    }

    // Try generic parse
    $ts = strtotime($value);
    if ($ts !== false) {
        return date('d/m/Y', $ts);
    }
    return $default;
}

$dob_display = formatDobForInput($student['date_of_birth'] ?? '');
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Edit Student</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>students/manage_students.php">Students</a></li>
                        <li class="breadcrumb-item active">Edit</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
<style>
/* Compact image preview frame */
.img-frame{width:96px;height:96px;border:1px solid #ddd;border-radius:.25rem;background:#f8f9fa;display:flex;align-items:center;justify-content:center;overflow:hidden}
.img-frame img{width:100%;height:100%;object-fit:cover}
</style>
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11">
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
            <div class="d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Edit Student</h4>
                <a href="manage_students.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to List
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
                        <h5 class="text-primary mb-3"><i class="bi bi-person-vcard"></i> Personal Information</h5>
                    </div>

                    <!-- Student Name -->
                    <div class="col-md-6">
                        <label class="form-label">Student Name <span class="text-danger">*</span></label>
                        <input type="text" name="student_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['student_name']) ?>" required>
                        <div class="invalid-feedback">Please enter student name</div>
                    </div>

                    <!-- Father's Name -->
                    <div class="col-md-6">
                        <label class="form-label">Father's Name <span class="text-danger">*</span></label>
                        <input type="text" name="father_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['father_name']) ?>" required>
                        <div class="invalid-feedback">Please enter father's name</div>
                    </div>

                    <!-- Mother's Name -->
                    <div class="col-md-6">
                        <label class="form-label">Mother's Name <span class="text-danger">*</span></label>
                        <input type="text" name="mother_name" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['mother_name']) ?>" required>
                        <div class="invalid-feedback">Please enter mother's name</div>
                    </div>

                    <!-- Date of Birth (optional) -->
                    <div class="col-md-6">
                        <label class="form-label">Date of Birth</label>
                        <input type="text" name="date_of_birth" class="form-control form-control-lg date-input" 
                               placeholder="dd/mm/yyyy" value="<?= htmlspecialchars($dob_display) ?>">
                        <div class="form-text">(Optional)</div>
                    </div>

                    <!-- Photo -->
                    <div class="col-md-4">
                        <label class="form-label">Photo</label>
                        <div class="d-flex align-items-center">
                            <div class="mr-3">
                                <div id="imagePreview" class="img-frame">
                                    <?php if (!empty($student['photo'])): ?>
                                        <img id="previewImgTag" src="../uploads/students/<?= htmlspecialchars($student['photo']) ?>" alt="Current Photo">
                                    <?php else: ?>
                                        <div id="previewImgTag" class="text-muted">
                                            <i class="bi bi-person-bounding-box" style="font-size: 2rem;"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <input type="file" name="student_photo" id="student_photo" class="form-control form-control-lg mb-1" accept="image/*" onchange="previewImage(this)">
                                <input type="hidden" name="existing_photo" value="<?= isset($student['photo']) ? htmlspecialchars($student['photo']) : '' ?>">
                                <small class="text-muted">JPG/PNG • Max 2MB</small>
                            </div>
                        </div>
                    </div>

                    <!-- Religion -->
                    <div class="col-md-4">
                        <label class="form-label">Religion</label>
                        <select name="religion" class="form-control form-control-lg">
                            <option value="">Select</option>
                            <option value="Islam" <?= (isset($student['religion']) && $student['religion'] == 'Islam') ? 'selected' : '' ?>>Islam</option>
                            <option value="Hinduism" <?= (isset($student['religion']) && $student['religion'] == 'Hinduism') ? 'selected' : '' ?>>Hinduism</option>
                            <option value="Christianity" <?= (isset($student['religion']) && $student['religion'] == 'Christianity') ? 'selected' : '' ?>>Christianity</option>
                            <option value="Buddhism" <?= (isset($student['religion']) && $student['religion'] == 'Buddhism') ? 'selected' : '' ?>>Buddhism</option>
                        </select>
                        <div class="invalid-feedback">Please select religion</div>
                    </div>

                    <!-- Blood Group -->
                    <div class="col-md-4">
                        <label class="form-label">Blood Group</label>
                        <select name="blood_group" class="form-control form-control-lg">
                            <option value="">Select</option>
                            <option value="A+" <?= (isset($student['blood_group']) && $student['blood_group'] == 'A+') ? 'selected' : '' ?>>A+</option>
                            <option value="A-" <?= (isset($student['blood_group']) && $student['blood_group'] == 'A-') ? 'selected' : '' ?>>A-</option>
                            <option value="B+" <?= (isset($student['blood_group']) && $student['blood_group'] == 'B+') ? 'selected' : '' ?>>B+</option>
                            <option value="B-" <?= (isset($student['blood_group']) && $student['blood_group'] == 'B-') ? 'selected' : '' ?>>B-</option>
                            <option value="O+" <?= (isset($student['blood_group']) && $student['blood_group'] == 'O+') ? 'selected' : '' ?>>O+</option>
                            <option value="O-" <?= (isset($student['blood_group']) && $student['blood_group'] == 'O-') ? 'selected' : '' ?>>O-</option>
                            <option value="AB+" <?= (isset($student['blood_group']) && $student['blood_group'] == 'AB+') ? 'selected' : '' ?>>AB+</option>
                            <option value="AB-" <?= (isset($student['blood_group']) && $student['blood_group'] == 'AB-') ? 'selected' : '' ?>>AB-</option>
                        </select>
                    </div>

                    <!-- Contact Information Section -->
                    <div class="col-md-12 mt-4">
                        <h5 class="text-primary mb-3"><i class="bi bi-telephone"></i> Contact Information</h5>
                    </div>

                    <!-- Mobile Number -->
                    <div class="col-md-6">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" name="mobile_no" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['mobile_no']) ?>">
                    </div>

                    <!-- Address -->
                    <div class="col-md-6">
                        <label class="form-label">Village</label>
                        <input type="text" name="village" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['village']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Post Office</label>
                        <input type="text" name="post_office" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['post_office']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Upazila</label>
                        <input type="text" name="upazilla" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['upazilla']) ?>">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">District</label>
                        <input type="text" name="district" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['district']) ?>">
                    </div>

                    <!-- Academic Information Section -->
                    <div class="col-md-12 mt-4">
                        <h5 class="text-primary mb-3"><i class="bi bi-book"></i> Academic Information</h5>
                    </div>

                    <!-- Class -->
                    <div class="col-md-4">
                        <label class="form-label">Class <span class="text-danger">*</span></label>
                        <select name="class_id" id="class_id" class="form-control form-control-lg" required>
                            <option value="">Select</option>
                            <?php while ($class = $classes->fetch_assoc()): ?>
                                <option value="<?= $class['id'] ?>" 
                                    <?= $student['class_id'] == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                        <div class="invalid-feedback">Please select class</div>
                    </div>

                    <!-- Section -->
                    <div class="col-md-4">
                        <label class="form-label">Section <span class="text-danger">*</span></label>
                        <select name="section_id" id="section_id" class="form-control form-control-lg" required>
                            <option value="">Select Section</option>
                            <?php foreach ($sections as $section): ?>
                                <option value="<?= $section['id'] ?>" 
                                    <?= $student['section_id'] == $section['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($section['section_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback">Please select section</div>
                    </div>

                    <!-- Group -->
                    <div class="col-md-4">
                        <label class="form-label">Group</label>
                        <?php
                            $groupVal = isset($student['student_group']) ? trim((string)$student['student_group']) : '';
                            // Normalize legacy values to 'None' for display selection
                            if ($groupVal === 'none' || $groupVal === '0') { $groupVal = 'None'; }
                        ?>
                        <select name="student_group" class="form-control form-control-lg">
                            <option value="">Select</option>
                            <option value="None" <?= $groupVal === 'None' ? 'selected' : '' ?>>None</option>
                            <option value="Science" <?= $groupVal === 'Science' ? 'selected' : '' ?>>Science</option>
                            <option value="Humanities" <?= $groupVal === 'Humanities' ? 'selected' : '' ?>>Humanities</option>
                            <option value="Business Studies" <?= $groupVal === 'Business Studies' ? 'selected' : '' ?>>Business Studies</option>
                        </select>
                    </div>

                    <!-- Roll No & Year -->
                    <div class="col-md-4">
                        <label class="form-label">Roll No <span class="text-danger">*</span></label>
                        <input type="text" name="roll_no" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['roll_no']) ?>" required>
                        <div class="invalid-feedback">Please enter roll no</div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Year <span class="text-danger">*</span></label>
                        <input type="text" name="year" class="form-control form-control-lg" 
                               value="<?= htmlspecialchars($student['year']) ?>" required>
                        <div class="invalid-feedback">Please enter year</div>
                    </div>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button type="reset" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-arrow-counterclockwise"></i> Reset
                    </button>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-circle"></i> Update
                    </button>
                </div>
            </form>
        </div>
    </div>
                </div> <!-- /.col -->
        </div> <!-- /.row -->
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

    // Image preview function
    function previewImage(input) {
        const preview = document.getElementById('imagePreview');
        const file = input.files[0];
        if (file) {
            // Enforce 2MB max client-side
            var maxBytes = 2 * 1024 * 1024; // 2MB
            if (file.size > maxBytes) {
                alert('Image is too large. Maximum allowed size is 2MB.');
                input.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function(e) {
                // Remove previous image or icon
                let imgTag = document.getElementById('previewImgTag');
                if (imgTag) imgTag.remove();
                // Add new image
                const img = document.createElement('img');
                img.id = 'previewImgTag';
                img.src = e.target.result;
                img.alt = 'Preview';
                img.style.maxWidth = '100%';
                img.style.maxHeight = '100%';
                img.style.objectFit = 'cover';
                preview.innerHTML = '';
                preview.appendChild(img);
            }
            reader.readAsDataURL(file);
        }
    }

    // Form validation
    (function () {
        'use strict'
        var forms = document.querySelectorAll('.needs-validation')
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