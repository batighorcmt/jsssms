<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>

<div class="d-flex flex-column flex-shrink-0 p-3 bg-light" style="width: 250px; min-height: 100vh;">
  <a href="dashboard.php" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-dark text-decoration-none">
    <span class="fs-4">📚 JSSSMS</span>
  </a>
  <hr>
  <ul class="nav nav-pills flex-column mb-auto">
    <li class="nav-item">
      <a href="dashboard.php" class="nav-link active">
        🏠 Dashboard
      </a>
    </li>
    
    <?php if ($_SESSION['role'] === 'super_admin'): ?>
    <li>
      <a href="manage_teachers.php" class="nav-link text-dark">
        👩‍🏫 Manage Teachers
      </a>
    </li>
    <li>
      <a href="manage_students.php" class="nav-link text-dark">
        🎓 Manage Students
      </a>
    </li>
    <li>
      <a href="manage_classes.php" class="nav-link text-dark">
        🏫 Class Management
      </a>
    </li>
    <li>
      <a href="manage_fees.php" class="nav-link text-dark">
        💳 Fees & Payments
      </a>
    </li>
    <li>
      <a href="exam_results.php" class="nav-link text-dark">
        📄 Exam Results
      </a>
    </li>
    <?php endif; ?>

    <?php if ($_SESSION['role'] === 'teacher'): ?>
    <li>
      <a href="my_classes.php" class="nav-link text-dark">
        🗂️ My Classes
      </a>
    </li>
    <li>
      <a href="mark_entry.php" class="nav-link text-dark">
        ✏️ Marks Entry
      </a>
    </li>
    <li>
      <a href="student_list.php" class="nav-link text-dark">
        📋 Student List
      </a>
    </li>
    <?php endif; ?>
    
    <li>
      <a href="profile.php" class="nav-link text-dark">
        👤 My Profile
      </a>
    </li>
    <li>
      <a href="auth/logout.php" class="nav-link text-danger">
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
