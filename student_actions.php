<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: dashboard.php");
    exit();
}

include "config/db.php";

// Function to sanitize input
function test_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

// Generate student_id
function generate_student_id($conn, $class_id) {
    // Year: last two digit of current year
    $year = date('y');

    // Map class id to class code (যেমন: Six=06, Seven=07, etc)
    $class_codes = [
        1 => '06', // Six
        2 => '07', // Seven
        3 => '08', // Eight
        4 => '09', // Nine
        5 => '10', // Ten
    ];

    $class_code = $class_codes[$class_id] ?? '00';

    // Find max serial for this class in current year
    $prefix = $year . $class_code; // e.g. 2506
    $sql = "SELECT student_id FROM students WHERE student_id LIKE '$prefix%' ORDER BY student_id DESC LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $last_id = $row['student_id'];
        $last_serial = (int)substr($last_id, -3);
        $new_serial = $last_serial + 1;
    } else {
        $new_serial = 1;
    }

    $serial_str = str_pad($new_serial, 3, '0', STR_PAD_LEFT);
    return $prefix . $serial_str;  // e.g. 2506001
}

// Fetch classes
$class_result = $conn->query("SELECT * FROM classes ORDER BY id ASC");

// Fetch students with join for class and section names
$students_sql = "SELECT s.*, c.class_name, sec.section_name FROM students s 
    LEFT JOIN classes c ON s.class = c.id 
    LEFT JOIN sections sec ON s.section = sec.id
    ORDER BY s.student_id DESC";
$students = $conn->query($students_sql);

// Handle POST requests for add/edit/change status/delete

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'add') {
            // Add new student
            $class_id = (int)$_POST['class'];
            $student_id = generate_student_id($conn, $class_id);

            // Collect other data safely
            $roll_no = test_input($_POST['roll_no']);
            $student_name = test_input($_POST['student_name']);
            $father_name = test_input($_POST['father_name']);
            $mother_name = test_input($_POST['mother_name']);
            $gender = test_input($_POST['gender']);
            $mobile_no = test_input($_POST['mobile_no']);
            $religion = test_input($_POST['religion']);
            $nationality = test_input($_POST['nationality']);
            $dob = test_input($_POST['dob']);
            $blood_group = test_input($_POST['blood_group']);
            $village = test_input($_POST['village']);
            $post_office = test_input($_POST['post_office']);
            $upazilla = test_input($_POST['upazilla']);
            $district = test_input($_POST['district']);
            $status = test_input($_POST['status']);
            $section = (int)$_POST['section'];
            $group = test_input($_POST['group']);
            $year = test_input($_POST['year']);

            $stmt = $conn->prepare("INSERT INTO students (student_id, roll_no, student_name, father_name, mother_name, gender, mobile_no, religion, nationality, dob, blood_group, village, post_office, upazilla, district, status, class, section, `group`, year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssssssssssiiss", $student_id, $roll_no, $student_name, $father_name, $mother_name, $gender, $mobile_no, $religion, $nationality, $dob, $blood_group, $village, $post_office, $upazilla, $district, $status, $class_id, $section, $group, $year);
            $stmt->execute();
            header("Location: manage_students.php");
            exit();
        } elseif ($action === 'edit') {
            // Edit existing student
            $student_id = test_input($_POST['student_id']);
            $roll_no = test_input($_POST['roll_no']);
            $student_name = test_input($_POST['student_name']);
            $father_name = test_input($_POST['father_name']);
            $mother_name = test_input($_POST['mother_name']);
            $gender = test_input($_POST['gender']);
            $mobile_no = test_input($_POST['mobile_no']);
            $religion = test_input($_POST['religion']);
            $nationality = test_input($_POST['nationality']);
            $dob = test_input($_POST['dob']);
            $blood_group = test_input($_POST['blood_group']);
            $village = test_input($_POST['village']);
            $post_office = test_input($_POST['post_office']);
            $upazilla = test_input($_POST['upazilla']);
            $district = test_input($_POST['district']);
            $status = test_input($_POST['status']);
            $class_id = (int)$_POST['class'];
            $section = (int)$_POST['section'];
            $group = test_input($_POST['group']);
            $year = test_input($_POST['year']);

            $stmt = $conn->prepare("UPDATE students SET roll_no=?, student_name=?, father_name=?, mother_name=?, gender=?, mobile_no=?, religion=?, nationality=?, dob=?, blood_group=?, village=?, post_office=?, upazilla=?, district=?, status=?, class=?, section=?, `group`=?, year=? WHERE student_id=?");
            $stmt->bind_param("sssssssssssssssssiiss", $roll_no, $student_name, $father_name, $mother_name, $gender, $mobile_no, $religion, $nationality, $dob, $blood_group, $village, $post_office, $upazilla, $district, $status, $class_id, $section, $group, $year, $student_id);
            $stmt->execute();
            header("Location: manage_students.php");
            exit();
        } elseif ($action === 'change_status') {
            // Change status
            $student_id = test_input($_POST['student_id']);
            $new_status = test_input($_POST['new_status']);
            $stmt = $conn->prepare("UPDATE students SET status=? WHERE student_id=?");
            $stmt->bind_param("ss", $new_status, $student_id);
            $stmt->execute();
            header("Location: manage_students.php");
            exit();
        } elseif ($action === 'delete') {
            // Delete student
            $student_id = test_input($_POST['student_id']);
            $stmt = $conn->prepare("DELETE FROM students WHERE student_id=?");
            $stmt->bind_param("s", $student_id);
            $stmt->execute();
            header("Location: manage_students.php");
            exit();
        }
    }
}

?>

<?php include "auth/heade.php"; ?>
<?php include "sidebar.php"; ?>

<div class="container mt-4">
    <h3>ছাত্র/ছাত্রী পরিচালনা (Manage Students)</h3>
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addStudentModal">নতুন ছাত্র/ছাত্রী যুক্ত করুন</button>

    <table class="table table-bordered table-striped">
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Roll No</th>
                <th>Student Name</th>
                <th>Father Name</th>
                <th>Mother Name</th>
                <th>Gender</th>
                <th>Mobile No</th>
                <th>Religion</th>
                <th>Nationality</th>
                <th>DOB</th>
                <th>Blood Group</th>
                <th>Village</th>
                <th>Post Office</th>
                <th>Upazilla</th>
                <th>District</th>
                <th>Status</th>
                <th>Class</th>
                <th>Section</th>
                <th>Group</th>
                <th>Year</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($students && $students->num_rows > 0) : ?>
                <?php while ($row = $students->fetch_assoc()) : ?>
                    <tr>
                        <td><?= htmlspecialchars($row['student_id']) ?></td>
                        <td><?= htmlspecialchars($row['roll_no']) ?></td>
                        <td><?= htmlspecialchars($row['student_name']) ?></td>
                        <td><?= htmlspecialchars($row['father_name']) ?></td>
                        <td><?= htmlspecialchars($row['mother_name']) ?></td>
                        <td><?= htmlspecialchars($row['gender']) ?></td>
                        <td><?= htmlspecialchars($row['mobile_no']) ?></td>
                        <td><?= htmlspecialchars($row['religion']) ?></td>
                        <td><?= htmlspecialchars($row['nationality']) ?></td>
                        <td><?= htmlspecialchars($row['dob']) ?></td>
                        <td><?= htmlspecialchars($row['blood_group']) ?></td>
                        <td><?= htmlspecialchars($row['village']) ?></td>
                        <td><?= htmlspecialchars($row['post_office']) ?></td>
                        <td><?= htmlspecialchars($row['upazilla']) ?></td>
                        <td><?= htmlspecialchars($row['district']) ?></td>
                        <td><?= htmlspecialchars($row['status']) ?></td>
                        <td><?= htmlspecialchars($row['class_name']) ?></td>
                        <td><?= htmlspecialchars($row['section_name']) ?></td>
                        <td><?= htmlspecialchars($row['group']) ?></td>
                        <td><?= htmlspecialchars($row['year']) ?></td>
                        <td>
                            <button 
                                class="btn btn-sm btn-info editBtn" 
                                data-bs-toggle="modal" 
                                data-bs-target="#editStudentModal"
                                data-student_id="<?= htmlspecialchars($row['student_id']) ?>"
                                data-roll_no="<?= htmlspecialchars($row['roll_no']) ?>"
                                data-student_name="<?= htmlspecialchars($row['student_name']) ?>"
                                data-father_name="<?= htmlspecialchars($row['father_name']) ?>"
                                data-mother_name="<?= htmlspecialchars($row['mother_name']) ?>"
                                data-gender="<?= htmlspecialchars($row['gender']) ?>"
                                data-mobile_no="<?= htmlspecialchars($row['mobile_no']) ?>"
                                data-religion="<?= htmlspecialchars($row['religion']) ?>"
                                data-nationality="<?= htmlspecialchars($row['nationality']) ?>"
                                data-dob="<?= htmlspecialchars($row['dob']) ?>"
                                data-blood_group="<?= htmlspecialchars($row['blood_group']) ?>"
                                data-village="<?= htmlspecialchars($row['village']) ?>"
                                data-post_office="<?= htmlspecialchars($row['post_office']) ?>"
                                data-upazilla="<?= htmlspecialchars($row['upazilla']) ?>"
                                data-district="<?= htmlspecialchars($row['district']) ?>"
                                data-status="<?= htmlspecialchars($row['status']) ?>"
                                data-class="<?= htmlspecialchars($row['class']) ?>"
                                data-section="<?= htmlspecialchars($row['section']) ?>"
                                data-group="<?= htmlspecialchars($row['group']) ?>"
                                data-year="<?= htmlspecialchars($row['year']) ?>"
                            >Edit</button>

                            <?php if ($row['status'] === 'Active'): ?>
                                <form style="display:inline;" method="post" onsubmit="return confirm('আপনি কি স্ট্যাটাস পরিবর্তন করতে চান?');">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($row['student_id']) ?>">
                                    <input type="hidden" name="new_status" value="Inactive">
                                    <button type="submit" class="btn btn-sm btn-warning">Deactivate</button>
                                </form>
                            <?php else: ?>
                                <form style="display:inline;" method="post" onsubmit="return confirm('আপনি কি স্ট্যাটাস পরিবর্তন করতে চান?');">
                                    <input type="hidden" name="action" value="change_status">
                                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($row['student_id']) ?>">
                                    <input type="hidden" name="new_status" value="Active">
                                    <button type="submit" class="btn btn-sm btn-success">Activate</button>
                                </form>
                            <?php endif; ?>

                            <form style="display:inline;" method="post" onsubmit="return confirm('আপনি কি ডিলিট করতে চান?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?= htmlspecialchars($row['student_id']) ?>">
                                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php else: ?>
                <tr><td colspan="21" class="text-center">কোনো তথ্য পাওয়া যায়নি</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" id="addStudentForm">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title" id="addStudentModalLabel">নতুন ছাত্র/ছাত্রী যুক্ত করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-2">
              <label for="roll_no" class="form-label">Roll No</label>
              <input type="text" name="roll_no" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label for="student_name" class="form-label">Student Name</label>
              <input type="text" name="student_name" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="father_name" class="form-label">Father Name</label>
              <input type="text" name="father_name" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="mother_name" class="form-label">Mother Name</label>
              <input type="text" name="mother_name" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label for="gender" class="form-label">Gender</label>
              <select name="gender" class="form-select" required>
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label for="mobile_no" class="form-label">Mobile No</label>
              <input type="text" name="mobile_no" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="religion" class="form-label">Religion</label>
              <input type="text" name="religion" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="nationality" class="form-label">Nationality</label>
              <input type="text" name="nationality" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="dob" class="form-label">Date Of Birth</label>
              <input type="text" name="dob" class="form-control date-input" placeholder="dd/mm/yyyy" required>
            </div>
            <div class="col-md-3">
              <label for="blood_group" class="form-label">Blood Group</label>
              <input type="text" name="blood_group" class="form-control">
            </div>
            <div class="col-md-3">
              <label for="village" class="form-label">Village</label>
              <input type="text" name="village" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="post_office" class="form-label">Post Office</label>
              <input type="text" name="post_office" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="upazilla" class="form-label">Upazilla</label>
              <input type="text" name="upazilla" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="district" class="form-label">District</label>
              <input type="text" name="district" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label for="status" class="form-label">Status</label>
              <select name="status" class="form-select" required>
                <option value="Active" selected>Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label for="class" class="form-label">Class</label>
              <select name="class" id="add_class" class="form-select" required>
                <option value="">Select Class</option>
                <?php while ($c = $class_result->fetch_assoc()) : ?>
                  <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label for="section" class="form-label">Section</label>
              <select name="section" id="add_section" class="form-select" required>
                <option value="">Select Section</option>
              </select>
            </div>
            <div class="col-md-2">
              <label for="group" class="form-label">Group</label>
              <input type="text" name="group" class="form-control">
            </div>
            <div class="col-md-2">
              <label for="year" class="form-label">Year</label>
              <input type="number" name="year" class="form-control" value="<?= date('Y') ?>" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-success">সেভ করুন</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বন্ধ করুন</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="post" id="editStudentForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="student_id" id="edit_student_id">
        <div class="modal-header">
          <h5 class="modal-title" id="editStudentModalLabel">ছাত্র/ছাত্রী তথ্য সম্পাদনা করুন</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-2">
              <label for="edit_roll_no" class="form-label">Roll No</label>
              <input type="text" name="roll_no" id="edit_roll_no" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label for="edit_student_name" class="form-label">Student Name</label>
              <input type="text" name="student_name" id="edit_student_name" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_father_name" class="form-label">Father Name</label>
              <input type="text" name="father_name" id="edit_father_name" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_mother_name" class="form-label">Mother Name</label>
              <input type="text" name="mother_name" id="edit_mother_name" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label for="edit_gender" class="form-label">Gender</label>
              <select name="gender" id="edit_gender" class="form-select" required>
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="col-md-3">
              <label for="edit_mobile_no" class="form-label">Mobile No</label>
              <input type="text" name="mobile_no" id="edit_mobile_no" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_religion" class="form-label">Religion</label>
              <input type="text" name="religion" id="edit_religion" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_nationality" class="form-label">Nationality</label>
              <input type="text" name="nationality" id="edit_nationality" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_dob" class="form-label">Date Of Birth</label>
              <input type="text" name="dob" id="edit_dob" class="form-control date-input" placeholder="dd/mm/yyyy" required>
            </div>
            <div class="col-md-3">
              <label for="edit_blood_group" class="form-label">Blood Group</label>
              <input type="text" name="blood_group" id="edit_blood_group" class="form-control">
            </div>
            <div class="col-md-3">
              <label for="edit_village" class="form-label">Village</label>
              <input type="text" name="village" id="edit_village" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_post_office" class="form-label">Post Office</label>
              <input type="text" name="post_office" id="edit_post_office" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_upazilla" class="form-label">Upazilla</label>
              <input type="text" name="upazilla" id="edit_upazilla" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label for="edit_district" class="form-label">District</label>
              <input type="text" name="district" id="edit_district" class="form-control" required>
            </div>
            <div class="col-md-2">
              <label for="edit_status" class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select" required>
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label for="edit_class" class="form-label">Class</label>
              <select name="class" id="edit_class" class="form-select" required>
                <option value="">Select Class</option>
                <?php
                // Reload classes for edit modal
                $class_result->data_seek(0);
                while ($c = $class_result->fetch_assoc()) {
                  echo '<option value="' . $c['id'] . '">' . htmlspecialchars($c['class_name']) . '</option>';
                }
                ?>
              </select>
            </div>
            <div class="col-md-3">
              <label for="edit_section" class="form-label">Section</label>
              <select name="section" id="edit_section" class="form-select" required>
                <option value="">Select Section</option>
              </select>
            </div>
            <div class="col-md-2">
              <label for="edit_group" class="form-label">Group</label>
              <input type="text" name="group" id="edit_group" class="form-control">
            </div>
            <div class="col-md-2">
              <label for="edit_year" class="form-label">Year</label>
              <input type="number" name="year" id="edit_year" class="form-control" value="<?= date('Y') ?>" required>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">আপডেট করুন</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বন্ধ করুন</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  // AJAX to load sections based on selected class in Add modal
  document.getElementById('add_class').addEventListener('change', function () {
    var classId = this.value;
    fetch('get_sections.php?class_id=' + classId)
      .then(response => response.json())
      .then(data => {
        var sectionSelect = document.getElementById('add_section');
        sectionSelect.innerHTML = '<option value="">Select Section</option>';
        data.forEach(section => {
          var option = document.createElement('option');
          option.value = section.id;
          option.textContent = section.section_name;
          sectionSelect.appendChild(option);
        });
      });
  });

  // AJAX to load sections based on selected class in Edit modal
  document.getElementById('edit_class').addEventListener('change', function () {
    var classId = this.value;
    fetch('get_sections.php?class_id=' + classId)
      .then(response => response.json())
      .then(data => {
        var sectionSelect = document.getElementById('edit_section');
        sectionSelect.innerHTML = '<option value="">Select Section</option>';
        data.forEach(section => {
          var option = document.createElement('option');
          option.value = section.id;
          option.textContent = section.section_name;
          sectionSelect.appendChild(option);
        });
      });
  });

  // Load student data into edit modal
  function openEditModal(student) {
    document.getElementById('edit_student_id').value = student.student_id;
    document.getElementById('edit_roll_no').value = student.roll_no;
    document.getElementById('edit_student_name').value = student.student_name;
    document.getElementById('edit_father_name').value = student.father_name;
    document.getElementById('edit_mother_name').value = student.mother_name;
    document.getElementById('edit_gender').value = student.gender;
    document.getElementById('edit_mobile_no').value = student.mobile_no;
    document.getElementById('edit_religion').value = student.religion;
    document.getElementById('edit_nationality').value = student.nationality;
    document.getElementById('edit_dob').value = student.dob;
    document.getElementById('edit_blood_group').value = student.blood_group;
    document.getElementById('edit_village').value = student.village;
    document.getElementById('edit_post_office').value = student.post_office;
    document.getElementById('edit_upazilla').value = student.upazilla;
    document.getElementById('edit_district').value = student.district;
    document.getElementById('edit_status').value = student.status;
    document.getElementById('edit_class').value = student.class_id;

    // Trigger change event to load sections
    var event = new Event('change');
    document.getElementById('edit_class').dispatchEvent(event);

    setTimeout(() => {
      document.getElementById('edit_section').value = student.section_id;
    }, 200);

    document.getElementById('edit_group').value = student.group;
    document.getElementById('edit_year').value = student.year;

    var editModal = new bootstrap.Modal(document.getElementById('editStudentModal'));
    editModal.show();
  }
</script>

</body>
</html>
