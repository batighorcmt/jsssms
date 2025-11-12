<?php
@include_once __DIR__ . '/config/config.php';
// Allow both super_admin and teacher to pass the session gate, we'll redirect teachers below
$ALLOWED_ROLES = ['super_admin','teacher'];
include "auth/session.php";
// If a teacher lands here, redirect them to the teacher dashboard using BASE_URL BEFORE any output
if (isset($_SESSION['role']) && $_SESSION['role'] === 'teacher') {
  header('Location: ' . BASE_URL . 'dashboard_teacher.php');
  exit();
}
include "includes/header.php";
?>

<?php include "includes/sidebar.php"; ?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="bn">অফিস ড্যাশবোর্ড</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Dashboard</li>
          </ol>
        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <div class="row">
        <div class="col-12">
          <div class="card">
            <div class="card-body">
              <p class="bn mb-0">আপনার তথ্য এখানে দেখতে ও পরিচালনা করতে পারেন।</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php include "includes/footer.php"; ?>
