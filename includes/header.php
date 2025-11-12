<?php
// includes/header.php
// Bootstrap error logging early for all pages that include header
@include_once __DIR__ . '/bootstrap.php';
if (session_status() == PHP_SESSION_NONE) {
  session_start();
}
// Global app config (BASE_URL)
@include_once __DIR__ . '/../config/config.php';
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
  /* Avatar dropdown trigger tweaks */
  .nav-item.dropdown > a.user-avatar-trigger {padding:0 .35rem; display:flex; align-items:center; position:relative;}
  /* Create a hit area that extends 5px around the circular avatar without shifting layout */
  .nav-item.dropdown > a.user-avatar-trigger .avatar-hit-area{display:inline-block; padding:5px; margin:-5px; border-radius:50%;}
  .nav-item.dropdown > a.user-avatar-trigger .avatar-hit-area img{width:36px;height:36px;border-radius:50%;object-fit:cover;display:block;}
  /* Focus/Hover outline on the hit area */
  .nav-item.dropdown > a.user-avatar-trigger:focus .avatar-hit-area img,
  .nav-item.dropdown > a.user-avatar-trigger:hover .avatar-hit-area img{outline:2px solid rgba(0,0,0,.15);}  
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
        <?php $dash = (isset($_SESSION['role']) && $_SESSION['role']==='teacher') ? (BASE_URL.'dashboard_teacher.php') : (BASE_URL.'dashboard.php'); ?>
        <a href="<?php echo htmlspecialchars($dash); ?>" class="nav-link">Dashboard</a>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <?php
        // Resolve current user display info
        $rawName = $_SESSION['name'] ?? '';
        // Fallback to fetch name from DB if missing in session (teacher)
        $username = $_SESSION['username'] ?? null;
        $role = $_SESSION['role'] ?? null;
        $avatar = $_SESSION['photo'] ?? '';
        if ($rawName === '' && !empty($role) && $role === 'teacher' && !empty($username)) {
          if (!isset($conn)) { @include_once __DIR__ . '/../config/db.php'; }
          if (isset($conn)) {
            if ($sn = $conn->prepare("SELECT name FROM teachers WHERE contact = ? LIMIT 1")) {
              $sn->bind_param('s', $username);
              if ($sn->execute()) { $rs = $sn->get_result(); if ($rs && ($r=$rs->fetch_assoc())) $rawName = $r['name'] ?? $rawName; }
            }
          }
        }
        $displayName = htmlspecialchars($rawName !== '' ? $rawName : 'User');

        // Helper to normalize avatar path
        $defaultAvatar = BASE_URL . 'assets/img/default-avatar.svg';
        $webAvatar = $defaultAvatar;
        if ($avatar) {
          if (preg_match('~^https?://~i', $avatar)) {
            $webAvatar = $avatar;
          } elseif (defined('BASE_URL') && strpos($avatar, BASE_URL) === 0) {
            $webAvatar = $avatar; // already absolute to our base
          } else {
            $candidate = BASE_URL . ltrim($avatar, '/');
            $webAvatar = $candidate;
          }
        } else {
          // Try to fetch from DB for teachers if possible
          if (!empty($role) && $role === 'teacher' && !empty($username)) {
            if (!isset($conn)) { @include_once __DIR__ . '/../config/db.php'; }
            if (isset($conn)) {
              if ($stmt = $conn->prepare("SELECT photo FROM teachers WHERE contact = ? LIMIT 1")) {
                $stmt->bind_param('s', $username);
                if ($stmt->execute()) {
                  $res = $stmt->get_result();
                  if ($res && ($row = $res->fetch_assoc())) {
                    $p = $row['photo'] ?? '';
                    if ($p) {
                      if (preg_match('~^https?://~i', $p)) { $webAvatar = $p; }
                      else { $webAvatar = BASE_URL . ltrim($p, '/'); }
                    }
                  }
                }
              }
            }
          }
        }
      ?>
      <li class="nav-item dropdown">
        <a class="nav-link user-avatar-trigger dropdown-toggle" data-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false" title="Account">
          <span class="avatar-hit-area">
            <img src="<?php echo htmlspecialchars($webAvatar); ?>" alt="avatar">
          </span>
        </a>
        <div class="dropdown-menu dropdown-menu-right">
          <span class="dropdown-item-text bn"><strong><?php echo $displayName; ?></strong><?php if($role){ echo ' — ' . htmlspecialchars($role); } ?></span>
          <div class="dropdown-divider"></div>
          <a href="<?php echo BASE_URL; ?>auth/profile.php" class="dropdown-item">
            <i class="far fa-user mr-2"></i> Profile
          </a>
          <div class="dropdown-divider"></div>
          <a href="<?php echo BASE_URL; ?>auth/logout.php" class="dropdown-item text-danger">
            <i class="fas fa-sign-out-alt mr-2"></i> Logout
          </a>
        </div>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->
    <script>
      // Fallback JS: ensure clicking exactly on the IMG toggles dropdown even if Bootstrap delegation misses
      document.addEventListener('DOMContentLoaded', function(){
        var trigger = document.querySelector('.nav-item.dropdown > a.user-avatar-trigger');
        if (trigger) {
          var img = trigger.querySelector('img');
          var hit = trigger.querySelector('.avatar-hit-area');
          var toggle = function(e){
            e.preventDefault();
            e.stopPropagation();
            if (typeof $ === 'function' && $(trigger).dropdown) {
              $(trigger).dropdown('toggle');
            } else {
              var menu = trigger.parentElement.querySelector('.dropdown-menu');
              if (menu){ menu.classList.toggle('show'); trigger.setAttribute('aria-expanded', menu.classList.contains('show')?'true':'false'); }
            }
          };
          if (img) img.addEventListener('click', toggle);
          if (hit) hit.addEventListener('click', toggle);
          // Also bind to the anchor itself for any remaining gap
          trigger.addEventListener('click', toggle);
        }
        // Close manual dropdown on outside click if we used fallback
        document.addEventListener('click', function(ev){
          var trigger = document.querySelector('.nav-item.dropdown > a.user-avatar-trigger');
          var dd = document.querySelector('.nav-item.dropdown .dropdown-menu.show');
          if (trigger && dd && !dd.contains(ev.target) && !trigger.contains(ev.target)) {
            dd.classList.remove('show'); trigger.setAttribute('aria-expanded','false');
          }
        });
      });
    </script>
