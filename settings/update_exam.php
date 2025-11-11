<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . BASE_URL . "auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';

$exam_id = isset($_GET['exam_id']) ? (int)$_GET['exam_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle update submission
    $exam_id = isset($_POST['exam_id']) ? (int)$_POST['exam_id'] : 0;
    $exam_name = trim($_POST['exam_name'] ?? '');
    $exam_type = trim($_POST['exam_type'] ?? '');
    $subjects_without_fourth = isset($_POST['subjects_without_fourth']) && $_POST['subjects_without_fourth'] !== ''
        ? (int)$_POST['subjects_without_fourth'] : null;

    if ($exam_id > 0) {
        // Ensure column exists for total_subjects_without_fourth
        $colExists = false;
        if ($res = $conn->query("SHOW COLUMNS FROM exams LIKE 'total_subjects_without_fourth'")) {
            $colExists = ($res->num_rows > 0);
        }
        if (!$colExists) {
            @ $conn->query("ALTER TABLE exams ADD COLUMN total_subjects_without_fourth INT NULL DEFAULT NULL");
        }
        // Update exams row
        $sql = "UPDATE exams SET exam_name = ?, exam_type = ?" .
               (is_null($subjects_without_fourth) ? ", total_subjects_without_fourth = NULL" : ", total_subjects_without_fourth = ?") .
               " WHERE id = ?";
        if (is_null($subjects_without_fourth)) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssi", $exam_name, $exam_type, $exam_id);
        } else {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssii", $exam_name, $exam_type, $subjects_without_fourth, $exam_id);
        }
        $stmt->execute();

        // Ensure pass_type column exists in exam_subjects
        $esPassTypeExists = false;
        if ($chk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'pass_type'")) {
            $esPassTypeExists = ($chk->num_rows > 0);
        }
        if (!$esPassTypeExists) {
            @ $conn->query("ALTER TABLE exam_subjects ADD COLUMN pass_type ENUM('total','individual') NOT NULL DEFAULT 'total'");
        }

        // Update each exam_subject row
        $ids = $_POST['exam_subject_id'] ?? [];
        $subject_ids = $_POST['subject_id'] ?? [];
        $exam_dates = $_POST['exam_date'] ?? [];
        $exam_times = $_POST['exam_time'] ?? [];
        $creative_marks = $_POST['creative_marks'] ?? [];
        $objective_marks = $_POST['objective_marks'] ?? [];
        $practical_marks = $_POST['practical_marks'] ?? [];
        $creative_pass = $_POST['creative_pass'] ?? [];
        $objective_pass = $_POST['objective_pass'] ?? [];
        $practical_pass = $_POST['practical_pass'] ?? [];
        $pass_types = $_POST['pass_type'] ?? [];

        $rowCount = min(
            count($ids), count($subject_ids), count($exam_dates), count($exam_times),
            count($creative_marks), count($objective_marks), count($practical_marks),
            count($creative_pass), count($objective_pass), count($practical_pass)
        );

        $upd = $conn->prepare("UPDATE exam_subjects 
            SET subject_id = ?,
                exam_date = ?, exam_time = ?,
                creative_marks = ?, objective_marks = ?, practical_marks = ?,
                creative_pass = ?, objective_pass = ?, practical_pass = ?,
                pass_type = ?,
                total_marks = (? + ? + ?)
            WHERE id = ? AND exam_id = ?");

        for ($i = 0; $i < $rowCount; $i++) {
            $id = (int)$ids[$i];
            $sid = (int)$subject_ids[$i];
            $c = (int)$creative_marks[$i];
            $o = (int)$objective_marks[$i];
            $p = (int)$practical_marks[$i];
            $cp = (int)$creative_pass[$i];
            $op = (int)$objective_pass[$i];
            $pp = (int)$practical_pass[$i];
            $ed = $exam_dates[$i];
            $et = $exam_times[$i];
            $pt = isset($pass_types[$i]) && $pass_types[$i] !== '' ? $pass_types[$i] : 'total';
            $typestr = 'iss' . str_repeat('i', 6) . 's' . str_repeat('i', 5); // 15 params
            $upd->bind_param($typestr, $sid, $ed, $et, $c, $o, $p, $cp, $op, $pp, $pt, $c, $o, $p, $id, $exam_id);
            $upd->execute();
        }
        $_SESSION['success'] = 'পরীক্ষার তথ্য সফলভাবে আপডেট হয়েছে';
    header("Location: " . BASE_URL . "settings/manage_exams.php");
        exit();
    }
}

if ($exam_id <= 0) {
    echo '<div class="alert alert-danger">অবৈধ পরীক্ষা আইডি।</div>';
    exit;
}

// Load exam info
$examInfoRes = $conn->query("SELECT e.*, c.class_name FROM exams e JOIN classes c ON e.class_id = c.id WHERE e.id = " . (int)$exam_id);
$exam = $examInfoRes ? $examInfoRes->fetch_assoc() : null;
if (!$exam) {
    echo '<div class="alert alert-danger">পরীক্ষার তথ্য পাওয়া যায়নি।</div>';
    exit;
}

// Load subjects for this exam
$subPassTypeSelect = "NULL AS subject_pass_type";
$chkPT = $conn->query("SHOW COLUMNS FROM subjects LIKE 'pass_type'");
if ($chkPT && $chkPT->num_rows > 0) { $subPassTypeSelect = "s.pass_type AS subject_pass_type"; }
$sql = "SELECT es.id AS exam_subject_id, es.subject_id, s.subject_name, s.subject_code, es.exam_date, es.exam_time, 
         es.creative_marks, es.objective_marks, es.practical_marks,
         es.creative_pass, es.objective_pass, es.practical_pass,
         es.pass_type,
         s.has_creative, s.has_objective, s.has_practical,
         $subPassTypeSelect
     FROM exam_subjects es 
     JOIN subjects s ON es.subject_id = s.id
     WHERE es.exam_id = ?
     ORDER BY s.subject_code ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$rows = $stmt->get_result();

// Load all subjects for this class to populate dropdowns
$classSubjects = [];
$subPT = "NULL AS subject_pass_type";
$chkPT2 = $conn->query("SHOW COLUMNS FROM subjects LIKE 'pass_type'");
if ($chkPT2 && $chkPT2->num_rows > 0) { $subPT = "s.pass_type AS subject_pass_type"; }
$subStmt = $conn->prepare("SELECT DISTINCT s.id, s.subject_name, s.subject_code, s.has_creative, s.has_objective, s.has_practical, $subPT
                           FROM subject_group_map gm
                           JOIN subjects s ON gm.subject_id = s.id
                           WHERE gm.class_id = ? AND s.status = 'Active'
                           ORDER BY s.subject_code ASC");
$subStmt->bind_param("i", $exam['class_id']);
$subStmt->execute();
$subRes = $subStmt->get_result();
$srow_map = [];
while ($srow = $subRes->fetch_assoc()) {
    $classSubjects[(int)$srow['id']] = $srow['subject_name'] . ' (' . $srow['subject_code'] . ')';
    $srow_map[(int)$srow['id']] = [
        'has_creative' => isset($srow['has_creative']) ? (int)$srow['has_creative'] : 1,
        'has_objective' => isset($srow['has_objective']) ? (int)$srow['has_objective'] : 1,
        'has_practical' => isset($srow['has_practical']) ? (int)$srow['has_practical'] : 1,
        'subject_pass_type' => isset($srow['subject_pass_type']) ? (string)$srow['subject_pass_type'] : 'total',
    ];
}
?>
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="bn">পরীক্ষা আপডেট: <?= htmlspecialchars($exam['exam_name']) ?> (<?= htmlspecialchars($exam['class_name']) ?>)</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>settings/manage_exams.php">Manage Exams</a></li>
                        <li class="breadcrumb-item active">Update</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="card">
                <div class="card-body">
        <h4 class="mb-3">পরীক্ষা আপডেট ফর্ম</h4>
    <?php if (isset($_SESSION['error'])) { echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['error']).'</div>'; unset($_SESSION['error']); } ?>

    <form method="POST" action="update_exam.php">
        <input type="hidden" name="exam_id" value="<?= (int)$exam_id ?>">
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">পরীক্ষার নাম</label>
                <input type="text" name="exam_name" class="form-control" value="<?= htmlspecialchars($exam['exam_name']) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">শ্রেণি</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($exam['class_name']) ?>" disabled>
            </div>
            <div class="col-md-4">
                <label class="form-label">পরীক্ষার ধরন</label>
                <select name="exam_type" class="form-control" required>
                    <?php
                    $types = ['Half Yearly' => 'অর্ধবার্ষিক', 'Final' => 'বার্ষিক', 'Monthly' => 'মাসিক'];
                    foreach ($types as $val => $label) {
                        $sel = ($exam['exam_type'] === $val) ? 'selected' : '';
                        echo '<option value="'.htmlspecialchars($val).'" '.$sel.'>'.htmlspecialchars($label).'</option>';
                    }
                    ?>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">মোট বিষয় সংখ্যা (চতুর্থ ছাড়া)</label>
            <input type="number" name="subjects_without_fourth" class="form-control" min="1" value="<?= htmlspecialchars($exam['total_subjects_without_fourth'] ?? '') ?>" placeholder="উদাহরণ: 6">
            <div class="form-text">GPA বিভাজকের জন্য ব্যবহৃত হবে (ঐচ্ছিক/চতুর্থ বিষয় বাদে)।</div>
        </div>

        <h5 class="mt-3">বিষয়ভিত্তিক নম্বর/তারিখ হালনাগাদ</h5>
        <table class="table table-bordered">
            <thead class="table-light">
                <tr>
                    <th>বিষয়</th>
                    <th>তারিখ</th>
                    <th>সময়</th>
                    <th>সৃজনশীল</th>
                    <th>নৈর্ব্যক্তিক</th>
                    <th>ব্যবহারিক</th>
                    <th>মোট</th>
                    <th>সৃজনশীল পাশ</th>
                    <th>নৈর্ব্যক্তিক পাশ</th>
                    <th>ব্যবহারিক পাশ</th>
                    <th>পাস টাইপ</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($r = $rows->fetch_assoc()): ?>
                <?php
                    $hasC = !empty($r['has_creative']);
                    $hasO = !empty($r['has_objective']);
                    $hasP = !empty($r['has_practical']);
                    $rowPassType = (isset($r['pass_type']) && trim((string)$r['pass_type']) !== '') ? $r['pass_type'] : ((isset($r['subject_pass_type']) && trim((string)$r['subject_pass_type']) !== '') ? $r['subject_pass_type'] : 'total');
                ?>
                <tr>
                    <td>
                        <select name="subject_id[]" class="form-control subj-select" required>
                            <?php foreach ($classSubjects as $sid => $label): ?>
                                <?php
                                // Attempt to fetch properties for option
                                // We already built $classSubjects label only; reload details for data attrs
                                // For performance, we could pre-store, but keep it simple here
                                ?>
                                <option value="<?= (int)$sid ?>" 
                                    data-hc="<?= isset($srow_map[$sid]['has_creative']) ? (int)$srow_map[$sid]['has_creative'] : 1 ?>"
                                    data-ho="<?= isset($srow_map[$sid]['has_objective']) ? (int)$srow_map[$sid]['has_objective'] : 1 ?>"
                                    data-hp="<?= isset($srow_map[$sid]['has_practical']) ? (int)$srow_map[$sid]['has_practical'] : 1 ?>"
                                    data-pt="<?= isset($srow_map[$sid]['subject_pass_type']) && $srow_map[$sid]['subject_pass_type'] !== '' ? htmlspecialchars($srow_map[$sid]['subject_pass_type']) : 'total' ?>"
                                    <?= ((int)$r['subject_id'] === (int)$sid) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="exam_subject_id[]" value="<?= (int)$r['exam_subject_id'] ?>">
                    </td>
                    <td><input type="text" name="exam_date[]" class="form-control date-input" placeholder="dd/mm/yyyy" value="<?= htmlspecialchars($r['exam_date']) ?>"></td>
                    <td><input type="time" name="exam_time[]" class="form-control" value="<?= htmlspecialchars($r['exam_time']) ?>"></td>
                    <td>
                        <input type="number" name="creative_marks[]" class="form-control cm" min="0" value="<?= (int)$r['creative_marks'] ?>" <?= $hasC ? '' : 'disabled' ?> required>
                        <?= $hasC ? '' : '<input type="hidden" name="creative_marks[]" value="0">' ?>
                    </td>
                    <td>
                        <input type="number" name="objective_marks[]" class="form-control om" min="0" value="<?= (int)$r['objective_marks'] ?>" <?= $hasO ? '' : 'disabled' ?> required>
                        <?= $hasO ? '' : '<input type="hidden" name="objective_marks[]" value="0">' ?>
                    </td>
                    <td>
                        <input type="number" name="practical_marks[]" class="form-control pm" min="0" value="<?= (int)$r['practical_marks'] ?>" <?= $hasP ? '' : 'disabled' ?> required>
                        <?= $hasP ? '' : '<input type="hidden" name="practical_marks[]" value="0">' ?>
                    </td>
                    <td><input type="number" class="form-control total" value="<?= (int)$r['creative_marks'] + (int)$r['objective_marks'] + (int)$r['practical_marks'] ?>" readonly></td>
                    <td>
                        <input type="number" name="creative_pass[]" class="form-control cpass" min="0" value="<?= (int)$r['creative_pass'] ?>" <?= $hasC ? '' : 'disabled' ?> required>
                        <?= $hasC ? '' : '<input type="hidden" name="creative_pass[]" value="0">' ?>
                    </td>
                    <td>
                        <input type="number" name="objective_pass[]" class="form-control opass" min="0" value="<?= (int)$r['objective_pass'] ?>" <?= $hasO ? '' : 'disabled' ?> required>
                        <?= $hasO ? '' : '<input type="hidden" name="objective_pass[]" value="0">' ?>
                    </td>
                    <td>
                        <input type="number" name="practical_pass[]" class="form-control ppass" min="0" value="<?= (int)$r['practical_pass'] ?>" <?= $hasP ? '' : 'disabled' ?> required>
                        <?= $hasP ? '' : '<input type="hidden" name="practical_pass[]" value="0">' ?>
                    </td>
                    <td>
                        <select name="pass_type[]" class="form-control pt">
                            <option value="total" <?= ($rowPassType === 'total') ? 'selected' : '' ?>>মোট নাম্বার</option>
                            <option value="individual" <?= ($rowPassType === 'individual') ? 'selected' : '' ?>>আলাদা আলাদা</option>
                        </select>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

                <button type="submit" class="btn btn-primary">আপডেট সংরক্ষণ</button>
                <a href="manage_exams.php" class="btn btn-secondary">বাতিল</a>
        </form>
                </div><!-- /.card-body -->
            </div><!-- /.card -->
        </div><!-- /.container-fluid -->
    </section>
</div><!-- /.content-wrapper -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    function recalc(row) {
        const c = parseInt(row.querySelector('.cm').value) || 0;
        const o = parseInt(row.querySelector('.om').value) || 0;
        const p = parseInt(row.querySelector('.pm').value) || 0;
        row.querySelector('.total').value = c + o + p;
    }
    document.querySelectorAll('tbody tr').forEach(row => {
        row.querySelectorAll('.cm,.om,.pm').forEach(inp => {
            inp.addEventListener('input', () => recalc(row));
        });
        // If subject is changed, toggle component inputs based on option data
        const subjSel = row.querySelector('.subj-select');
        if (subjSel) {
            subjSel.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                const hasC = Number(opt.dataset.hc || 1) === 1;
                const hasO = Number(opt.dataset.ho || 1) === 1;
                const hasP = Number(opt.dataset.hp || 1) === 1;
                const defPT = (opt.dataset.pt && opt.dataset.pt.trim() !== '') ? opt.dataset.pt : 'total';

                const cm = row.querySelector('.cm');
                const om = row.querySelector('.om');
                const pm = row.querySelector('.pm');
                const cpass = row.querySelector('.cpass');
                const opass = row.querySelector('.opass');
                const ppass = row.querySelector('.ppass');
                const ptSel = row.querySelector('.pt');

                function toggle(el, enable) { if (!el) return; el.disabled = !enable; if (!enable) el.value = 0; }
                toggle(cm, hasC); toggle(cpass, hasC);
                toggle(om, hasO); toggle(opass, hasO);
                toggle(pm, hasP); toggle(ppass, hasP);
                if (ptSel) { ptSel.value = defPT; }
                recalc(row);
            });
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
