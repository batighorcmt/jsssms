<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';

// Ensure pass mark & teacher columns exist (runtime migration safety)
function ensureColumn($conn,$name,$ddl){
    if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE '".$conn->real_escape_string($name)."'")) {
        if ($chk->num_rows === 0) { @$conn->query($ddl); }
    }
}
ensureColumn($conn,'teacher_id','ALTER TABLE exam_subjects ADD COLUMN teacher_id INT NULL AFTER subject_id');
ensureColumn($conn,'creative_pass','ALTER TABLE exam_subjects ADD COLUMN creative_pass INT NOT NULL DEFAULT 0 AFTER creative_marks');
ensureColumn($conn,'objective_pass','ALTER TABLE exam_subjects ADD COLUMN objective_pass INT NOT NULL DEFAULT 0 AFTER objective_marks');
ensureColumn($conn,'practical_pass','ALTER TABLE exam_subjects ADD COLUMN practical_pass INT NOT NULL DEFAULT 0 AFTER practical_marks');
ensureColumn($conn,'total_pass','ALTER TABLE exam_subjects ADD COLUMN total_pass INT NOT NULL DEFAULT 0 AFTER total_marks');
ensureColumn($conn,'mark_entry_deadline','ALTER TABLE exam_subjects ADD COLUMN mark_entry_deadline DATE NULL AFTER exam_time');

$id = $_GET['id'] ?? null;

if (!$id) {
    $_SESSION['error'] = "Invalid Exam Subject ID!";
    header("Location: manage_exams.php");
    exit();
}

// Get existing exam_subject data (including subject component flags)
$sql = "SELECT es.*, e.exam_name, e.class_id, c.class_name, s.subject_name, s.has_creative, s.has_objective, s.has_practical, t.name AS teacher_name 
    FROM exam_subjects es 
    JOIN exams e ON es.exam_id = e.id
    JOIN classes c ON e.class_id = c.id
    JOIN subjects s ON es.subject_id = s.id
    LEFT JOIN teachers t ON es.teacher_id = t.id
    WHERE es.id = $id";
$result = $conn->query($sql);
if (!$result || $result->num_rows === 0) {
    $_SESSION['error'] = "Exam not found!";
    header("Location: manage_exams.php");
    exit();
}
$exam = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_date = trim($_POST['exam_date'] ?? '');
    if ($exam_date && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$exam_date,$dmy)) {
        $exam_date = $dmy[3].'-'.$dmy[2].'-'.$dmy[1];
    }
    $exam_time = trim($_POST['exam_time'] ?? '');
    // Normalize time HH:MM -> HH:MM:SS if needed
    if ($exam_time && preg_match('/^(\d{2}):(\d{2})$/',$exam_time)) {
        $exam_time .= ':00';
    }
    $mark_entry_deadline = $_POST['mark_entry_deadline'] ?? '';
    if ($mark_entry_deadline && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/',$mark_entry_deadline,$dm)) {
        $mark_entry_deadline = $dm[3].'-'.$dm[2].'-'.$dm[1];
    }
    $creative_marks = (int)($_POST['creative_marks'] ?? 0);
    $objective_marks = (int)($_POST['objective_marks'] ?? 0);
    $practical_marks = (int)($_POST['practical_marks'] ?? 0);
    $creative_pass = (int)($_POST['creative_pass'] ?? 0);
    $objective_pass = (int)($_POST['objective_pass'] ?? 0);
    $practical_pass = (int)($_POST['practical_pass'] ?? 0);
    $pass_type = $conn->real_escape_string($_POST['pass_type'] ?? 'total');
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $total_marks = $creative_marks + $objective_marks + $practical_marks;
    $total_pass = (int)($_POST['total_pass'] ?? ($pass_type==='total' ? ceil($total_marks * 0.33) : 0));

    $update = $conn->prepare("UPDATE exam_subjects SET exam_date=?, exam_time=?, mark_entry_deadline=?, creative_marks=?, objective_marks=?, practical_marks=?, creative_pass=?, objective_pass=?, practical_pass=?, pass_type=?, teacher_id=?, total_marks=?, total_pass=? WHERE id=?");
    // Corrected type string (14 placeholders)
    $update->bind_param("sssiiiiiisiiii", $exam_date, $exam_time, $mark_entry_deadline, $creative_marks, $objective_marks, $practical_marks, $creative_pass, $objective_pass, $practical_pass, $pass_type, $teacher_id, $total_marks, $total_pass, $id);
    if ($update->execute()) {
        // Redirect to exam details with toast
        $eid = intval($exam['exam_id'] ?? 0);
        header("Location: exam_details.php?exam_id=".$eid."&status=success&msg=updated");
        exit();
    } else {
        $error = "Update failed (database error).";
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>
<div class="content-wrapper">
<section class="content-header">
    <div class="container-fluid">
        <div class="row mb-2">
            <div class="col-sm-6"><h4>Edit Exam - <?= htmlspecialchars($exam['exam_name']) ?> (<?= htmlspecialchars($exam['class_name']) ?>)</h4></div>
            <div class="col-sm-6">
                <ol class="breadcrumb float-sm-right">
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                    <li class="breadcrumb-item"><a href="<?= BASE_URL ?>settings/manage_exams.php">Manage Exams</a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </div>
        </div>
    </div>
</section>
<section class="content"><div class="container-fluid">
        <div class="card">
            <div class="card-body">
    <h5 class="mb-3">Subject: <?= htmlspecialchars($exam['subject_name']) ?> (ID: <?= (int)$exam['subject_id'] ?>)</h5>

    <?php if (isset($error)): ?>
    <div class="d-none" id="serverMessage" data-type="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php
      // Fetch teachers for dropdown
      $teacherList = [];
      if ($tres = $conn->query("SELECT id,name FROM teachers ORDER BY name ASC")) { while($t=$tres->fetch_assoc()){ $teacherList[]=$t; } }
    ?>
    <form method="POST">
        <div class="table-responsive">
            <table class="table table-sm table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Exam Date</th>
                        <th>Mark Entry Deadline</th>
                        <th>Exam Time</th>
                        <th>Creative</th>
                        <th>Objective</th>
                        <th>Practical</th>
                        <th>Total</th>
                        <th>Creative Pass</th>
                        <th>Objective Pass</th>
                        <th>Practical Pass</th>
                        <th>Pass Type</th>
                        <th>Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="exam_date" class="form-control date-input" placeholder="dd/mm/yyyy" value="<?= htmlspecialchars($exam['exam_date']) ?>" required></td>
                        <?php
                            $deadlineDisplay = '';
                            if (!empty($exam['mark_entry_deadline']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$exam['mark_entry_deadline'],$m)) {
                                $deadlineDisplay = $m[3].'/'.$m[2].'/'.$m[1];
                            }
                        ?>
                        <td><input type="text" name="mark_entry_deadline" class="form-control date-input" placeholder="dd/mm/yyyy" value="<?= htmlspecialchars($deadlineDisplay) ?>"></td>
                        <?php
                            // Normalize exam_time to HH:MM for input if stored HH:MM:SS
                            $timeDisplay = '';
                            if (!empty($exam['exam_time']) && preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/',$exam['exam_time'],$mt)) {
                                $timeDisplay = $mt[1].':'.$mt[2];
                            }
                        ?>
                        <td><input type="time" name="exam_time" class="form-control" value="<?= htmlspecialchars($timeDisplay) ?>" required></td>
                        <?php $hasC = !empty($exam['has_creative']); $hasO = !empty($exam['has_objective']); $hasP = !empty($exam['has_practical']); ?>
                        <td>
                            <input type="number" name="creative_marks" class="form-control mark-c" min="0" value="<?= (int)$exam['creative_marks'] ?>" <?= $hasC ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <input type="number" name="objective_marks" class="form-control mark-o" min="0" value="<?= (int)$exam['objective_marks'] ?>" <?= $hasO ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <input type="number" name="practical_marks" class="form-control mark-p" min="0" value="<?= (int)$exam['practical_marks'] ?>" <?= $hasP ? '' : 'disabled' ?>>
                        </td>
                        <td><input type="number" class="form-control total-marks" value="<?= (int)$exam['creative_marks'] + (int)$exam['objective_marks'] + (int)$exam['practical_marks'] ?>" readonly></td>
                        <td>
                            <input type="number" name="creative_pass" class="form-control pass-c" min="0" value="<?= isset($exam['creative_pass']) ? (int)$exam['creative_pass'] : 0 ?>" <?= $hasC ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <input type="number" name="objective_pass" class="form-control pass-o" min="0" value="<?= isset($exam['objective_pass']) ? (int)$exam['objective_pass'] : 0 ?>" <?= $hasO ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <input type="number" name="practical_pass" class="form-control pass-p" min="0" value="<?= isset($exam['practical_pass']) ? (int)$exam['practical_pass'] : 0 ?>" <?= $hasP ? '' : 'disabled' ?>>
                        </td>
                        <td>
                            <?php $pt = $exam['pass_type'] ?? 'total'; ?>
                            <select name="pass_type" class="form-control">
                                <option value="total" <?= ($pt==='total')?'selected':''; ?>>Total Marks</option>
                                <option value="individual" <?= ($pt==='individual')?'selected':''; ?>>Per Component</option>
                            </select>
                        </td>
                        <td>
                            <select name="teacher_id" class="form-control">
                                <option value="">-- Teacher --</option>
                                <?php foreach($teacherList as $t): ?>
                                  <option value="<?= $t['id'] ?>" <?= ((int)$exam['teacher_id']===(int)$t['id'])?'selected':''; ?>><?= htmlspecialchars($t['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="mt-2">
            <button type="submit" class="btn btn-success">Update</button>
            <a href="manage_exams.php" class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
      </div></div>
    </div></section></div>
<?php include '../includes/footer.php'; ?>
<script>
 (function(){
     var sm = document.getElementById('serverMessage');
     if (sm && window.showToast) window.showToast('Error', sm.innerHTML, 'error');
     function recalc(){
         var c = parseInt(document.querySelector('.mark-c')?.value || '0')||0;
         var o = parseInt(document.querySelector('.mark-o')?.value || '0')||0;
         var p = parseInt(document.querySelector('.mark-p')?.value || '0')||0;
         var t = document.querySelector('.total-marks'); if (t) t.value = c+o+p;
     }
     ['.mark-c','.mark-o','.mark-p'].forEach(sel=>{
         var el = document.querySelector(sel); if (el) el.addEventListener('input', recalc);
     });
     recalc();
 })();
</script>
