<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
@include_once __DIR__ . '/../config/config.php';
// Optional: show who tried to access
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="text-danger">Access Denied</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Forbidden</li>
          </ol>
        </div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <div class="alert alert-danger">
        আপনার এই পৃষ্ঠায় প্রবেশ করার অনুমতি নেই।
      </div>
  <a href="<?= BASE_URL ?>dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> ড্যাশবোর্ডে ফিরে যান</a>
    </div>
  </section>
</div>
<?php include '../includes/footer.php'; ?>
