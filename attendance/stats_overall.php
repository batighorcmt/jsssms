<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role'])) { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
include '../config/db.php';

function is_controller(mysqli $conn, $userId){ $userId=(int)$userId; if ($userId<=0) return false; $q=$conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1'); return ($q && $q->num_rows>0); }
function require_ctrl_or_admin(mysqli $conn){ if (($_SESSION['role'] ?? '')==='super_admin' || is_controller($conn, $_SESSION['id'] ?? 0)) return; header('Location: ' . BASE_URL . 'auth/forbidden.php'); exit(); }

require_ctrl_or_admin($conn);

$plan_id = (int)($_GET['plan_id'] ?? 0);

// Load active plans only
$plans=[]; $hasStatus = ($conn->query("SHOW COLUMNS FROM seat_plans LIKE 'status'")->num_rows>0);
$sqlPlans = $hasStatus ? "SELECT id, plan_name, shift FROM seat_plans WHERE status='active' ORDER BY id DESC" : "SELECT id, plan_name, shift FROM seat_plans ORDER BY id DESC";
if ($rp=$conn->query($sqlPlans)){ while($r=$rp->fetch_assoc()){ $plans[]=$r; } }
if ($plan_id===0 && !empty($plans)) $plan_id=(int)$plans[0]['id'];

// Gather distinct exam dates strictly from exams mapped to the selected plan
$dates = [];
if ($plan_id>0){
  $sqlDates = "SELECT DISTINCT es.exam_date AS d FROM seat_plan_exams spe JOIN exam_subjects es ON es.exam_id=spe.exam_id WHERE spe.plan_id=".(int)$plan_id." AND es.exam_date IS NOT NULL AND es.exam_date<>'' AND es.exam_date<>'0000-00-00' ORDER BY es.exam_date ASC";
  if ($q=$conn->query($sqlDates)){
    while($r=$q->fetch_assoc()){ $d=$r['d'] ?? ''; if ($d) $dates[]=$d; }
  }
}

$summary = [];
if ($plan_id>0 && !empty($dates)){
  // Preload totals from attendance grouped by date for this plan
  $sql = "SELECT duty_date, SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS p, SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS a FROM exam_room_attendance WHERE plan_id=".(int)$plan_id." GROUP BY duty_date";
  if ($rs=$conn->query($sql)){
    while($r=$rs->fetch_assoc()){ $summary[$r['duty_date']] = ['p'=>(int)$r['p'], 'a'=>(int)$r['a']]; }
  }
}

// Rooms for the selected plan (all rooms will be columns with P/A)
$rooms = [];
if ($plan_id>0){
  if ($rr=$conn->query('SELECT id, room_no FROM seat_plan_rooms WHERE plan_id='.(int)$plan_id.' ORDER BY room_no')){
    while($r=$rr->fetch_assoc()){ $rooms[] = ['id'=>(int)$r['id'], 'room_no'=>$r['room_no']]; }
  }
}

// Build a matrix of counts for all dates: date -> class_name -> room_id -> {p,a}
$matrix = [];
if ($plan_id>0){
  $sql = "SELECT a.duty_date, a.room_id, COALESCE(c1.class_name, c2.class_name) AS class_name,
                 SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS p,
                 SUM(CASE WHEN a.status='absent' THEN 1 ELSE 0 END) AS a
          FROM exam_room_attendance a
          LEFT JOIN students s1 ON s1.student_id=a.student_id
          LEFT JOIN students s2 ON s2.id=a.student_id
          LEFT JOIN classes c1 ON c1.id=s1.class_id
          LEFT JOIN classes c2 ON c2.id=s2.class_id
          WHERE a.plan_id=".(int)$plan_id.
          " GROUP BY a.duty_date, a.room_id, class_name";
  if ($rs=$conn->query($sql)){
    while($r=$rs->fetch_assoc()){
      $d = $r['duty_date']; $rid = (int)$r['room_id']; $cls = $r['class_name'] ?? '-';
      if (!isset($matrix[$d])) $matrix[$d] = [];
      if (!isset($matrix[$d][$cls])) $matrix[$d][$cls] = [];
      $matrix[$d][$cls][$rid] = ['p'=>(int)$r['p'], 'a'=>(int)$r['a']];
    }
  }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6"><h4>Overall Attendance (All Dates)</h4></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li><li class="breadcrumb-item"><a href="<?= BASE_URL ?>attendance/stats.php">Attendance Stats</a></li><li class="breadcrumb-item active">Overall</li></ol></div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <style>
        .table-bright thead th{ background:#f8f9fa; color:#000; }
        .text-present{ color:#28a745 !important; font-weight:700; }
        .text-absent{ color:#dc3545 !important; font-weight:700; }
        .text-total{ color:#007bff !important; font-weight:700; }

        .table-fit{ table-layout:fixed; width:100%; font-size:12px; border-collapse:collapse; }
        .table-fit th, .table-fit td{ padding:.2rem .35rem; box-sizing:border-box; overflow:hidden; text-overflow:ellipsis; }
        .no-wrap{ white-space:nowrap; }
        .page-break-print{ page-break-before: always; }
        /* Column width tuning */
          /* Column width tuning (screen) */
          col.col-idx{ width:44px; }
          col.col-date{ width:120px; }
          col.col-class{ width:120px; }
          col.col-room{ width:30px; }
          col.col-total{ width:95px; }
        /* Prevent ellipsis on room subheaders (P/A/T) */
        thead tr.subhead th{ overflow:visible !important; text-overflow:clip !important; padding:.12rem .18rem !important; }

        /* Print header layout copied from exam_details.php */
        .ph-grid{ display:grid; grid-template-columns: auto 1fr auto; align-items:center; }
        .ph-left img{ height:60px; object-fit:contain; display:block; }
        .ph-center{ text-align:center; }
        .ph-name{ font-size:26px; font-weight:800; line-height:1.05; margin:0; }
        .ph-meta{ font-size:12px; margin:0; line-height:1.1; }
        .ph-title{ font-size:17px; font-weight:700; margin:2px 0 0 0; display:inline-block; padding:2px 8px; border-radius:4px; background:#eef3ff; border:1px solid #cdd9ff; }
        .ph-sub{ font-size:11px; margin:2px 0 0 0; line-height:1.1; }
        .print-brand { margin-top:8px; font-size:11px; text-align:right; color:#333; }

        @page { size: auto; margin: 8mm; }

        @media print{
          body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; font-size:11px; background:#fff !important; }
          .content-wrapper, .content, .container-fluid, .card, .card-body{ background:#fff !important; }
          .container-fluid{ width:100% !important; max-width:none !important; padding:0 6mm !important; }
          .content{ padding:0 !important; }
          .table-responsive{ overflow: visible !important; }
          .table-bright thead th{ background:#fff !important; color:#000 !important; }
          .breadcrumb, .card.mb-3, .btn, .form-inline, .main-footer, .sidebar, .navbar{ display:none !important; }
          .content-wrapper{ margin:0; }
          .table-fit{ font-size:10.5px; }
          .table-fit th, .table-fit td{ padding:.15rem .25rem; }
          .no-wrap{ white-space:nowrap; }
          /* Slightly tighter widths for print */
          col.col-idx{ width:46px; }
          col.col-date{ width:130px; }
          col.col-class{ width:120px; }
          col.col-room{ width:34px; }
          col.col-total{ width:100px; }
          thead tr.subhead th{ overflow:visible !important; text-overflow:clip !important; padding:.1rem .14rem !important; }
        }
      </style>
      <?php
        $orgName = isset($institute_name) && $institute_name ? $institute_name : 'Institution Name';
        $orgAddr = isset($institute_address) && $institute_address ? $institute_address : '';
        $brandName = isset($company_name) && $company_name ? $company_name : 'Batighor Computers';
        $brandUrl = isset($company_website) && $company_website ? $company_website : '';
      ?>
      <!-- Print Header (copied design from exam_details.php) -->
      <div class="d-none d-print-block" style="margin-bottom:10px;">
        <?php
            $name = isset($institute_name) ? $institute_name : 'Institution Name';
            $addr = isset($institute_address) ? $institute_address : '';
            $phone = isset($institute_phone) ? $institute_phone : '';
            $logo = isset($institute_logo) ? $institute_logo : 'assets/img/logo.png';
            $logoSrc = (preg_match('~^https?://~',$logo)) ? $logo : (BASE_URL . ltrim($logo,'/'));
            $currPlanLabel = '';
            foreach ($plans as $p){ if ((int)$p['id']===$plan_id){ $currPlanLabel = $p['plan_name'].' ('.$p['shift'].')'; break; } }
        ?>
        <div class="ph-grid">
            <div class="ph-left">
                <img src="<?= htmlspecialchars($logoSrc) ?>" alt="Logo" onerror="this.style.display='none'">
            </div>
            <div class="ph-center">
                <div class="ph-name"><?= htmlspecialchars($name) ?></div>
                <div class="ph-meta">
                    <?php if ($addr !== ''): ?><?= htmlspecialchars($addr) ?><?php endif; ?>
                    <?php if ($phone !== ''): ?><?= ($addr!=='')? ' | ' : '' ?>Phone: <?= htmlspecialchars($phone) ?><?php endif; ?>
                </div>
                <div class="ph-title">Overall Attendance Summary (All Dates)</div>
                <div class="ph-sub">Seat Plan: <?= htmlspecialchars($currPlanLabel) ?> | Printed: <?= date('d/m/Y') ?></div>
            </div>
            <div class="ph-right"></div>
        </div>
        <hr style="margin:4px 0;" />
      </div>
      <div class="card mb-3">
        <div class="card-body">
          <form id="overallForm" class="form-inline" method="get">
            <div class="form-row align-items-end w-100">
              <div class="form-group mr-2">
                <label class="mr-2">Seat Plan</label>
                <select id="ovPlan" name="plan_id" class="form-control" required>
                  <?php foreach($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $plan_id===(int)$p['id']?'selected':'' ?>><?= htmlspecialchars($p['plan_name']) ?> (<?= htmlspecialchars($p['shift']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ml-auto">
                <a class="btn btn-outline-secondary mr-2" href="<?= BASE_URL ?>attendance/stats.php?date=<?= urlencode(date('Y-m-d')) ?>&plan_id=<?= (int)$plan_id ?>">Daily (By Date)</a>
                <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Screen table: show all rooms together -->
        <div class="card d-print-none">
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0 table-bright text-center table-fit">
              <colgroup>
                <col class="col-idx" />
                <col class="col-date" />
                <col class="col-class" />
                  <?php foreach($rooms as $_cg): ?>
                  <col class="col-room" />
                  <col class="col-room" />
                  <col class="col-room" />
                <?php endforeach; ?>
                <col class="col-total" />
                <col class="col-total" />
              </colgroup>
              <thead class="thead-light">
                <tr>
                  <th rowspan="2" class="align-middle no-wrap">#</th>
                  <th rowspan="2" class="align-middle no-wrap">Date</th>
                  <th rowspan="2" class="align-middle no-wrap">Class</th>
                    <?php foreach($rooms as $rm): ?>
                    <th colspan="3" class="align-middle text-center"><?= htmlspecialchars($rm['room_no']) ?></th>
                  <?php endforeach; ?>
                  <th rowspan="2" class="align-middle text-center no-wrap">Present</th>
                  <th rowspan="2" class="align-middle text-center no-wrap">Absent</th>
                </tr>
                  <tr class="subhead">
                    <?php foreach($rooms as $rm): ?>
                    <th class="text-center align-middle">P</th>
                    <th class="text-center align-middle">A</th>
                    <th class="text-center align-middle">T</th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php
                  $dateSn = 0;
                  if (!empty($dates)){
                    foreach($dates as $d){
                      $dateSn++;
                      $clsMap = $matrix[$d] ?? [];
                      $classes = array_keys($clsMap);
                      sort($classes, SORT_NATURAL | SORT_FLAG_CASE);
                      if (empty($classes)) { $classes = ['-']; }
                      $first = true; $rowspan = max(1, count($classes));
                      foreach($classes as $cn){
                        $cells = $clsMap[$cn] ?? [];
                        $any=false; $anyAll=false; $tpAll=0; $taAll=0;
                        echo '<tr>';
                        if ($first){ echo '<td rowspan="'.$rowspan.'">'.$dateSn.'</td>'; }
                        if ($first){
                          $disp = ($t=strtotime($d)) ? date('d/m/Y',$t) : $d;
                          echo '<td rowspan="'.$rowspan.'" class="no-wrap">'.htmlspecialchars($disp).'</td>';
                          $first=false;
                        }
                        echo '<td class="no-wrap">'.htmlspecialchars($cn).'</td>';
                          foreach($rooms as $rm){
                          $rid=(int)$rm['id'];
                          $has = isset($cells[$rid]);
                          $p = $has ? (int)$cells[$rid]['p'] : '';
                          $a = $has ? (int)$cells[$rid]['a'] : '';
                          $t = $has ? ($p + $a) : '';
                          if ($has) { $any=true; }
                          echo '<td class="text-present">'.($p===0 ? '0' : htmlspecialchars((string)$p)).'</td>';
                          echo '<td class="text-absent">'.($a===0 ? '0' : htmlspecialchars((string)$a)).'</td>';
                          echo '<td class="text-total">'.($t===0 ? '0' : htmlspecialchars((string)$t)).'</td>';
                        }
                        // Totals across all rooms (not just the current group)
                          foreach($rooms as $rmAll){
                          $ridAll=(int)$rmAll['id'];
                          if (isset($cells[$ridAll])){ $tpAll += (int)$cells[$ridAll]['p']; $taAll += (int)$cells[$ridAll]['a']; $anyAll=true; }
                        }
                        echo '<td class="text-present">'.($anyAll ? ($tpAll===0 ? '0' : htmlspecialchars((string)$tpAll)) : '').'</td>';
                        echo '<td class="text-absent">'.($anyAll ? ($taAll===0 ? '0' : htmlspecialchars((string)$taAll)) : '').'</td>';
                        echo '</tr>';
                      }
                    }
                  }
                  if ($dateSn===0){
                    echo '<tr><td colspan="'.(3 + (count($rooms)*3) + 2).'" class="text-center text-muted">No data to display.</td></tr>';
                  }
                ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

        <?php $roomCount = count($rooms); $roomGroups = ($roomCount>12) ? [array_slice($rooms,0,ceil($roomCount/2)), array_slice($rooms,ceil($roomCount/2))] : [$rooms]; $groupIndex=0; ?>
        <?php foreach($roomGroups as $groupRooms): $groupIndex++; ?>
        <div class="card d-none d-print-block <?= ($groupIndex>1 ? 'page-break-print' : '') ?>">
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-bordered table-hover mb-0 table-bright text-center table-fit">
                <colgroup>
                  <col class="col-idx" />
                  <col class="col-date" />
                  <col class="col-class" />
                  <?php foreach($groupRooms as $_cg): ?>
                    <col class="col-room" />
                    <col class="col-room" />
                    <col class="col-room" />
                  <?php endforeach; ?>
                  <col class="col-total" />
                  <col class="col-total" />
                </colgroup>
                <thead class="thead-light">
                  <tr>
                    <th rowspan="2" class="align-middle no-wrap">#</th>
                    <th rowspan="2" class="align-middle no-wrap">Date</th>
                    <th rowspan="2" class="align-middle no-wrap">Class</th>
                    <?php foreach($groupRooms as $rm): ?>
                      <th colspan="3" class="align-middle text-center"><?= htmlspecialchars($rm['room_no']) ?></th>
                    <?php endforeach; ?>
                    <th rowspan="2" class="align-middle text-center no-wrap">Present</th>
                    <th rowspan="2" class="align-middle text-center no-wrap">Absent</th>
                  </tr>
                  <tr class="subhead">
                    <?php foreach($groupRooms as $rm): ?>
                      <th class="text-center align-middle">P</th>
                      <th class="text-center align-middle">A</th>
                      <th class="text-center align-middle">T</th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $dateSn = 0;
                    if (!empty($dates)){
                      foreach($dates as $d){
                        $dateSn++;
                        $clsMap = $matrix[$d] ?? [];
                        $classes = array_keys($clsMap);
                        sort($classes, SORT_NATURAL | SORT_FLAG_CASE);
                        if (empty($classes)) { $classes = ['-']; }
                        $first = true; $rowspan = max(1, count($classes));
                        foreach($classes as $cn){
                          $cells = $clsMap[$cn] ?? [];
                          $any=false; $anyAll=false; $tpAll=0; $taAll=0;
                          echo '<tr>';
                          if ($first){ echo '<td rowspan="'.$rowspan.'">'.$dateSn.'</td>'; }
                          if ($first){
                            $disp = ($t=strtotime($d)) ? date('d/m/Y',$t) : $d;
                            echo '<td rowspan="'.$rowspan.'" class="no-wrap">'.htmlspecialchars($disp).'</td>';
                            $first=false;
                          }
                          echo '<td class="no-wrap">'.htmlspecialchars($cn).'</td>';
                          foreach($groupRooms as $rm){
                            $rid=(int)$rm['id'];
                            $has = isset($cells[$rid]);
                            $p = $has ? (int)$cells[$rid]['p'] : '';
                            $a = $has ? (int)$cells[$rid]['a'] : '';
                            $t = $has ? ($p + $a) : '';
                            if ($has) { $any=true; }
                            echo '<td class="text-present">'.($p===0 ? '0' : htmlspecialchars((string)$p)).'</td>';
                            echo '<td class="text-absent">'.($a===0 ? '0' : htmlspecialchars((string)$a)).'</td>';
                            echo '<td class="text-total">'.($t===0 ? '0' : htmlspecialchars((string)$t)).'</td>';
                          }
                          // Totals across all rooms (not just the current group)
                          foreach($rooms as $rmAll){
                            $ridAll=(int)$rmAll['id'];
                            if (isset($cells[$ridAll])){ $tpAll += (int)$cells[$ridAll]['p']; $taAll += (int)$cells[$ridAll]['a']; $anyAll=true; }
                          }
                          echo '<td class="text-present">'.($anyAll ? ($tpAll===0 ? '0' : htmlspecialchars((string)$tpAll)) : '').'</td>';
                          echo '<td class="text-absent">'.($anyAll ? ($taAll===0 ? '0' : htmlspecialchars((string)$taAll)) : '').'</td>';
                          echo '</tr>';
                        }
                      }
                    }
                    if ($dateSn===0){
                      $__cols = 3 + (count($groupRooms)*3) + 2;
                      echo '<tr><td colspan="'.$__cols.'" class="text-center text-muted">No data to display.</td></tr>';
                    }
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- Print-only branding footer (copied from exam_details.php) -->
        <?php
          $devName = isset($developer_name) ? $developer_name : '';
          $devPhone = isset($developer_phone) ? $developer_phone : '';
          $coName  = isset($company_name) ? $company_name : '';
          $coWeb   = isset($company_website) ? $company_website : '';
        ?>
        <div class="print-brand d-none d-print-block">
          <div class="brand-line">
            <?php if ($coName): ?><strong><?= htmlspecialchars($coName) ?></strong><?php endif; ?>
            <?php if ($coName && ($devName || $devPhone || $coWeb)) echo ' — '; ?>
            <?php if ($devName): ?>Developer: <?= htmlspecialchars($devName) ?><?php endif; ?>
            <?php if ($devPhone): ?> | Phone: <?= htmlspecialchars($devPhone) ?><?php endif; ?>
            <?php if ($coWeb): ?> | Website: <?= htmlspecialchars($coWeb) ?><?php endif; ?>
          </div>
        </div>

    </div>
  </section>
</div>

<?php include '../includes/footer.php'; ?>
<script>
  // Auto-submit plan change
  (function(){
    var form = document.getElementById('overallForm');
    var plan = document.getElementById('ovPlan');
    if (form && plan){ plan.addEventListener('change', function(){ form.submit(); }); }
  })();
</script>
