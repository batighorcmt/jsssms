<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role'])) { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
include '../config/db.php';

function is_controller(mysqli $conn, $userId){ $userId=(int)$userId; if ($userId<=0) return false; $q=$conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1'); return ($q && $q->num_rows>0); }
function require_ctrl_or_admin(mysqli $conn){ if (($_SESSION['role'] ?? '')==='super_admin' || is_controller($conn, $_SESSION['id'] ?? 0)) return; header('Location: ' . BASE_URL . 'auth/forbidden.php'); exit(); }

require_ctrl_or_admin($conn);

$date = $_GET['date'] ?? date('Y-m-d');
$plan_id = (int)($_GET['plan_id'] ?? 0);

// Load active plans
$plans=[]; $hasStatus = ($conn->query("SHOW COLUMNS FROM seat_plans LIKE 'status'")->num_rows>0);
$sqlPlans = $hasStatus ? "SELECT id, plan_name, shift FROM seat_plans WHERE status='active' ORDER BY id DESC" : "SELECT id, plan_name, shift FROM seat_plans ORDER BY id DESC";
if ($rp=$conn->query($sqlPlans)){ while($r=$rp->fetch_assoc()){ $plans[]=$r; } }
if ($plan_id===0 && !empty($plans)) $plan_id=(int)$plans[0]['id'];

// Build date options for dropdown (distinct exam dates)
$dateOptions = [];
if ($q=$conn->query("SELECT DISTINCT exam_date FROM exam_subjects WHERE exam_date IS NOT NULL AND exam_date<>'' AND exam_date<>'0000-00-00' ORDER BY exam_date ASC")){
  while($r=$q->fetch_assoc()){
    $d = $r['exam_date'];
    if ($d) $dateOptions[] = $d;
  }
}
// Ensure current selected date is present in options
if ($date && !in_array($date, $dateOptions, true)) array_unshift($dateOptions, $date);

$rows = [];
if (preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) && $plan_id>0){
  $sql = "SELECT r.room_no, COALESCE(c1.class_name, c2.class_name) AS class_name,
                 SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present_cnt,
                 SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS absent_cnt
          FROM exam_room_attendance a
          JOIN seat_plan_rooms r ON r.id=a.room_id AND r.plan_id=a.plan_id
          LEFT JOIN students s1 ON s1.student_id=a.student_id
          LEFT JOIN students s2 ON s2.id=a.student_id
          LEFT JOIN classes c1 ON c1.id=s1.class_id
          LEFT JOIN classes c2 ON c2.id=s2.class_id
          WHERE a.duty_date='".$conn->real_escape_string($date)."' AND a.plan_id=".(int)$plan_id.
          " GROUP BY r.room_no, class_name ORDER BY r.room_no, class_name";
  if ($rs=$conn->query($sql)){ while($r=$rs->fetch_assoc()){ $rows[]=$r; } }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6"><h4>Room-wise Attendance Stats</h4></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li><li class="breadcrumb-item active">Attendance Stats</li></ol></div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <div class="card mb-3">
        <div class="card-body">
          <form id="statsForm" class="form-inline" method="get">
            <div class="form-row align-items-end w-100">
              <div class="form-group mr-2">
                <label class="mr-2">Date</label>
                <select id="statDate" name="date" class="form-control" required>
                  <?php foreach($dateOptions as $d): $label = (strtotime($d) ? date('d/m/Y', strtotime($d)) : htmlspecialchars($d)); ?>
                    <option value="<?= htmlspecialchars($d) ?>" <?= $date===$d ? 'selected' : '' ?>><?= $label ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group mr-2">
                <label class="mr-2">Seat Plan</label>
                <select id="statPlan" name="plan_id" class="form-control" required>
                  <?php foreach($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $plan_id===(int)$p['id']?'selected':'' ?>><?= htmlspecialchars($p['plan_name']) ?> (<?= htmlspecialchars($p['shift']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ml-auto">
                <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>attendance/stats_overall.php?plan_id=<?= (int)$plan_id ?>">Overall (All Dates)</a>
              </div>
            </div>
          </form>
        </div>
      </div>

      <?php
        // Build class set and per-room matrix with totals
        $classes = [];
        $byRoom = [];
        foreach ($rows as $r) {
          $room = (string)($r['room_no'] ?? '');
          $cls = (string)($r['class_name'] ?? '-');
          $p = (int)($r['present_cnt'] ?? 0);
          $a = (int)($r['absent_cnt'] ?? 0);
          if (!isset($classes[$cls])) $classes[$cls] = true;
          if (!isset($byRoom[$room])) $byRoom[$room] = ['classes'=>[], 'tp'=>0, 'ta'=>0];
          $byRoom[$room]['classes'][$cls] = ['p'=>$p, 'a'=>$a];
          $byRoom[$room]['tp'] += $p; $byRoom[$room]['ta'] += $a;
        }
        $classList = array_keys($classes);
        sort($classList, SORT_NATURAL | SORT_FLAG_CASE);
      ?>
      <?php if (!empty($byRoom)): ?>
      <style>
        .table-compact th, .table-compact td { padding: .25rem .35rem; }
        .table-compact thead th { font-size: 14px; }
        .table-compact tbody td { font-size: 15px; }
        @media (max-width: 576px){
          .table-compact th, .table-compact td { padding: .2rem .3rem; }
          .table-compact thead th { font-size: 13px; }
          .table-compact tbody td { font-size: 14px; }
        }
      </style>
      <div class="card">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-compact">
              <thead class="thead-light">
                <tr>
                  <th rowspan="2" class="align-middle text-center">Room</th>
                  <?php foreach($classList as $cn): ?>
                    <th colspan="2" class="text-center text-center"><?= htmlspecialchars($cn) ?></th>
                  <?php endforeach; ?>
                  <th rowspan="2" class="align-middle text-center">Total Present</th>
                  <th rowspan="2" class="align-middle text-center">Total Absent</th>
                </tr>
                <tr>
                  <?php foreach($classList as $cn): ?>
                    <th class="text-center">P</th>
                    <th class="text-center">A</th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach($byRoom as $roomNo => $info): ?>
                  <tr>
                    <td><?= htmlspecialchars($roomNo) ?></td>
                    <?php foreach($classList as $cn): $cell = $info['classes'][$cn] ?? ['p'=>0,'a'=>0]; ?>
                      <td class="text-center"><?= (int)$cell['p'] ?></td>
                      <td class="text-center text-danger"><?= (int)$cell['a'] ?></td>
                    <?php endforeach; ?>
                    <td class="text-center font-weight-bold text-success"><?= (int)$info['tp'] ?></td>
                    <td class="text-center font-weight-bold text-danger"><?= (int)$info['ta'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php else: ?>
        <div class="alert alert-info">Select date and plan to view stats.</div>
      <?php endif; ?>

    </div>
  </section>
</div>

<?php include '../includes/footer.php'; ?>
<script>
  // Auto-submit filters on change
  (function(){
    var form = document.getElementById('statsForm');
    if (!form) return;
    var dateEl = document.getElementById('statDate');
    var planEl = document.getElementById('statPlan');
    function submit(){ form.submit(); }
    if (dateEl) dateEl.addEventListener('change', submit);
    if (planEl) planEl.addEventListener('change', submit);
  })();
</script>
