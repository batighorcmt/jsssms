<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role'])) { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
include '../config/db.php';

// Ensure plan-to-exam mapping exists for date scoping
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  exam_id INT NOT NULL,
  UNIQUE KEY uniq_plan_exam (plan_id, exam_id),
  INDEX idx_plan (plan_id)
)");

function is_controller(mysqli $conn, $userId){ $userId=(int)$userId; if ($userId<=0) return false; $q=$conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1'); return ($q && $q->num_rows>0); }
function can_access(mysqli $conn, $userId, $role, $date, $plan_id, $room_id){ if ($role==='super_admin' || is_controller($conn,$userId)) return true; $st=$conn->prepare('SELECT 1 FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? AND teacher_user_id=? LIMIT 1'); $st->bind_param('siii',$date,$plan_id,$room_id,$userId); $st->execute(); $st->store_result(); return ($st->num_rows>0); }

$date = $_GET['date'] ?? date('Y-m-d');
if ($date === '0000-00-00' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) { $date = '1970-01-01'; }
$plan_id = (int)($_GET['plan_id'] ?? 0);
$room_id = (int)($_GET['room_id'] ?? 0);
$uid = (int)($_SESSION['id'] ?? 0);
$role = $_SESSION['role'] ?? '';
$isCtrlOrAdmin = ($role==='super_admin' || is_controller($conn,$uid));

// Load active plans for selection (teachers only see their assigned)
$plans = [];
$hasStatus = ($conn->query("SHOW COLUMNS FROM seat_plans LIKE 'status'")->num_rows>0);
$sqlPlans = $hasStatus ? "SELECT id, plan_name, shift FROM seat_plans WHERE status='active' ORDER BY id DESC" : "SELECT id, plan_name, shift FROM seat_plans ORDER BY id DESC";
if ($role==='teacher'){
  $st = $conn->prepare("SELECT DISTINCT p.id, p.plan_name, p.shift FROM exam_room_invigilation d JOIN seat_plans p ON p.id=d.plan_id WHERE d.teacher_user_id=? AND d.duty_date=?" );
  $st->bind_param('is',$uid,$date); $st->execute(); $res=$st->get_result(); if ($res){ while($r=$res->fetch_assoc()){ $plans[]=$r; } }
} else {
  if ($rp=$conn->query($sqlPlans)){ while($r=$rp->fetch_assoc()){ $plans[]=$r; } }
}
if ($plan_id===0 && !empty($plans)) $plan_id=(int)$plans[0]['id'];

// Rooms for selected plan for this user/date
$rooms=[];
if ($plan_id>0){
  if ($role==='teacher'){
    $st=$conn->prepare('SELECT r.id, r.room_no, r.title FROM exam_room_invigilation d JOIN seat_plan_rooms r ON r.id=d.room_id WHERE d.teacher_user_id=? AND d.duty_date=? AND d.plan_id=? ORDER BY r.room_no');
    $st->bind_param('isi',$uid,$date,$plan_id); $st->execute(); $res=$st->get_result(); if ($res){ while($r=$res->fetch_assoc()){ $rooms[]=$r; } }
  } else {
    if ($rr=$conn->query('SELECT id, room_no, title FROM seat_plan_rooms WHERE plan_id='.(int)$plan_id.' ORDER BY room_no')){ while($r=$rr->fetch_assoc()){ $rooms[]=$r; } }
  }
}
if ($room_id===0 && !empty($rooms)) $room_id=(int)$rooms[0]['id'];
// Ensure selected room_id belongs to current plan/user scope; else reset to first
if ($room_id>0 && !empty($rooms)){
  $roomIds = array_map(function($r){ return (int)$r['id']; }, $rooms);
  if (!in_array((int)$room_id, $roomIds, true)){
    $room_id = (int)$rooms[0]['id'];
  }
}

// Load allocations + any existing attendance
$students=[];
if ($plan_id>0 && $room_id>0){
  $attOn = '0';
  if (preg_match('~^\\d{4}-\\d{2}-\\d{2}$~',$date)){
    $attOn = "att.duty_date='".$conn->real_escape_string($date)."' AND att.plan_id=".(int)$plan_id." AND att.room_id=".(int)$room_id." AND att.student_id=a.student_id";
  }
  $sql =
    "SELECT a.student_id, " .
    "       COALESCE(s1.student_name, s2.student_name) AS student_name, " .
    "       COALESCE(s1.roll_no, s2.roll_no) AS roll_no, " .
    "       COALESCE(c1.class_name, c2.class_name) AS class_name, " .
    "       a.col_no, a.bench_no, a.position, " .
    "       att.status " .
    "FROM seat_plan_allocations a " .
    "LEFT JOIN students s1 ON s1.student_id=a.student_id " .
    "LEFT JOIN students s2 ON s2.id=a.student_id " .
    "LEFT JOIN classes c1 ON c1.id=s1.class_id " .
    "LEFT JOIN classes c2 ON c2.id=s2.class_id " .
    "LEFT JOIN exam_room_attendance att ON " . $attOn . " " .
    "WHERE a.plan_id=" . (int)$plan_id . " AND a.room_id=" . (int)$room_id . " " .
    "ORDER BY a.col_no, a.bench_no, a.position";
  if ($rs=$conn->query($sql)){ while($r=$rs->fetch_assoc()){ $students[]=$r; } }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6"><h4>Room Attendance</h4></div>
        <div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard<?= ($role==='teacher' ? '_teacher' : '') ?>.php">Home</a></li><li class="breadcrumb-item active">Attendance</li></ol></div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <?php
        // Precompute room stats: total/male/female from allocations; present/absent from attendance
        $stats = ['total'=>0,'male'=>0,'female'=>0,'present'=>0,'absent'=>0];
        if ($plan_id>0 && $room_id>0){
          $sqlAlloc = "SELECT COUNT(*) AS total,
                              SUM(CASE WHEN COALESCE(s1.gender, s2.gender)='Male' THEN 1 ELSE 0 END) AS male,
                              SUM(CASE WHEN COALESCE(s1.gender, s2.gender)='Female' THEN 1 ELSE 0 END) AS female
                       FROM seat_plan_allocations a
                       LEFT JOIN students s1 ON s1.student_id=a.student_id
                       LEFT JOIN students s2 ON s2.id=a.student_id
                       WHERE a.plan_id=".(int)$plan_id." AND a.room_id=".(int)$room_id;
          if ($ra=$conn->query($sqlAlloc)){
            if ($row=$ra->fetch_assoc()){
              $stats['total'] = (int)($row['total'] ?? 0);
              $stats['male'] = (int)($row['male'] ?? 0);
              $stats['female'] = (int)($row['female'] ?? 0);
            }
          }
          if (preg_match('~^\\d{4}-\\d{2}-\\d{2}$~',$date)){
            $sqlAtt = "SELECT SUM(CASE WHEN status='present' THEN 1 ELSE 0 END) AS p,
                              SUM(CASE WHEN status='absent' THEN 1 ELSE 0 END) AS a
                       FROM exam_room_attendance
                       WHERE duty_date='".$conn->real_escape_string($date)."' AND plan_id=".(int)$plan_id." AND room_id=".(int)$room_id; 
            if ($rt=$conn->query($sqlAtt)){
              if ($row=$rt->fetch_assoc()){
                $stats['present'] = (int)($row['p'] ?? 0);
                $stats['absent'] = (int)($row['a'] ?? 0);
              }
            }
          }
        }
      ?>
      <?php if ($plan_id>0 && $room_id>0): ?>
      <?php
        // Class-wise totals (allocated per class in this room)
        $classCounts = [];
        $sqlCls = "SELECT COALESCE(c1.class_name, c2.class_name) AS class_name, COUNT(*) AS cnt
                   FROM seat_plan_allocations a
                   LEFT JOIN students s1 ON s1.student_id=a.student_id
                   LEFT JOIN students s2 ON s2.id=a.student_id
                   LEFT JOIN classes c1 ON c1.id=s1.class_id
                   LEFT JOIN classes c2 ON c2.id=s2.class_id
                   WHERE a.plan_id=".(int)$plan_id." AND a.room_id=".(int)$room_id.
                   " GROUP BY class_name ORDER BY class_name";
        if ($rcs = $conn->query($sqlCls)) { while($r=$rcs->fetch_assoc()){ $classCounts[] = $r; } }
      ?>
      <style>
        .stats-wrap{ display:flex; flex-direction:column; gap:.5rem; }
        .stats-row{ display:flex; flex-wrap:wrap; gap:.5rem; }
        .stat-chip{ display:flex; align-items:baseline; gap:.35rem; padding:.35rem .6rem; border-radius:8px; color:#fff; font-weight:600; }
        .stat-chip .label{ font-size:.8rem; opacity:.9; }
        .stat-chip .value{ font-size:1.05rem; letter-spacing:.3px; }
        .bg-soft-secondary{ background:#6c757d; }
        .bg-soft-info{ background:#17a2b8; }
        .bg-soft-warning{ background:#ffc107; color:#212529; }
        .bg-soft-success{ background:#28a745; }
        .bg-soft-danger{ background:#dc3545; }
        .class-row{ display:flex; flex-wrap:wrap; gap:.4rem; }
        .class-pill{ background:#f1f3f5; color:#333; border:1px solid #e2e6ea; border-radius:999px; padding:.25rem .55rem; font-size:.85rem; }
      </style>
      <div class="card shadow-none border-0 mb-2">
        <div class="card-body py-2">
          <div class="stats-wrap">
            <div class="stats-row">
              <div class="stat-chip bg-soft-secondary"><span class="label">Total</span><span class="value" id="stat-total"><?= (int)$stats['total'] ?></span></div>
              <div class="stat-chip bg-soft-info"><span class="label">Male</span><span class="value" id="stat-male"><?= (int)$stats['male'] ?></span></div>
              <div class="stat-chip bg-soft-warning"><span class="label">Female</span><span class="value" id="stat-female"><?= (int)$stats['female'] ?></span></div>
              <div class="stat-chip bg-soft-success"><span class="label">Present</span><span class="value" id="stat-present"><?= (int)$stats['present'] ?></span></div>
              <div class="stat-chip bg-soft-danger"><span class="label">Absent</span><span class="value" id="stat-absent"><?= (int)$stats['absent'] ?></span></div>
            </div>
            <?php if (!empty($classCounts)): ?>
            <div class="class-row">
              <?php foreach($classCounts as $cc): ?>
                <span class="class-pill"><?= htmlspecialchars($cc['class_name'] ?: 'Class') ?>: <strong><?= (int)$cc['cnt'] ?></strong></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <div class="card mb-3">
        <div class="card-body">
          <form id="filterForm" class="form-inline" method="get">
            <div class="form-row align-items-end w-100">
              <?php if (!$isCtrlOrAdmin): ?>
                <input type="hidden" name="date" value="<?= htmlspecialchars($date) ?>">
              <?php endif; ?>
              <div class="form-group col-md-4 col-12">
                <label>Seat Plan</label>
                <select id="planSelect" name="plan_id" class="form-control w-100" required>
                  <?php foreach($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===(int)$plan_id?'selected':'' ?>><?= htmlspecialchars($p['plan_name'].' ('.$p['shift'].')') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3 col-12">
                <label>Date</label>
                <?php if ($isCtrlOrAdmin): ?>
                  <?php
                    // Dates strictly from exams mapped to the selected plan
                    $examDates = [];
                    if ($plan_id>0){
                      // Avoid comparing DATE to '' (strict mode). Check only for NOT NULL.
                      $sqlDates = "SELECT DISTINCT es.exam_date AS d FROM seat_plan_exams spe JOIN exam_subjects es ON es.exam_id=spe.exam_id WHERE spe.plan_id=".(int)$plan_id." AND es.exam_date IS NOT NULL ORDER BY es.exam_date ASC";
                      if ($q = $conn->query($sqlDates)){
                        while($r = $q->fetch_assoc()){ $d=$r['d'] ?? ''; if ($d) $examDates[] = $d; }
                      }
                    }
                    // Normalize selection within mapped dates only
                    if (empty($examDates)) { $date = ''; }
                    else if (!preg_match('~^\\d{4}-\\d{2}-\\d{2}$~',$date) || !in_array($date, $examDates, true)) { $date = $examDates[0]; }
                  ?>
                  <?php if (!empty($examDates)): ?>
                    <select id="dateSelect" name="date" class="form-control w-100" required>
                      <?php foreach($examDates as $d): $disp = (strtotime($d)? date('d/m/Y', strtotime($d)) : $d); ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= $date===$d?'selected':'' ?>><?= htmlspecialchars($disp) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <select id="dateSelect" class="form-control w-100" disabled>
                      <option value="">-- No mapped exam dates --</option>
                    </select>
                    <small class="form-text text-muted">Seat Plan → Edit এ গিয়ে Exams নির্বাচন করুন; এখানে তারিখগুলো দেখাবে।</small>
                  <?php endif; ?>
                <?php else: ?>
                  <input type="text" value="<?= htmlspecialchars($date) ?>" class="form-control w-100" readonly>
                <?php endif; ?>
              </div>
              <div class="form-group col-md-3 col-12">
                <label>Room</label>
                <select id="roomSelect" name="room_id" class="form-control w-100" required>
                  <?php foreach($rooms as $r): ?>
                    <option value="<?= (int)$r['id'] ?>" <?= (int)$r['id']===(int)$room_id?'selected':'' ?>><?= htmlspecialchars($r['room_no'].($r['title']? (' — '.$r['title']):'')) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </form>
        </div>
      </div>

      <?php if ($plan_id>0 && $room_id>0): ?>
      <?php if (!can_access($conn, $uid, $role, $date, $plan_id, $room_id)): ?>
        <div class="alert alert-warning">You are not authorized to mark attendance for this room and date.</div>
      <?php else: ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <strong>Attendance — <?= htmlspecialchars($date) ?> | Room #<?= (int)$room_id ?></strong>
          <button type="button" class="btn btn-sm btn-success" id="btnMarkAll" data-mode="present">Mark all present</button>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-hover mb-0">
              <thead class="thead-light"><tr><th>Roll</th><th>Name</th><th>Class</th><th>Status</th></tr></thead>
              <tbody>
              <?php foreach($students as $s): $sid=(int)$s['student_id']; $status=$s['status']?:''; ?>
                <tr data-student="<?= $sid ?>">
                  <td><?= htmlspecialchars($s['roll_no'] ?? '') ?></td>
                  <td><?= htmlspecialchars($s['student_name'] ?? '') ?></td>
                  <td><?= htmlspecialchars($s['class_name'] ?? '') ?></td>
                  <td>
                    <div class="btn-group btn-group-toggle" data-toggle="buttons">
                      <label class="btn btn-sm <?= $status==='present'?'btn-success active':'btn-outline-success' ?> js-mark" data-status="present">
                        <input type="radio" autocomplete="off" <?= $status==='present'?'checked':'' ?>> Present
                      </label>
                      <label class="btn btn-sm <?= $status==='absent'?'btn-danger active':'btn-outline-danger' ?> js-mark" data-status="absent">
                        <input type="radio" autocomplete="off" <?= $status==='absent'?'checked':'' ?>> Absent
                      </label>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>

    </div>
  </section>
</div>

<?php include '../includes/footer.php'; ?>
<script>
// Auto-submit on plan/room change; reset room when plan changes
document.addEventListener('DOMContentLoaded', function(){
  var form = document.getElementById('filterForm');
  var plan = document.getElementById('planSelect');
  var room = document.getElementById('roomSelect');
  var dateSel = document.getElementById('dateSelect');
  if (plan){ plan.addEventListener('change', function(){ if (room){ room.removeAttribute('name'); } form && form.submit(); }); }
  if (room){ room.addEventListener('change', function(){ if (!room.getAttribute('name')) room.setAttribute('name','room_id'); form && form.submit(); }); }
  if (dateSel){ dateSel.addEventListener('change', function(){ if (room){ room.removeAttribute('name'); } form && form.submit(); }); }
});
function postMark(studentId, status){
  return fetch('attendance_api.php', {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: new URLSearchParams({ action:'mark', date:'<?= htmlspecialchars($date) ?>', plan_id:'<?= (int)$plan_id ?>', room_id:'<?= (int)$room_id ?>', student_id:String(studentId), status:status })
  }).then(r=>r.json());
}
function recalcStatsFromDOM(){
  var present = 0, absent = 0;
  document.querySelectorAll('tbody tr').forEach(function(tr){
    var grp = tr.querySelector('.btn-group'); if (!grp) return;
    var p = grp.querySelector('.js-mark[data-status="present"]');
    var a = grp.querySelector('.js-mark[data-status="absent"]');
    if (p && p.classList.contains('active')) present++;
    else if (a && a.classList.contains('active')) absent++;
  });
  var sp = document.getElementById('stat-present'); if (sp) sp.textContent = String(present);
  var sa = document.getElementById('stat-absent'); if (sa) sa.textContent = String(absent);
}
document.addEventListener('click', function(e){
  var label = e.target.closest && e.target.closest('.js-mark');
  if (!label) return;
  var tr = label.closest('tr'); var sid = tr ? tr.getAttribute('data-student') : null; if (!sid) return;
  var status = label.getAttribute('data-status');
  label.closest('.btn-group').querySelectorAll('.btn').forEach(b=>b.classList.remove('active','btn-success','btn-danger'));
  label.classList.add(status==='present'?'btn-success':'btn-danger','active');
  label.classList.remove('btn-outline-success','btn-outline-danger');
  // Async save
  postMark(sid, status).then(function(res){
    if(!res || !res.success){ if(window.showToast) window.showToast('Error', 'Save failed', 'error'); return; }
    var cells = tr.querySelectorAll('td');
    var roll = cells[0] ? cells[0].textContent.trim() : '';
    var name = cells[1] ? cells[1].textContent.trim() : '';
    if(window.showToast) window.showToast('Success', 'Marked ' + (status==='present'?'present':'absent') + ' — ' + roll + ' ' + name, 'success');
    recalcStatsFromDOM();
  }).catch(function(){ if(window.showToast) window.showToast('Error', 'Save failed', 'error'); });
});
document.getElementById('btnMarkAll') && document.getElementById('btnMarkAll').addEventListener('click', function(){
  var btn = this; var mode = btn.getAttribute('data-mode') || 'present';
  var action = (mode === 'present') ? 'mark_all_present' : 'mark_all_absent';
  fetch('attendance_api.php', {
    method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
    body: new URLSearchParams({ action:action, date:'<?= htmlspecialchars($date) ?>', plan_id:'<?= (int)$plan_id ?>', room_id:'<?= (int)$room_id ?>' })
  }).then(r=>r.json()).then(function(res){ if(res && res.success){
    if (mode === 'present'){
      document.querySelectorAll('tbody tr .btn-group .js-mark[data-status="present"]').forEach(function(lbl){
        lbl.closest('.btn-group').querySelectorAll('.btn').forEach(b=>b.classList.remove('active','btn-success','btn-danger'));
        lbl.classList.add('btn-success','active'); lbl.classList.remove('btn-outline-success');
      });
      if(window.showToast) window.showToast('সফল', 'All marked present', 'success');
      btn.textContent = 'Mark all absent'; btn.classList.remove('btn-success'); btn.classList.add('btn-danger'); btn.setAttribute('data-mode','absent');
    } else {
      document.querySelectorAll('tbody tr .btn-group .js-mark[data-status="absent"]').forEach(function(lbl){
        lbl.closest('.btn-group').querySelectorAll('.btn').forEach(b=>b.classList.remove('active','btn-success','btn-danger'));
        lbl.classList.add('btn-danger','active'); lbl.classList.remove('btn-outline-danger');
      });
      if(window.showToast) window.showToast('সফল', 'All marked absent', 'success');
      btn.textContent = 'Mark all present'; btn.classList.remove('btn-danger'); btn.classList.add('btn-success'); btn.setAttribute('data-mode','present');
    }
    recalcStatsFromDOM();
  } else { if(window.showToast) window.showToast('ত্রুটি', 'Bulk save failed', 'error'); } }).catch(function(){ if(window.showToast) window.showToast('ত্রুটি', 'Bulk save failed', 'error'); });
});
// Recalc once on load to sync initial counts with UI state
document.addEventListener('DOMContentLoaded', function(){ recalcStatsFromDOM(); });
</script>
