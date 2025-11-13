<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role'])) { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
include '../config/db.php';

// Helpers
function is_controller(mysqli $conn, $userId){
    $userId = (int)$userId; if ($userId<=0) return false;
    $q = $conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1');
    return ($q && $q->num_rows>0);
}
function require_ctrl_or_admin(mysqli $conn){
    if (isset($_SESSION['role']) && $_SESSION['role']==='super_admin') return true;
    if (is_controller($conn, $_SESSION['id'] ?? 0)) return true;
    header('Location: ' . BASE_URL . 'auth/forbidden.php'); exit();
}

// Create tables if not exist
$conn->query("CREATE TABLE IF NOT EXISTS exam_controllers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)");
$conn->query("CREATE TABLE IF NOT EXISTS exam_room_invigilation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  plan_id INT NOT NULL,
  room_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  assigned_by INT NULL,
  assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_duty (duty_date, plan_id, room_id)
)");
$conn->query("CREATE TABLE IF NOT EXISTS exam_room_attendance (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  plan_id INT NOT NULL,
  room_id INT NOT NULL,
  student_id INT NOT NULL,
  status ENUM('present','absent') NOT NULL,
  marked_by INT NOT NULL,
  marked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_att (duty_date, plan_id, room_id, student_id),
  INDEX idx_room_date (duty_date, plan_id, room_id)
)");

// Ensure plan-to-exam mapping table exists (for date filtering)
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_exams (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  exam_id INT NOT NULL,
  UNIQUE KEY uniq_plan_exam (plan_id, exam_id),
  INDEX idx_plan (plan_id)
)");

require_ctrl_or_admin($conn);

// Load teachers (users.role='teacher') with display name from teachers table if possible
$teachers = [];
$sqlT = "SELECT u.id AS user_id, u.username, COALESCE(t.name, CONCAT('Teacher(', u.username, ')')) AS display_name
         FROM users u
         LEFT JOIN teachers t ON t.contact = u.username
         WHERE u.role='teacher'
         ORDER BY display_name ASC";
if ($rt = $conn->query($sqlT)) { while($r=$rt->fetch_assoc()){ $teachers[]=$r; } }

// Load active plans
$plans = [];
$hasStatus = ($conn->query("SHOW COLUMNS FROM seat_plans LIKE 'status'")->num_rows>0);
$sqlPlans = $hasStatus ? "SELECT id, plan_name, shift FROM seat_plans WHERE status='active' ORDER BY id DESC" : "SELECT id, plan_name, shift FROM seat_plans ORDER BY id DESC";
if ($rp = $conn->query($sqlPlans)) { while($r=$rp->fetch_assoc()){ $plans[]=$r; } }

$toast = null;
// Handle set controller (super_admin only)
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='set_controller' && ($_SESSION['role'] ?? '')==='super_admin'){
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid>0){
        $conn->query('UPDATE exam_controllers SET active=0');
        $ins = $conn->prepare('INSERT INTO exam_controllers (user_id, active) VALUES (?,1)');
        $ins->bind_param('i', $uid);
    if ($ins->execute()) $toast=['type'=>'success','msg'=>'Exam controller set']; else $toast=['type'=>'error','msg'=>'Failed to set controller'];
    }
}
// Handle save duties for a date + plan
$postedDutyMap = null;
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='save_duties'){
    $duty_date = trim($_POST['duty_date'] ?? '');
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    $map = $_POST['room_teacher'] ?? [];
  if (!preg_match('~^\d{4}-\d{2}-\d{2}$~',$duty_date) || $duty_date==='0000-00-00' || $plan_id<=0){ $toast=['type'=>'error','msg'=>'Invalid date or plan']; }
    else {
    // Enforce: a teacher can be assigned to only one room for the selected date+plan
    $teacherCounts = [];
    foreach ($map as $room_id => $teacher_user_id){
      $tid = (int)$teacher_user_id; if ($tid>0){ $teacherCounts[$tid] = ($teacherCounts[$tid] ?? 0) + 1; }
    }
    $dupTeachers = array_keys(array_filter($teacherCounts, function($c){ return $c>1; }));
    if (!empty($dupTeachers)){
      $toast=['type'=>'error','msg'=>'Each teacher can be assigned to only one room for this date and plan.'];
      // Preserve posted selections to show in the form
      $postedDutyMap = [];
      foreach ($map as $rid=>$tid){ $rid=(int)$rid; $tid=(int)$tid; if($rid>0){ $postedDutyMap[$rid]=$tid; } }
    } else {
        $assigned_by = (int)($_SESSION['id'] ?? 0);
        foreach ($map as $room_id => $teacher_user_id){
            $room_id=(int)$room_id; $teacher_user_id=(int)$teacher_user_id; if ($room_id<=0 || $teacher_user_id<=0) continue;
            // upsert
            $stmt = $conn->prepare('INSERT INTO exam_room_invigilation (duty_date, plan_id, room_id, teacher_user_id, assigned_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE teacher_user_id=VALUES(teacher_user_id), assigned_by=VALUES(assigned_by), assigned_at=CURRENT_TIMESTAMP');
            $stmt->bind_param('siiii', $duty_date, $plan_id, $room_id, $teacher_user_id, $assigned_by);
            @$stmt->execute();
        }
    $toast=['type'=>'success','msg'=>'Duties saved'];
    }
    }
}

// Current controller
$controller = null;
if ($rc=$conn->query('SELECT user_id FROM exam_controllers WHERE active=1 ORDER BY id DESC LIMIT 1')){
    if ($rc->num_rows>0){ $cid=$rc->fetch_assoc()['user_id']; foreach($teachers as $t){ if ((int)$t['user_id']===(int)$cid){ $controller=$t; break; } } }
}

// Selected date/plan
$sel_date = isset($_POST['duty_date']) ? $_POST['duty_date'] : (date('Y-m-d'));
$sel_plan = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : (count($plans)? (int)$plans[0]['id'] : 0);

// Rooms for selected plan
$rooms = [];
if ($sel_plan>0){
    if ($rr=$conn->query('SELECT id, room_no, title FROM seat_plan_rooms WHERE plan_id='.(int)$sel_plan.' ORDER BY room_no')){ while($r=$rr->fetch_assoc()){ $rooms[]=$r; } }
}
// Existing duties map
$dutyMap = [];
if ($sel_plan>0 && preg_match('~^\d{4}-\d{2}-\d{2}$~',$sel_date)){
    $q = $conn->prepare('SELECT room_id, teacher_user_id FROM exam_room_invigilation WHERE duty_date=? AND plan_id=?');
    $q->bind_param('si', $sel_date, $sel_plan); $q->execute(); $res=$q->get_result();
    if ($res){ while($r=$res->fetch_assoc()){ $dutyMap[(int)$r['room_id']] = (int)$r['teacher_user_id']; } }
}
// If we had a validation error, prefer showing posted selections
if (is_array($postedDutyMap)) { foreach($postedDutyMap as $rid=>$tid){ $dutyMap[(int)$rid]=(int)$tid; } }

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
                  // Normalize selected date: restrict to mapped dates only; fallback to 1970-01-01 to avoid strict-mode errors
                  if (empty($examDates)) { $sel_date = '1970-01-01'; }
                  else if ($sel_date==='0000-00-00' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $sel_date) || !in_array($sel_date, $examDates, true)) { $sel_date = $examDates[0]; }
        <div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li><li class="breadcrumb-item active">Exam Duty</li></ol></div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <?php /* Toasts will be shown via JS using global showToast */ ?>

      <?php if (($_SESSION['role'] ?? '')==='super_admin'): ?>
      <div class="card mb-3">
        <div class="card-header"><strong>Set Exam Controller</strong></div>
        <div class="card-body">
          <form method="post" class="form-inline">
            <input type="hidden" name="action" value="set_controller">
            <div class="form-group mr-2">
              <select name="user_id" class="form-control" required>
                <option value="">-- Select Teacher --</option>
                <?php foreach($teachers as $t): ?>
                  <option value="<?= (int)$t['user_id'] ?>" <?= ($controller && (int)$controller['user_id']===(int)$t['user_id'])?'selected':'' ?>><?= htmlspecialchars($t['display_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-primary">Save</button>
            <div class="ml-3 text-muted">Current: <?= $controller? htmlspecialchars($controller['display_name']) : 'None' ?></div>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header"><strong>Assign Room Duties</strong></div>
        <div class="card-body">
          <form id="dutiesForm" method="post">
            <input type="hidden" name="action" value="save_duties">
            <div class="form-row">
              <div class="form-group col-md-4">
                <label>Seat Plan</label>
                <select id="filterPlan" name="plan_id" class="form-control" required>
                  <option value="">-- Select Plan --</option>
                  <?php foreach($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $sel_plan===(int)$p['id']?'selected':'' ?>><?= htmlspecialchars($p['plan_name']) ?> (<?= htmlspecialchars($p['shift']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-3">
                <label>Date</label>
                <?php
                  // Load distinct mapped exam dates for the selected plan (yyyy-mm-dd)
                  $examDates = [];
                  if ($sel_plan>0){
                    // Avoid comparing DATE to '' (strict mode). Check only for NOT NULL and not '0000-00-00'.
                    $sqlDates = "SELECT DISTINCT es.exam_date AS d FROM seat_plan_exams spe JOIN exam_subjects es ON es.exam_id=spe.exam_id WHERE spe.plan_id=".(int)$sel_plan." AND es.exam_date IS NOT NULL AND es.exam_date<>'0000-00-00' ORDER BY es.exam_date ASC";
                    if ($q = $conn->query($sqlDates)){
                      while($r = $q->fetch_assoc()){ $d=$r['d'] ?? ''; if ($d) $examDates[] = $d; }
                    }
                  }
                  // Normalize selected date: restrict to mapped dates only; fallback to 1970-01-01 to avoid strict-mode errors
                  if (empty($examDates)) { $sel_date = '1970-01-01'; }
                  else if ($sel_date==='0000-00-00' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $sel_date) || !in_array($sel_date, $examDates, true)) { $sel_date = $examDates[0]; }
                ?>
                <?php if (!empty($examDates)): ?>
                  <select id="filterDate" name="duty_date" class="form-control" required>
                    <?php foreach($examDates as $d): ?>
                      <?php $disp = ($t=strtotime($d)) ? date('d/m/Y',$t) : $d; ?>
                      <option value="<?= htmlspecialchars($d) ?>" <?= $sel_date===$d?'selected':'' ?>><?= htmlspecialchars($disp) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php else: ?>
                  <select id="filterDate" class="form-control" disabled>
                    <option value="">-- No mapped exam dates --</option>
                  </select>
                  <small class="form-text text-muted">Seat Plan → Edit এ গিয়ে Exams নির্বাচন করুন; তখন তারিখগুলো এখানে দেখাবে।</small>
                  <?php if ($sel_plan>0): ?>
                  <div class="alert alert-warning mt-2" role="alert">
                    No exam dates available for this plan. Please map exams to the plan.
                    <a class="alert-link" href="<?= BASE_URL ?>exam/seat_plan_edit.php?plan_id=<?= (int)$sel_plan ?>">Open Seat Plan → Edit</a>
                  </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
            <?php if (!empty($rooms)): ?>
            <div class="table-responsive">
              <table class="table table-bordered table-hover">
                <thead class="thead-light"><tr><th>Room</th><th>Assign Teacher</th></tr></thead>
                <tbody>
                  <?php foreach($rooms as $r): $rid=(int)$r['id']; $assigned=$dutyMap[$rid] ?? 0; ?>
                    <tr>
                      <td><?= htmlspecialchars($r['room_no']) ?><?= $r['title']? ' — '.htmlspecialchars($r['title']) : '' ?></td>
                      <td>
                        <select name="room_teacher[<?= $rid ?>]" class="form-control js-teacher-select" required>
                          <option value="">-- Select --</option>
                          <?php foreach($teachers as $t): ?>
                            <option value="<?= (int)$t['user_id'] ?>" <?= $assigned===(int)$t['user_id']?'selected':'' ?>><?= htmlspecialchars($t['display_name']) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <button type="button" id="btnSaveDuties" class="btn btn-success">Save Duties</button>
            <?php else: ?>
              <div class="text-muted">Select a plan to load rooms.</div>
            <?php endif; ?>
          </form>
        </div>
      </div>

    </div>
  </section>
</div>

<?php include '../includes/footer.php'; ?>
<!-- Select2 assets -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<style>
  /* Fix Select2 text clipping in Bootstrap 4 forms */
  .select2-container .select2-selection--single{ height: calc(2.25rem + 2px) !important; }
  .select2-container--default .select2-selection--single .select2-selection__rendered{ line-height: 2.25rem !important; }
  .select2-container--default .select2-selection--single .select2-selection__arrow{ height: calc(2.25rem + 2px) !important; }
  .select2-container .select2-selection--single .select2-selection__rendered{ padding-left: .75rem; }
  .select2-container--default .select2-selection--single{ border-color: #ced4da; }
  .select2-container--default.select2-container--focus .select2-selection--single{ border-color: #80bdff; box-shadow: 0 0 0 .2rem rgba(0,123,255,.25); }
  .select2-dropdown{ z-index: 1060; }
</style>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
  // Initialize Select2 on teacher selects and enforce uniqueness across rooms
  document.addEventListener('DOMContentLoaded', function(){
    var $ = window.jQuery || window.$;
    // Filter auto-submit without saving duties
    var form = document.getElementById('dutiesForm');
    function submitFilter(){
      var actionInput = form && form.querySelector('input[name="action"]');
      if (actionInput) actionInput.value = 'filter';
      if (form) form.submit();
    }
    var planSel = document.getElementById('filterPlan');
    var dateSel = document.getElementById('filterDate');
    if (planSel) planSel.addEventListener('change', submitFilter);
    if (dateSel) dateSel.addEventListener('change', submitFilter);

    var saveBtn = document.getElementById('btnSaveDuties');
    if (saveBtn) saveBtn.addEventListener('click', function(){
      var actionInput = form && form.querySelector('input[name="action"]');
      if (actionInput) actionInput.value = 'save_duties';
      if (form) form.submit();
    });

    if ($ && $.fn.select2){
      $('select[name="user_id"]').addClass('js-teacher-controller').select2({ width:'100%', placeholder:'-- Select Teacher --', dropdownParent: $('body') });
      $('.js-teacher-select').select2({ width:'100%', placeholder:'-- Select --', dropdownParent: $('body') });

      function updateDisabledOptions(){
        var selectedVals = {};
        $('.js-teacher-select').each(function(){ var v = $(this).val(); if (v) selectedVals[v]=true; });
        $('.js-teacher-select').each(function(){
          var $sel = $(this), keep = $sel.val();
          $sel.find('option').each(function(){
            var val = this.value;
            if (!val) return;
            if (val === keep) { this.disabled = false; }
            else { this.disabled = !!selectedVals[val]; }
          });
        });
        $('.js-teacher-select').each(function(){ $(this).trigger('change.select2'); });
      }

      $('.js-teacher-select').on('change', function(){
        var v = $(this).val(); if (!v) { updateDisabledOptions(); return; }
        // Clear same teacher from other selects
        $('.js-teacher-select').not(this).each(function(){ if ($(this).val() === v){ $(this).val('').trigger('change'); } });
        updateDisabledOptions();
      });
      // Initial state
      updateDisabledOptions();
    }

    // Show server toast if present
    <?php if ($toast): ?>
      if (window.showToast) window.showToast(<?= json_encode($toast['type']==='success'?'Success':'Error') ?>, <?= json_encode($toast['msg']) ?>, <?= json_encode($toast['type']) ?>);
    <?php endif; ?>
  });
</script>
