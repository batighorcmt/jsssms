<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
@include_once __DIR__ . '/../config/config.php';
$BASE_URL = BASE_URL; // keep variable for existing template echos
$current = basename($_SERVER['PHP_SELF']);
?>

<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <!-- Brand Logo -->
  <?php $dashHref = (isset($_SESSION['role']) && $_SESSION['role']==='teacher') ? ($BASE_URL . 'dashboard_teacher.php') : ($BASE_URL . 'dashboard.php'); ?>
  <a href="<?= $dashHref ?>" class="brand-link">
    <i class="fas fa-school brand-image img-circle elevation-3" style="opacity:.8"></i>
    <span class="brand-text font-weight-light">JSSSMS</span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Sidebar user panel (optional) -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex">
      <div class="image">
        <i class="fas fa-user-circle fa-2x text-white-50"></i>
      </div>
      <div class="info">
        <a href="#" class="d-block"><?php echo htmlspecialchars($_SESSION['name'] ?? 'Unknown'); ?></a>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <?php $isTeacher = (isset($_SESSION['role']) && $_SESSION['role']==='teacher'); ?>
          <a href="<?= $isTeacher ? ($BASE_URL.'dashboard_teacher.php') : ($BASE_URL.'dashboard.php') ?>" class="nav-link <?= ($current==($isTeacher?'dashboard_teacher.php':'dashboard.php'))?'active':'' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p><?= $isTeacher ? 'Teacher Dashboard' : 'Dashboard' ?></p>
          </a>
        </li>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>/teachers/manage_teachers.php" class="nav-link <?= ($current=='manage_teachers.php')?'active':'' ?>">
            <i class="nav-icon fas fa-chalkboard-teacher"></i>
            <p>Manage Teachers</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>students/manage_students.php" class="nav-link <?= ($current=='manage_students.php')?'active':'' ?>">
            <i class="nav-icon fas fa-user-graduate"></i>
            <p>Manage Students</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>students/student_list_print.php" class="nav-link <?= ($current=='student_list_print.php')?'active':'' ?>">
            <i class="nav-icon fas fa-user-graduate"></i>
            <p>Students Report</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>settings/manage_subjects.php" class="nav-link <?= ($current=='manage_subjects.php')?'active':'' ?>">
            <i class="nav-icon fas fa-book"></i>
            <p>Manage Subjects</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>settings/manage_exams.php" class="nav-link <?= ($current=='manage_exams.php')?'active':'' ?>">
            <i class="nav-icon fas fa-file-alt"></i>
            <p>Manage Exams</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>exam/mark_entry.php" class="nav-link <?= ($current=='mark_entry.php')?'active':'' ?>">
            <i class="nav-icon fas fa-pen"></i>
            <p>Mark Entry</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>results/exam_results.php" class="nav-link <?= ($current=='exam_results.php')?'active':'' ?>">
            <i class="nav-icon fas fa-file"></i>
            <p>Exam Results</p>
          </a>
        </li>
        <?php endif; ?>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher'): ?>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>exam/mark_entry.php" class="nav-link <?= ($current=='mark_entry.php')?'active':'' ?>">
            <i class="nav-icon fas fa-pen"></i>
            <p>Mark Entry</p>
          </a>
        </li>
        <?php endif; ?>

        <li class="nav-item">
          <a href="<?= $BASE_URL ?>auth/profile.php" class="nav-link <?= ($current=='profile.php' || $current=='auth.php' || $current=='auth/profile.php')?'active':'' ?>">
            <i class="nav-icon fas fa-user"></i>
            <p>My Profile</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="<?= $BASE_URL ?>auth/logout.php" class="nav-link text-danger">
            <i class="nav-icon fas fa-sign-out-alt text-danger"></i>
            <p class="text-danger">Logout</p>
          </a>
        </li>
      </ul>
    </nav>
    <!-- /.sidebar-menu -->
  </div>
  <!-- /.sidebar -->
</aside>
