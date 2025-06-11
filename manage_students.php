<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: auth/login.php");
    exit();
}
include 'config/db.php';
include 'includes/header.php'; ?>
<div class="d-flex">
<?php include 'includes/sidebar.php';

// শিক্ষার্থীদের তথ্য আনো
$query = "SELECT s.*, c.class_name, sec.section_name 
          FROM students s
          JOIN classes c ON s.class_id = c.id
          JOIN sections sec ON s.section_id = sec.id
          ORDER BY s.id DESC";
$result = $conn->query($query);
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>ছাত্র/ছাত্রী তালিকা</h4>
        <a href="students/add_student.php" class="btn btn-success">+ Add Student</a>
    </div>

    <table class="table table-bordered table-hover">
        <thead class="table-dark">
            <tr>
                <th>ক্রমিক</th>
                <th>আইডি</th>
                <th>নাম</th>
                <th>পিতার নাম</th>
                <th>মাতার নাম</th>
                <th>শ্রেণি</th>
                <th>শাখা</th>
                <th>রোল</th>
                <th>বিষয় কোড</th>
                <th>ছবি</th>
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sl = 1;
            while ($row = $result->fetch_assoc()):
                // শিক্ষার্থীর বিষয়ের কোড সংগ্রহ
$subject_codes = '';
$student_id = $row['student_id'];
$sub_query = "SELECT sub.subject_code 
              FROM student_subjects ss 
              JOIN subjects sub ON ss.subject_id = sub.id 
              WHERE ss.student_id = '$student_id'";
$sub_result = $conn->query($sub_query);
while ($sub_row = $sub_result->fetch_assoc()) {
    $subject_codes .= $sub_row['subject_code'] . ', ';
}
$subject_codes = rtrim($subject_codes, ', ');
            ?>
            <tr>
                <td><?= $sl++; ?></td>
                <td><?= htmlspecialchars($row['student_id']); ?></td>
                <td><?= htmlspecialchars($row['student_name']); ?></td>
                <td><?= htmlspecialchars($row['father_name']); ?></td>
                <td><?= htmlspecialchars($row['mother_name']); ?></td>
                <td><?= htmlspecialchars($row['class_name']); ?></td>
                <td><?= htmlspecialchars($row['section_name']); ?></td>
                <td><?= htmlspecialchars($row['roll_no']); ?></td>
                <td>
                  <?php
    if ($subject_codes) {
        $codes = explode(',', $subject_codes);
        foreach ($codes as $code) {
            echo '<span class="badge bg-secondary me-1">' . trim($code) . '</span>';
        }
    } else {
        echo 'N/A';
    }
    ?>
              
              </td>
                <td>
                    <?php if ($row['photo']): ?>
                        <img src="uploads/students/<?= htmlspecialchars($row['photo']); ?>" width="50" height="50">
                    <?php else: ?>
                        <span>No Photo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="students/edit_student.php?id=<?= $row['id']; ?>">Edit</a></li>
                            <li><a class="dropdown-item" href="students/add_subjects.php?student_id=<?= $row['student_id']; ?>">Select Subject</a></li>
                            <li><a class="dropdown-item text-danger" href="students/delete_student.php?id=<?= $row['id']; ?>" onclick="return confirm('Are you sure?');">Delete</a></li>
                        </ul>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>


