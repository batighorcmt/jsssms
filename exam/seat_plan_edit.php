<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
include '../config/db.php';

// Ensure mapping table exists (in case user opens edit directly)
$conn->query("CREATE TABLE IF NOT EXISTS seat_plan_exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    plan_id INT NOT NULL,
    exam_id INT NOT NULL,
    UNIQUE KEY uniq_plan_exam (plan_id, exam_id),
    INDEX idx_plan (plan_id)
)");

$plan_id = (int)($_GET['plan_id'] ?? 0);
if ($plan_id <= 0) { header('Location: seat_plan.php'); exit(); }

// Load plan
$plan = null;
$rp = $conn->query('SELECT * FROM seat_plans WHERE id='.$plan_id.' LIMIT 1');
if ($rp && $rp->num_rows>0) { $plan = $rp->fetch_assoc(); }
if (!$plan) { header('Location: seat_plan.php'); exit(); }

// Load classes
$classes = [];
$rc = $conn->query('SELECT id, class_name FROM classes ORDER BY id ASC');
if ($rc) { while($r=$rc->fetch_assoc()){ $classes[]=$r; } }

// Load selected class ids
$selected = [];
$rs = $conn->query('SELECT class_id FROM seat_plan_classes WHERE plan_id='.$plan_id);
if ($rs) { while($r=$rs->fetch_assoc()){ $selected[] = (int)$r['class_id']; } }

// Load exams list and selected exam ids
$exams = [];
if ($re = $conn->query('SELECT e.id, e.exam_name, c.class_name FROM exams e LEFT JOIN classes c ON c.id=e.class_id ORDER BY e.id DESC')){
    while($r=$re->fetch_assoc()){ $exams[]=$r; }
}
$selectedExams = [];
if ($rse = $conn->query('SELECT exam_id FROM seat_plan_exams WHERE plan_id='.$plan_id)){
    while($r=$rse->fetch_assoc()){ $selectedExams[] = (int)$r['exam_id']; }
}

$toast = null;
if ($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    if ($action==='auto_map_exams'){
        // Auto-map exams by classes included in this plan
        $classIds = [];
        if ($rs = $conn->query('SELECT class_id FROM seat_plan_classes WHERE plan_id='.$plan_id)){
            while($r=$rs->fetch_assoc()){ $classIds[] = (int)$r['class_id']; }
        }
        if (empty($classIds)){
            $toast = ['type'=>'danger','msg'=>'Add classes to the plan first to auto-map exams.'];
        } else {
            $in = implode(',', array_map('intval', $classIds));
            $examIds = [];
            if ($re = $conn->query('SELECT id FROM exams WHERE class_id IN ('.$in.')')){
                while($row=$re->fetch_assoc()){ $examIds[] = (int)$row['id']; }
            }
            if (empty($examIds)){
                $toast = ['type'=>'danger','msg'=>'No exams found for the selected classes.'];
            } else {
                $ei = $conn->prepare('INSERT INTO seat_plan_exams (plan_id, exam_id) VALUES (?,?) ON DUPLICATE KEY UPDATE exam_id=VALUES(exam_id)');
                foreach ($examIds as $eid){ $e=(int)$eid; if ($e>0){ $ei->bind_param('ii', $plan_id, $e); @$ei->execute(); } }
                // Reload selected exams
                $selectedExams = [];
                if ($rse2 = $conn->query('SELECT exam_id FROM seat_plan_exams WHERE plan_id='.$plan_id)){
                    while($r=$rse2->fetch_assoc()){ $selectedExams[] = (int)$r['exam_id']; }
                }
                $toast = ['type'=>'success','msg'=>'Exams auto-mapped by classes.'];
            }
        }
    }
    if ($action==='update_plan'){
        $plan_name = trim($_POST['plan_name'] ?? '');
        $shift = trim($_POST['shift'] ?? 'Morning');
        $classesSel = $_POST['classes'] ?? [];
        $examsSel = $_POST['exams'] ?? [];
        if ($plan_name===''){ $toast = ['type'=>'danger','msg'=>'Plan name is required']; }
        else {
            $upd = $conn->prepare('UPDATE seat_plans SET plan_name=?, shift=? WHERE id=?');
            $upd->bind_param('ssi', $plan_name, $shift, $plan_id);
            if ($upd->execute()){
                // update classes: replace
                $conn->query('DELETE FROM seat_plan_classes WHERE plan_id='.$plan_id);
                if (!empty($classesSel)){
                    $ci = $conn->prepare('INSERT INTO seat_plan_classes (plan_id, class_id) VALUES (?,?)');
                    foreach ($classesSel as $cid){ $c=(int)$cid; if ($c>0){ $ci->bind_param('ii', $plan_id, $c); @$ci->execute(); } }
                }
                // update exams mapping: replace
                $conn->query('DELETE FROM seat_plan_exams WHERE plan_id='.$plan_id);
                if (!empty($examsSel)){
                    $ei = $conn->prepare('INSERT INTO seat_plan_exams (plan_id, exam_id) VALUES (?,?)');
                    foreach ($examsSel as $eid){ $e=(int)$eid; if ($e>0){ $ei->bind_param('ii', $plan_id, $e); @$ei->execute(); } }
                }
                // reload selected
                $selected = [];
                $rs2 = $conn->query('SELECT class_id FROM seat_plan_classes WHERE plan_id='.$plan_id);
                if ($rs2) { while($r=$rs2->fetch_assoc()){ $selected[] = (int)$r['class_id']; } }
                $selectedExams = [];
                if ($rse2 = $conn->query('SELECT exam_id FROM seat_plan_exams WHERE plan_id='.$plan_id)){
                    while($r=$rse2->fetch_assoc()){ $selectedExams[] = (int)$r['exam_id']; }
                }
                // refresh plan
                $rp2 = $conn->query('SELECT * FROM seat_plans WHERE id='.$plan_id.' LIMIT 1');
                if ($rp2 && $rp2->num_rows>0) { $plan = $rp2->fetch_assoc(); }
                $toast = ['type'=>'success','msg'=>'Plan updated'];
            } else {
                $toast = ['type'=>'danger','msg'=>'Update failed'];
            }
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
                <div class="col-sm-6"><h4>Edit Seat Plan</h4></div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="seat_plan.php">Seat Plan</a></li>
                        <li class="breadcrumb-item active">Edit</li>
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

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Update Plan</strong>
                    <form method="post" class="m-0 p-0">
                        <input type="hidden" name="action" value="auto_map_exams">
                        <button class="btn btn-sm btn-outline-primary" type="submit" title="Map all exams of the selected classes to this plan">Auto Map Exams by Classes</button>
                    </form>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="update_plan">
                        <div class="form-row">
                            <div class="form-group col-md-4">
                                <label>Plan Name</label>
                                <input type="text" name="plan_name" class="form-control" value="<?= htmlspecialchars($plan['plan_name']) ?>" required>
                            </div>
                            <div class="form-group col-md-2">
                                <label>Shift</label>
                                <select name="shift" class="form-control" required>
                                    <option value="Morning" <?= ($plan['shift']==='Morning'?'selected':'') ?>>Morning</option>
                                    <option value="Afternoon" <?= ($plan['shift']==='Afternoon'?'selected':'') ?>>Afternoon</option>
                                </select>
                            </div>
                            <div class="form-group col-md-6">
                                <label>Classes (multi-select)</label>
                                <select name="classes[]" class="form-control" multiple size="6">
                                    <?php foreach($classes as $c): $cid=(int)$c['id']; ?>
                                        <option value="<?= $cid ?>" <?= in_array($cid, $selected)?'selected':''; ?>><?= htmlspecialchars($c['class_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Hold Ctrl to select multiple</small>
                            </div>
                            <div class="form-group col-md-12 mt-2">
                                <label>Exams included in this plan (optional, multi-select)</label>
                                <select name="exams[]" class="form-control" multiple size="6">
                                    <?php foreach($exams as $e): $eid=(int)($e['id'] ?? 0); ?>
                                        <option value="<?= $eid ?>" <?= in_array($eid, $selectedExams)?'selected':''; ?>><?= htmlspecialchars(($e['exam_name'] ?? 'Exam').' — '.($e['class_name'] ?? '')) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">If selected, date filters and related views will only use these exams' dates.</small>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between">
                            <a class="btn btn-outline-secondary" href="seat_plan.php">Back</a>
                            <button class="btn btn-success">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
