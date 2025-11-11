<?php
// includes/header.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Jorepukuria School Management</title>
  <!-- Fonts: Open Sans (English), Kalpurush (Bangla) -->
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.maateen.me" crossorigin>
  <style>
    @font-face{font-family:'Kalpurush';src:url('https://fonts.maateen.me/kalpurush/font.ttf') format('truetype');font-display:swap}
    :root{ --brand:#0b3a67; }
    html,body{ font-family:'Open Sans','Kalpurush',system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif }
    .bn{ font-family:'Kalpurush','Open Sans',sans-serif }
  </style>
  <!-- AdminLTE 3 via CDN -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <!-- Bootstrap Datepicker CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-datepicker@1.9.0/dist/css/bootstrap-datepicker3.min.css">
</head>
<body class="hold-transition sidebar-mini">
<div class="wrapper">
  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="/jsssms/dashboard.php" class="nav-link">ড্যাশবোর্ড</a>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <span class="nav-link">Logged in as: <strong><?php echo htmlspecialchars($_SESSION['name'] ?? 'Unknown'); ?></strong></span>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->
