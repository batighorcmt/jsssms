<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
// Optional debug: enable via ?debug=1
if (isset($_GET['debug']) && $_GET['debug'] == '1') {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}

include '../config/db.php';

// ---------- New Schema: Seat Plans (exam-independent) ----------
// seat_plans: high-level plan
$conn->query("CREATE TABLE IF NOT EXISTS seat_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_name VARCHAR(150) NOT NULL,
  shift VARCHAR(20) NOT NULL DEFAULT 'Morning',
    status ENUM('active','inactive','completed') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)");

// Ensure status column exists in case of older installs
if ($chk = $conn->query("SHOW COLUMNS FROM seat_plans LIKE 'status'")) {
        if ($chk->num_rows === 0) {
                @$conn->query("ALTER TABLE seat_plans ADD COLUMN status ENUM('active','inactive','completed') NOT NULL DEFAULT 'active' AFTER shift");
        }
}

// seat_plan_classes: classes included in a plan
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_classes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  class_id INT NOT NULL,
  UNIQUE KEY uniq_plan_class (plan_id, class_id),
  INDEX idx_plan (plan_id)
)");

// seat_plan_rooms: rooms under a plan
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_rooms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  room_no VARCHAR(50) NOT NULL,
  title VARCHAR(255) NULL,
  columns_count TINYINT NOT NULL DEFAULT 3,
  col1_benches INT NOT NULL DEFAULT 0,
  col2_benches INT NOT NULL DEFAULT 0,
  col3_benches INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_plan_room (plan_id, room_no),
  INDEX idx_plan_room (plan_id)
)");

// seat_plan_allocations: student seating
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_allocations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  room_id INT NOT NULL,
  student_id INT NOT NULL,
  col_no INT NOT NULL,
  bench_no INT NOT NULL,
  position ENUM('L','R') NOT NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_seat (room_id, col_no, bench_no, position),
  UNIQUE KEY uniq_student_in_plan (plan_id, student_id),
  INDEX idx_plan (plan_id),
  INDEX idx_room (room_id)
)");

// seat_plan_exams: map a seat plan to one or more exams
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    exam_id INT NOT NULL,
    UNIQUE KEY uniq_plan_exam (plan_id, exam_id),
    INDEX idx_plan (plan_id)
)");

// ---------- Helpers ----------
function fetch_classes(mysqli $conn){
        $out = [];
    $res = $conn->query('SELECT id, class_name FROM classes ORDER BY id ASC');
        if ($res) { while($r=$res->fetch_assoc()){ $out[]=$r; } }
        return $out;
}
// Plans helpers
function fetch_plans(mysqli $conn){
    $out = [];
    $res = $conn->query('SELECT id, plan_name, shift, status, created_at FROM seat_plans ORDER BY id DESC');
    if ($res) { while($r=$res->fetch_assoc()){ $out[]=$r; } }
    return $out;
}
function fetch_exams(mysqli $conn){
    $out = [];
    if ($rs = $conn->query('SELECT e.id, e.exam_name, c.class_name FROM exams e LEFT JOIN classes c ON c.id=e.class_id ORDER BY e.id DESC')){
        while($r=$rs->fetch_assoc()){ $out[]=$r; }
    }
    return $out;
}
function room_capacity($r){
        $b = (int)($r['col1_benches'] ?? 0) + (int)($r['col2_benches'] ?? 0) + (int)($r['col3_benches'] ?? 0);
        return $b * 2; // 2 seats per bench
}

// Per-plan summaries
function get_plan_classes(mysqli $conn, int $planId): string {
    $names = [];
    $sql = 'SELECT c.class_name FROM seat_plan_classes spc JOIN classes c ON c.id = spc.class_id WHERE spc.plan_id = ? ORDER BY c.id ASC';
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $planId);
        if ($st->execute() && ($res = $st->get_result())) {
            while ($r = $res->fetch_assoc()) { $names[] = (string)($r['class_name'] ?? ''); }
        }
    }
    return trim(implode(', ', array_filter($names))) ?: '—';
}

function get_plan_exams(mysqli $conn, int $planId): string {
    $labels = [];
    $sql = 'SELECT e.exam_name, c.class_name FROM seat_plan_exams spe JOIN exams e ON e.id = spe.exam_id LEFT JOIN classes c ON c.id = e.class_id WHERE spe.plan_id = ? ORDER BY e.id DESC';
    if ($st = $conn->prepare($sql)) {
        $st->bind_param('i', $planId);
        if ($st->execute() && ($res = $st->get_result())) {
            while ($r = $res->fetch_assoc()) {
                $en = trim((string)($r['exam_name'] ?? ''));
                $cn = trim((string)($r['class_name'] ?? ''));
                $labels[] = $cn !== '' ? ($en.' — '.$cn) : $en;
            }
        }
    }
    return trim(implode(', ', array_filter($labels))) ?: '—';
}

// ---------- Actions (Plan create/delete) ----------
$toast = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

    if ($action === 'create_plan') {
        $plan_name = trim($_POST['plan_name'] ?? '');
        $shift = trim($_POST['shift'] ?? 'Morning');
        $classesSel = $_POST['classes'] ?? [];
        $examsSel = $_POST['exams'] ?? [];
        if ($plan_name === '' || empty($classesSel)) {
        $toast = ['type'=>'danger','msg'=>'Please provide Plan Name and select at least one Class.'];
        } else {
        $ins = $conn->prepare('INSERT INTO seat_plans (plan_name, shift) VALUES (?,?)');
        $ins->bind_param('ss', $plan_name, $shift);
        if ($ins->execute()) {
            $pid = $conn->insert_id;
            $ci = $conn->prepare('INSERT INTO seat_plan_classes (plan_id, class_id) VALUES (?,?)');
            foreach ($classesSel as $cid) { $c=(int)$cid; if ($c>0) { $ci->bind_param('ii', $pid, $c); @$ci->execute(); } }
            if (!empty($examsSel)){
                $ei = $conn->prepare('INSERT INTO seat_plan_exams (plan_id, exam_id) VALUES (?,?)');
                foreach ($examsSel as $eid){ $e=(int)$eid; if ($e>0){ $ei->bind_param('ii', $pid, $e); @$ei->execute(); } }
            }
            header('Location: seat_plan_rooms.php?plan_id='.$pid);
            exit();
        } else {
            $toast = ['type'=>'danger','msg'=>'Failed to create plan.'];
        }
        }
    }
    if ($action === 'delete_plan') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        if ($pid>0) {
        // cascade delete related
        $conn->query('DELETE spa FROM seat_plan_allocations spa JOIN seat_plan_rooms spr ON spa.room_id=spr.id WHERE spr.plan_id='.$pid);
        $conn->query('DELETE FROM seat_plan_rooms WHERE plan_id='.$pid);
        $conn->query('DELETE FROM seat_plan_classes WHERE plan_id='.$pid);
        $conn->query('DELETE FROM seat_plan_exams WHERE plan_id='.$pid);
        $conn->query('DELETE FROM seat_plans WHERE id='.$pid);
        $toast = ['type'=>'success','msg'=>'Plan deleted'];
        }
    }
    if ($action === 'update_status') {
        $pid = (int)($_POST['plan_id'] ?? 0);
        $status = strtolower(trim((string)($_POST['status'] ?? '')));
        $allowed = ['active','inactive','completed'];
        if ($pid>0 && in_array($status, $allowed, true)){
            $st = $conn->prepare('UPDATE seat_plans SET status=? WHERE id=?');
            $st->bind_param('si', $status, $pid);
            if ($st->execute()) { $toast = ['type'=>'success','msg'=>'Status updated']; }
            else { $toast = ['type'=>'danger','msg'=>'Failed to update status']; }
        }
    }
}

// ---------- Load data for view ----------
$classes = fetch_classes($conn);
$exams = fetch_exams($conn);
$plans = fetch_plans($conn);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6"><h4>Exam Seat Plan</h4></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Seat Plan</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>
    <section class="content">
        <div class="container-fluid">
            <?php if ($toast): ?>
            <div class="alert alert-<?= $toast['type']==='success'?'success':'danger' ?>"><?= htmlspecialchars($toast['msg']) ?></div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header"><strong>Create New Seat Plan</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="create_plan">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Plan Name</label>
                                <input type="text" name="plan_name" class="form-control" placeholder="e.g., Mid-November Plan" required>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Shift</label>
                                <select name="shift" class="form-control" required>
                                    <option value="Morning">Morning</option>
                                    <option value="Afternoon">Afternoon</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Classes (multi-select)</label>
                                <select name="classes[]" class="form-control" multiple size="6" required>
                                    <?php foreach($classes as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple</small>
                            </div>
                            <div class="form-group col-md-12 mt-2">
                                <label>Exams included in this plan (optional, multi-select)</label>
                                <select name="exams[]" class="form-control" multiple size="6">
                                    <?php foreach($exams as $e): ?>
                                        <option value="<?= (int)$e['id'] ?>"><?= htmlspecialchars(($e['exam_name'] ?? 'Exam').' — '.($e['class_name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">If selected, date filters and related views will only use these exams' dates.</small>
                            </div>
                        </div>
                        <button class="btn btn-success">Create Plan</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Seat Plans</strong>
                    <div class="form-inline">
                        <input id="plansFilter" type="text" class="form-control form-control-sm" placeholder="Filter by name or shift">
                    </div>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Plan Name</th>
                                <th>Shift</th>
                                <th>Classes</th>
                                <th>Exams</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($plans)): ?>
                            <tr><td colspan="8" class="text-center text-muted">No plans yet.</td></tr>
                        <?php else: $i=1; foreach($plans as $p): ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($p['plan_name']) ?></td>
                                <td><?= htmlspecialchars($p['shift']) ?></td>
                                <td><?= htmlspecialchars(get_plan_classes($conn, (int)$p['id'])) ?></td>
                                <td><?= htmlspecialchars(get_plan_exams($conn, (int)$p['id'])) ?></td>
                                <td>
                                    <form method="post" action="" class="d-inline">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                                        <select name="status" class="form-control form-control-sm" onchange="this.form.submit()">
                                            <?php $st = strtolower($p['status'] ?? 'active'); ?>
                                            <option value="active" <?= $st==='active'?'selected':'' ?>>Active</option>
                                            <option value="inactive" <?= $st==='inactive'?'selected':'' ?>>Inactive</option>
                                            <option value="completed" <?= $st==='completed'?'selected':'' ?>>Completed</option>
                                        </select>
                                    </form>
                                </td>
                                <td><?= htmlspecialchars($p['created_at']) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-primary" href="seat_plan_rooms.php?plan_id=<?= (int)$p['id'] ?>">View Rooms</a>
                                    <a class="btn btn-sm btn-info" href="seat_plan_edit.php?plan_id=<?= (int)$p['id'] ?>">Edit</a>
                                    <form method="post" action="" style="display:inline" onsubmit="return confirm('Delete this plan and all its rooms/allocations?');">
                                        <input type="hidden" name="action" value="delete_plan">
                                        <input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>">
                                        <button class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <script>
            (function(){
                var input = document.getElementById('plansFilter');
                if (!input) return;
                        var table = input.closest('.card').querySelector('table');
                var tbody = table ? table.querySelector('tbody') : null;
                function filter(){
                    var term = (input.value || '').toLowerCase().trim();
                    if (!tbody) return;
                    Array.prototype.forEach.call(tbody.rows, function(tr){
                        if (tr.querySelector('td[colspan]')) return; // skip empty row
                                var name = (tr.cells[1]?.innerText || '').toLowerCase();
                                var shift = (tr.cells[2]?.innerText || '').toLowerCase();
                                var statusText = (tr.cells[5]?.innerText || '').toLowerCase();
                                tr.style.display = (!term || name.indexOf(term)>-1 || shift.indexOf(term)>-1 || statusText.indexOf(term)>-1) ? '' : 'none';
                    });
                }
                input.addEventListener('input', filter);
            })();
            </script>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
