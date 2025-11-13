<?php
@include_once __DIR__ . '/config/config.php';
// Allow only teacher role on this page
$ALLOWED_ROLES = ['teacher'];
include "auth/session.php"; // gate before any output
include "includes/header.php";
include "includes/sidebar.php";
include "config/db.php";

$uid = (int)($_SESSION['id'] ?? 0);
$today = date('Y-m-d');

// Load today's invigilation duties for quick access
$duties = [];
if ($uid > 0) {
  $st = $conn->prepare(
    "SELECT d.plan_id, d.room_id, p.plan_name, p.shift, r.room_no, r.title\n"
    . "FROM exam_room_invigilation d\n"
    . "JOIN seat_plans p ON p.id=d.plan_id\n"
    . "JOIN seat_plan_rooms r ON r.id=d.room_id\n"
    . "WHERE d.teacher_user_id=? AND d.duty_date=?\n"
    . "ORDER BY p.plan_name, r.room_no"
  );
  if ($st) {
    $st->bind_param('is', $uid, $today);
    $st->execute();
    $res = $st->get_result();
    if ($res) { while ($row = $res->fetch_assoc()) { $duties[] = $row; } }
    $st->close();
  }
}
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
        /* Modern, animated action tiles */
        .link-tiles { gap: 1.25rem; }
        .link-tile { position: relative; border-radius: 16px; color: #fff; overflow: hidden; box-shadow: 0 10px 26px rgba(0,0,0,.12); transition: transform .25s ease, box-shadow .25s ease, filter .25s ease; }
        .link-tile:hover { transform: translateY(-4px); box-shadow: 0 18px 34px rgba(0,0,0,.18); text-decoration: none; filter: brightness(1.03); }
        .link-tile .inner { position: relative; padding: 22px 22px 56px; min-height: 150px; border-radius: 16px; }
        .link-tile .inner::before { content:""; position:absolute; inset:0; border-radius: inherit; background: linear-gradient(165deg, rgba(0,0,0,.28) 0%, rgba(0,0,0,.12) 100%); pointer-events:none; }
        .link-tile h4 { position:relative; z-index:1; font-weight: 800; margin: 0 0 6px; letter-spacing:.2px; color:#ffffff; text-shadow: 0 2px 6px rgba(0,0,0,.45), 0 1px 2px rgba(0,0,0,.6); }
        .link-tile p { position:relative; z-index:1; margin: 0; opacity: 1; font-weight:600; color:#f1f5f9; text-shadow: 0 2px 6px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.55); }
        .link-tile .icon-wrap { position: absolute; right: 16px; bottom: 16px; width: 54px; height: 54px; border-radius: 50%; background: rgba(255,255,255,.16); backdrop-filter: blur(2px); display:flex; align-items:center; justify-content:center; box-shadow: inset 0 0 0 1px rgba(255,255,255,.15); }
        .link-tile .icon-wrap i { font-size: 22px; color: #fff; }

        .tile-primary { background: linear-gradient(135deg, #2563eb 0%, #38bdf8 100%); background-size: 180% 180%; animation: gradientShift 18s ease infinite; }
        .tile-attendance { background: linear-gradient(135deg, #0f766e 0%, #10b981 100%); background-size: 180% 180%; animation: gradientShift 18s ease infinite; }
        .tile-info { background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%); background-size: 180% 180%; animation: gradientShift 18s ease infinite; }

        .tile-footer { position:absolute; left:0; right:0; bottom:0; padding:10px 16px; background: rgba(0,0,0,.32); color:#ffffff; text-align:right; font-weight:700; letter-spacing:.2px; text-shadow: 0 1px 2px rgba(0,0,0,.65); }
        .tile-footer i{ margin-left:6px; }

        @keyframes gradientShift { 0%{background-position:0% 50%} 50%{background-position:100% 50%} 100%{background-position:0% 50%} }
        @media (prefers-reduced-motion: reduce) { .tile-primary, .tile-attendance, .tile-info { animation: none; background-size: auto; } }

        /* Background */
        .content-wrapper { background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%); }

        /* Duties card */
        .card-glass { background: rgba(255,255,255,.75); backdrop-filter: blur(6px); border: 1px solid rgba(255,255,255,.6); border-radius: 14px; }
      </style>

      <div class="row justify-content-center link-tiles">
        <!-- Seat Find -->
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

        <!-- Take Attendance -->
        <div class="col-sm-10 col-md-5 col-lg-4">
          <a class="link-tile tile-attendance" href="<?= BASE_URL ?>attendance/mark_room_attendance.php">
            <div class="inner">
              <h4 class="bn">হাজিরা গ্রহণ</h4>
              <p class="bn">নিজ কক্ষের উপস্থিতি মার্কিং</p>
              <div class="icon-wrap"><i class="fas fa-clipboard-check"></i></div>
            </div>
            <div class="tile-footer bn">খুলুন <i class="fas fa-arrow-right"></i></div>
          </a>
        </div>

        <!-- Mark Entry -->
        <div class="col-sm-10 col-md-5 col-lg-4">
          <a class="link-tile tile-info" href="<?= BASE_URL ?>exam/mark_entry.php">
            <div class="inner">
              <h4 class="bn">মার্ক এন্ট্রি</h4>
              <p class="bn">পরীক্ষার নম্বর প্রদান</p>
              <div class="icon-wrap"><i class="fas fa-pen"></i></div>
            </div>
            <div class="tile-footer bn">খুলুন <i class="fas fa-arrow-right"></i></div>
          </a>
        </div>
      </div>

      <!-- My Duties Today -->
      <div class="row mt-4">
        <div class="col-lg-8 col-md-10 mx-auto">
          <div class="card card-glass">
            <div class="card-header"><strong class="bn">আজকের দায়িত্ব</strong> <small class="text-muted">(<?= htmlspecialchars($today) ?>)</small></div>
            <div class="card-body p-0">
              <?php if (!empty($duties)): ?>
                <div class="list-group list-group-flush">
                  <?php foreach ($duties as $d):
                    $plan = ($d['plan_name'] ?? 'Plan');
                    $shift = ($d['shift'] ?? '');
                    $roomTitle = trim(($d['room_no'] ?? '') . (($d['title'] ?? '') ? (' — ' . $d['title']) : ''));
                    $href = BASE_URL . 'attendance/mark_room_attendance.php?date=' . urlencode($today) . '&plan_id=' . (int)$d['plan_id'] . '&room_id=' . (int)$d['room_id'];
                  ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" href="<?= htmlspecialchars($href) ?>">
                      <span class="bn"><?= htmlspecialchars($plan) ?> (<?= htmlspecialchars($shift) ?>) — রুম: <?= htmlspecialchars($roomTitle) ?></span>
                      <i class="fas fa-arrow-right"></i>
                    </a>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="p-3 text-muted bn">আজ আপনার জন্য কোনো দায়িত্ব বরাদ্দ নেই।</div>
              <?php endif; ?>
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
