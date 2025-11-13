<?php
@include_once __DIR__ . '/config/config.php';
// Allow only teacher role on this page
$ALLOWED_ROLES = ['teacher'];
include "auth/session.php"; // gate before any output
include "includes/header.php";
include "includes/sidebar.php";
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="bn">শিক্ষক ড্যাশবোর্ড</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard_teacher.php">Home</a></li>
            <li class="breadcrumb-item active">Teacher Dashboard</li>
          </ol>
        </div>
      </div>
    </div><!-- /.container-fluid -->
  </section>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <style>
        /* Sleek tiles for teacher shortcuts */
        .link-tiles { gap: 1.25rem; }
        .link-tile { position: relative; border-radius: 14px; color: #fff; overflow: hidden; box-shadow: 0 10px 26px rgba(0,0,0,.12); transition: transform .25s ease, box-shadow .25s ease, filter .25s ease; }
        .link-tile:hover { transform: translateY(-4px); box-shadow: 0 18px 34px rgba(0,0,0,.18); text-decoration: none; filter: brightness(1.03); }
  .link-tile .inner { position: relative; padding: 22px 22px 56px; min-height: 150px; border-radius: 14px; }
  /* Overlay to ensure text contrast on bright/animated backgrounds */
  .link-tile .inner::before { content:""; position:absolute; inset:0; border-radius: inherit; background: linear-gradient(165deg, rgba(0,0,0,.28) 0%, rgba(0,0,0,.12) 100%); pointer-events:none; }
  .link-tile h4 { position:relative; z-index:1; font-weight: 800; margin: 0 0 6px; letter-spacing:.2px; color:#ffffff; text-shadow: 0 2px 6px rgba(0,0,0,.45), 0 1px 2px rgba(0,0,0,.6); }
  .link-tile p { position:relative; z-index:1; margin: 0; opacity: 1; font-weight:600; color:#f1f5f9; text-shadow: 0 2px 6px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.55); }
        .link-tile .icon-wrap { position: absolute; right: 16px; bottom: 16px; width: 52px; height: 52px; border-radius: 50%; background: rgba(255,255,255,.16); backdrop-filter: blur(2px); display:flex; align-items:center; justify-content:center; box-shadow: inset 0 0 0 1px rgba(255,255,255,.15); }
        .link-tile .icon-wrap i { font-size: 22px; color: #fff; }
        /* Updated palettes: deep blue/cyan & emerald/teal for higher appeal */
        .tile-info {
          background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 35%, #2563eb 65%, #38bdf8 100%);
          background-size: 220% 220%;
          animation: gradientShift 10s ease infinite;
        }
        .tile-warning {
          background: linear-gradient(135deg, #022c22 0%, #065f46 35%, #10b981 70%, #34d399 100%);
          background-size: 220% 220%;
          animation: gradientShift 10s ease infinite;
        }
        /* Extra colorful palette for seat search */
        .tile-primary {
          background: linear-gradient(135deg, #6d28d9 0%, #db2777 50%, #f97316 100%);
          background-size: 220% 220%;
          animation: gradientShift 10s ease infinite;
        }
  .tile-footer { position:absolute; left:0; right:0; bottom:0; padding:10px 16px; background: rgba(0,0,0,.32); color:#ffffff; text-align:right; font-weight:700; letter-spacing:.2px; text-shadow: 0 1px 2px rgba(0,0,0,.65); }
  .tile-footer i{ margin-left:6px; }

        @keyframes gradientShift {
          0%   { background-position: 0% 50%; }
          50%  { background-position: 100% 50%; }
          100% { background-position: 0% 50%; }
        }

        /* Respect users who prefer reduced motion */
        @media (prefers-reduced-motion: reduce) {
          .tile-info, .tile-warning { animation: none; background-size: auto; }
        }

        /* Subtle colorful background for dashboard section */
        .content-wrapper { background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 35%, #fff7ed 100%); }
      </style>

      <div class="row justify-content-center link-tiles">
        <!-- Seat Find tile (placed before Mark Entry) -->
        <div class="col-sm-10 col-md-5 col-lg-4">
          <a class="link-tile tile-primary" href="<?= BASE_URL ?>exam/seat_plan_find.php">
            <div class="inner">
              <h4 class="bn">সীট খুঁজুন</h4>
              <p class="bn">শিক্ষার্থীর আসন খুঁজে নিন</p>
              <div class="icon-wrap"><i class="fas fa-search"></i></div>
            </div>
            <div class="tile-footer bn">খুলুন <i class="fas fa-arrow-right"></i></div>
          </a>
        </div>
        <!-- Mark Entry tile -->
        <div class="col-sm-10 col-md-5 col-lg-4">
          <a class="link-tile tile-info" href="<?= BASE_URL ?>exam/mark_entry.php">
            <div class="inner">
              <h4 class="bn">মার্ক এন্ট্রি</h4>
              <p class="bn">পরীক্ষার মার্কস দ্রুত যোগ করুন</p>
              <div class="icon-wrap"><i class="fas fa-pen"></i></div>
            </div>
            <div class="tile-footer bn">খুলুন <i class="fas fa-arrow-right"></i></div>
          </a>
        </div>
        <!-- Profile tile -->
        <div class="col-sm-10 col-md-5 col-lg-4">
          <a class="link-tile tile-warning" href="<?= BASE_URL ?>auth/profile.php">
            <div class="inner">
              <h4 class="bn">প্রোফাইল</h4>
              <p class="bn">তথ্য আপডেট ও ছবি পরিবর্তন</p>
              <div class="icon-wrap"><i class="far fa-user"></i></div>
            </div>
            <div class="tile-footer bn">খুলুন <i class="fas fa-arrow-right"></i></div>
          </a>
        </div>
      </div>

    </div>
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php include "includes/footer.php"; ?>
