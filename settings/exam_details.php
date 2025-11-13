<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: " . BASE_URL . "auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';

$exam_id = intval($_GET['exam_id'] ?? 0);
if ($exam_id <= 0) {
    echo '<div class="alert alert-danger">Invalid exam ID.</div>';
    exit;
}

// Exam name and class
$exam_info = $conn->query("SELECT exams.exam_name, classes.class_name 
                          FROM exams 
                          JOIN classes ON exams.class_id = classes.id 
                          WHERE exams.id = $exam_id")->fetch_assoc();

if (!$exam_info) {
    echo '<div class="alert alert-danger">Exam not found.</div>';
    exit;
}
?>
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h5>Exam Details: <?= htmlspecialchars($exam_info['exam_name']) ?> (<?= htmlspecialchars($exam_info['class_name']) ?>)</h5>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>settings/manage_exams.php">Manage Exams</a></li>
                        <li class="breadcrumb-item active">Details</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="mb-3 d-print-none">
                <a href="<?= BASE_URL ?>settings/manage_exams.php" class="btn btn-secondary btn-sm">← Manage Exams</a>
                <a href="<?= BASE_URL ?>settings/create_exam.php" class="btn btn-primary btn-sm">+ Create New Exam</a>
                <button onclick="window.print()" type="button" class="btn btn-outline-dark btn-sm">Print</button>
                <button onclick="printLandscape()" type="button" class="btn btn-outline-dark btn-sm">Print (Landscape)</button>
            </div>

            <!-- Print Header -->
            <div class="print-header d-none d-print-block" style="margin-bottom:10px;">
                <?php
                    $name = isset($institute_name) ? $institute_name : 'Institution Name';
                    $addr = isset($institute_address) ? $institute_address : '';
                    $phone = isset($institute_phone) ? $institute_phone : '';
                    $logo = isset($institute_logo) ? $institute_logo : 'assets/img/logo.png';
                    $logoSrc = (preg_match('~^https?://~',$logo)) ? $logo : (BASE_URL . ltrim($logo,'/'));
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
                        <div class="ph-title">Exam Details</div>
                        <div class="ph-sub">Exam: <?= htmlspecialchars($exam_info['exam_name']) ?> | Class: <?= htmlspecialchars($exam_info['class_name']) ?> | Printed: <?= date('d/m/Y') ?></div>
                    </div>
                    <div class="ph-right"></div>
                </div>
                <hr style="margin:4px 0;" />
            </div>

            <table class="table table-bordered table-hover exam-details-table">
        <thead class="table-light">
                        <?php
                            // Detect pass and deadline columns
                            $hasCpass = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'creative_pass'")->num_rows > 0;
                            $hasOpass = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'objective_pass'")->num_rows > 0;
                            $hasPpass = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'practical_pass'")->num_rows > 0;
                            $hasDeadline = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'mark_entry_deadline'")->num_rows > 0;
                        ?>
            <tr>
                                <th rowspan="2">#</th>
                                <th rowspan="2">Subject</th>
                                <th rowspan="2">Date</th>
                                <th rowspan="2">Time</th>
                                <?php if ($hasDeadline): ?><th rowspan="2">Mark Entry Deadline</th><?php endif; ?>
                                <th colspan="<?= $hasCpass?2:1 ?>">Creative</th>
                                <th colspan="<?= $hasOpass?2:1 ?>">Objective</th>
                                <th colspan="<?= $hasPpass?2:1 ?>">Practical</th>
                                <th rowspan="2">Total</th>
                                <th rowspan="2">Teacher</th>
                                <th rowspan="2" class="d-print-none">Action</th>
                                <th rowspan="2">Signature</th>
            </tr>
            <tr>
                            <?php if($hasCpass): ?><th>Exam</th><th>Pass</th><?php else: ?><th>Exam</th><?php endif; ?>
                            <?php if($hasOpass): ?><th>Exam</th><th>Pass</th><?php else: ?><th>Exam</th><?php endif; ?>
                            <?php if($hasPpass): ?><th>Exam</th><th>Pass</th><?php else: ?><th>Exam</th><?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php
         $hasTeacher = false;
         if ($__c = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'teacher_id'")) { $hasTeacher = ($__c->num_rows > 0); }
         $passCols = '';
         if ($hasCpass) $passCols .= ', es.creative_pass';
         if ($hasOpass) $passCols .= ', es.objective_pass';
         if ($hasPpass) $passCols .= ', es.practical_pass';
         $deadlineCol = $hasDeadline ? ', es.mark_entry_deadline' : '';
         if ($hasTeacher) {
          $sql = "SELECT es.id AS exam_subject_id, s.subject_name, es.exam_date, es.exam_time$deadlineCol, 
                   es.creative_marks, es.objective_marks, es.practical_marks, es.total_marks$passCols,
                   es.teacher_id, t.name AS teacher_name
               FROM exam_subjects es 
               JOIN subjects s ON es.subject_id = s.id
               LEFT JOIN teachers t ON es.teacher_id = t.id
               WHERE es.exam_id = $exam_id
               ORDER BY es.exam_date ASC";
         } else {
          $sql = "SELECT es.id AS exam_subject_id, s.subject_name, es.exam_date, es.exam_time$deadlineCol, 
                   es.creative_marks, es.objective_marks, es.practical_marks, es.total_marks$passCols
               FROM exam_subjects es 
               JOIN subjects s ON es.subject_id = s.id
               WHERE es.exam_id = $exam_id
               ORDER BY es.exam_date ASC";
         }
            $result = $conn->query($sql);
            $i = 1;
            while ($row = $result->fetch_assoc()):
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['subject_name']) ?></td>
                <td>
                    <?php
                        $ed = $row['exam_date'] ?? '';
                        if ($ed && $ed !== '0000-00-00') {
                            echo date('d/m/Y', strtotime($ed));
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                    ?>
                </td>
                <td>
                    <?php
                        $et = $row['exam_time'] ?? '';
                        if ($et && $et !== '00:00:00') {
                            echo date('h:i A', strtotime($et));
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                    ?>
                </td>
                <?php if ($hasDeadline): ?>
                <td>
                    <?php
                        if (!empty($row['mark_entry_deadline']) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$row['mark_entry_deadline'], $dm)) {
                            echo htmlspecialchars($dm[3].'/'.$dm[2].'/'.$dm[1]);
                        } elseif (!empty($row['mark_entry_deadline'])) {
                            echo htmlspecialchars($row['mark_entry_deadline']); // if stored in d/m/Y already
                        } else {
                            echo '<span class="text-muted">—</span>';
                        }
                    ?>
                </td>
                <?php endif; ?>
                <td><?= $row['creative_marks'] ?></td>
                <?php if($hasCpass): ?><td><?= isset($row['creative_pass'])?(int)$row['creative_pass']:0 ?></td><?php endif; ?>
                <td><?= $row['objective_marks'] ?></td>
                <?php if($hasOpass): ?><td><?= isset($row['objective_pass'])?(int)$row['objective_pass']:0 ?></td><?php endif; ?>
                <td><?= $row['practical_marks'] ?></td>
                <?php if($hasPpass): ?><td><?= isset($row['practical_pass'])?(int)$row['practical_pass']:0 ?></td><?php endif; ?>
                <td><?= $row['total_marks'] ?></td>
                <td><?= isset($row['teacher_name']) && $row['teacher_name'] ? htmlspecialchars($row['teacher_name']) : '<span class="text-muted">Not Assigned</span>' ?></td>
                <td class="d-print-none">
                    <a href="edit_exam.php?id=<?= $row['exam_subject_id'] ?>" class="btn btn-sm btn-info">Edit</a>
                    <a href="delete_exam.php?id=<?= $row['exam_subject_id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure you want to delete this item?')">Delete</a>
                </td>
                <td class="signature-cell"></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
            </table>

            <!-- Print-only branding footer (appears at the end/last page) -->
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
        </div><!-- /.container-fluid -->
    </section>
</div><!-- /.content-wrapper -->

<style>
@media print {
    html, body { background:#fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .content-header, .main-footer, .d-print-none, .sidebar, .navbar { display:none !important; }
    .print-header { display:block !important; }
    /* Remove any residual grey backgrounds from AdminLTE wrappers */
    .wrapper, .content-wrapper, .content, .container-fluid, .row, .card, .card-body, .card-header { background:#fff !important; }
    .exam-details-table { border-collapse:collapse; width:100%; }
    /* Normalize padding and vertical rhythm */
    .exam-details-table th, .exam-details-table td { font-size:12px; padding:8px 6px; line-height:1.2; }
    .exam-details-table thead th[rowspan="2"]{ vertical-align:middle !important; padding-top:8px; padding-bottom:8px; }
    /* Force transparent backgrounds in print */
    .exam-details-table th { background:transparent !important; color:#000 !important; text-align:center; vertical-align:middle; }
    .exam-details-table tr { background:transparent !important; }
    .print-header, .ph-grid, .ph-center, .ph-left { background:transparent !important; }
    .exam-details-table td { vertical-align:middle; }
    .exam-details-table td, .exam-details-table th { border:1px solid #444 !important; }
    /* Signature column size and a bit more row height for signing */
    .signature-cell { min-width: 95px; height: 28px; }
    /* Branding footer small and right aligned */
    .print-brand { margin-top:8px; font-size:11px; text-align:right; color:#333; }
}
/* Print header layout: logo flush-left, center content perfectly centered */
.ph-grid{ display:grid; grid-template-columns: auto 1fr auto; align-items:center; }
.ph-left img{ height:60px; object-fit:contain; display:block; }
.ph-center{ text-align:center; }
.ph-name{ font-size:26px; font-weight:800; line-height:1.05; margin:0; }
.ph-meta{ font-size:12px; margin:0; line-height:1.1; }
.ph-title{ font-size:17px; font-weight:700; margin:2px 0 0 0; display:inline-block; padding:2px 8px; border-radius:4px; background:#eef3ff; border:1px solid #cdd9ff; }
.ph-sub{ font-size:11px; margin:2px 0 0 0; line-height:1.1; }
</style>

<script>
 (function(){
   // Read status from query string and show toast
   const params = new URLSearchParams(window.location.search);
   const status = params.get('status');
   const msg = params.get('msg');
   if (status && window.showToast){
     let title = status === 'success' ? 'Success' : 'Message';
     let body = 'Operation completed.';
     if (msg === 'created') body = 'Exam created successfully.';
     if (msg === 'updated') body = 'Exam updated successfully.';
     if (msg === 'deleted') body = 'Record deleted successfully.';
     window.showToast(title, body, status);
   }
 })();
    function printLandscape(){
        // Inject a temporary style to set landscape orientation
        var st = document.createElement('style');
        st.id = 'landscapeStyle';
        st.media = 'print';
        st.appendChild(document.createTextNode('@page { size: landscape; }'));
        document.head.appendChild(st);
        window.print();
        setTimeout(function(){ var s=document.getElementById('landscapeStyle'); if (s) s.remove(); }, 1000);
    }
</script>
<?php include '../includes/footer.php'; ?>
