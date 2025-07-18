<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
$BASE_URL = '/jss/'; // যেমন: /jsssms/

$current = basename($_SERVER['PHP_SELF']);
?>

<div class="d-flex flex-column flex-shrink-0 p-3 bg-light" style="width: 250px; min-height: 100vh;">
  <a href="<?= $BASE_URL ?>dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-dark text-decoration-none">
    <span class="fs-4">📚 JSSSMS</span>
  </a>
  <hr>
  <ul class="nav nav-pills flex-column mb-auto">
    <li class="nav-item">
      <a href="<?= $BASE_URL ?>dashboard.php" class="nav-link <?= ($current == 'dashboard.php') ? 'active' : 'text-dark' ?>">
        🏠 Dashboard
      </a>
    </li>

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
    <li>
      <a href="<?= $BASE_URL ?>manage_teachers.php" class="nav-link <?= ($current == 'manage_teachers.php') ? 'active' : 'text-dark' ?>">
        👩‍🏫 Manage Teachers
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>students/manage_students.php" class="nav-link <?= ($current == 'manage_students.php') ? 'active' : 'text-dark' ?>">
        🎓 Manage Students
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>manage_classes.php" class="nav-link <?= ($current == 'manage_classes.php') ? 'active' : 'text-dark' ?>">
        🏫 Class Management
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>settings/manage_subjects.php" class="nav-link <?= ($current == 'manage_subjects.php') ? 'active' : 'text-dark' ?>">
        📚 Manage Subjects
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>manage_fees.php" class="nav-link <?= ($current == 'manage_fees.php') ? 'active' : 'text-dark' ?>">
        💳 Fees & Payments
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>settings/manage_exams.php" class="nav-link <?= ($current == 'manage_exams.php') ? 'active' : 'text-dark' ?>">
        📝 Manage Exams
      </a>
    <li>
          <li>
      <a href="<?= $BASE_URL ?>exam/mark_entry.php" class="nav-link <?= ($current == 'mark_entry.php') ? 'active' : 'text-dark' ?>">
        📝 Mark Entry
      </a>
    <li>
      <a href="<?= $BASE_URL ?>exam_results.php" class="nav-link <?= ($current == 'exam_results.php') ? 'active' : 'text-dark' ?>">
        📄 Exam Results
      </a>
    </li>
    <?php endif; ?>

    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
    <li>
      <a href="<?= $BASE_URL ?>my_classes.php" class="nav-link <?= ($current == 'my_classes.php') ? 'active' : 'text-dark' ?>">
        🗂️ My Classes
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>exam/mark_entry.php" class="nav-link <?= ($current == 'mark_entry.php') ? 'active' : 'text-dark' ?>">
        📝 Mark Entry
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>student_list.php" class="nav-link <?= ($current == 'student_list.php') ? 'active' : 'text-dark' ?>">
        📋 Student List
      </a>
    </li>
    <?php endif; ?>

    <li>
      <a href="<?= $BASE_URL ?>profile.php" class="nav-link <?= ($current == 'profile.php') ? 'active' : 'text-dark' ?>">
        👤 My Profile
      </a>
    </li>
    <li>
      <a href="<?= $BASE_URL ?>auth/logout.php" class="nav-link text-danger">
        🔓 Logout
      </a>
    </li>
  </ul>
  <hr>
  <div class="text-muted small">
    Logged in as: <strong><?= $_SESSION['name'] ?? 'Unknown' ?></strong><br>
    Role: <?= $_SESSION['role'] ?? 'User' ?>
  </div>
</div>
