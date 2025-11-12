<?php
@require_once __DIR__ . '/../config/config.php';
$ALLOWED_ROLES = ['super_admin','teacher'];
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/db.php';

// School identity (override via constants if available)
$SCHOOL_NAME = defined('SCHOOL_NAME') ? constant('SCHOOL_NAME') : 'Jorpukuria Secondary School';
$SCHOOL_ADDRESS = defined('SCHOOL_ADDRESS') ? constant('SCHOOL_ADDRESS') : 'Gangni, Meherpur';

// Inputs
$exam_id  = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$year     = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$subject_id = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0; // optional preselect

// Fetch exam + class names
$exam_name = '';$class_name='';
if ($exam_id) {
    $er = $conn->prepare('SELECT exam_name FROM exams WHERE id=? LIMIT 1');
    $er->bind_param('i',$exam_id); $er->execute(); $er->bind_result($exam_name); $er->fetch(); $er->close();
}
if ($class_id) {
    $cr = $conn->prepare('SELECT class_name FROM classes WHERE id=? LIMIT 1');
    $cr->bind_param('i',$class_id); $cr->execute(); $cr->bind_result($class_name); $cr->fetch(); $cr->close();
}

// Load subjects configured for this exam/class/year
$subjects = [];
if ($exam_id && $class_id) {
    $sql = "SELECT es.subject_id, s.subject_name, s.subject_code, es.creative_marks, es.objective_marks, es.practical_marks, es.teacher_id
            FROM exam_subjects es
            JOIN subjects s ON es.subject_id = s.id
            JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = ?
            WHERE es.exam_id = ? AND (es.year = ? OR es.year IS NULL OR es.year=0)
            ORDER BY s.subject_code";
    $st = $conn->prepare($sql);
    $st->bind_param('iii',$class_id,$exam_id,$year);
    $st->execute();
    $res = $st->get_result();
    while($r=$res->fetch_assoc()) { $subjects[]=$r; }
    $st->close();
}

$selected_subject = null;
if ($subject_id) {
    foreach($subjects as $s) { if ((int)$s['subject_id']===$subject_id){ $selected_subject=$s; break; } }
}

// Determine subject teacher (simple heuristic: teachers.subject LIKE subject_name or subject_code in teachers.subject)
$subject_teacher = '';
if ($selected_subject) {
  $tid = (int)($selected_subject['teacher_id'] ?? 0);
  if ($tid > 0) {
    if ($tr = $conn->prepare("SELECT name FROM teachers WHERE id = ? LIMIT 1")) { $tr->bind_param('i',$tid); $tr->execute(); $tr->bind_result($subject_teacher); $tr->fetch(); $tr->close(); }
  }
  // Fallback heuristic if not assigned
  if ($subject_teacher === '') {
    $sn = $selected_subject['subject_name'];
    $sc = $selected_subject['subject_code'];
    if ($tr = $conn->prepare("SELECT name FROM teachers WHERE subject LIKE CONCAT('%',?,'%') OR subject LIKE CONCAT('%',?,'%') LIMIT 1")) {
      $tr->bind_param('ss',$sn,$sc);
      $tr->execute(); $tr->bind_result($subject_teacher); $tr->fetch(); $tr->close();
    }
  }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1>Blank Mark Entry Form</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Blank Mark Entry</li>
          </ol>
        </div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-body">
          <form class="form-inline mb-3 no-print" method="get" action="">
            <input type="hidden" name="exam_id" value="<?= htmlspecialchars($exam_id) ?>" />
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>" />
            <input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>" />
            <label class="mr-2">Select Subject:</label>
            <select name="subject_id" class="form-control form-control-sm mr-2" required>
              <option value="">-- Subject --</option>
              <?php foreach($subjects as $s): ?>
                <option value="<?= $s['subject_id'] ?>" <?= ($subject_id==$s['subject_id'])?'selected':'' ?>><?= htmlspecialchars($s['subject_code'].' - '.$s['subject_name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">Load</button>
            <?php if ($selected_subject): ?>
              <button type="button" onclick="window.print()" class="btn btn-sm btn-success ml-2"><i class="fas fa-print"></i> Print</button>
            <?php endif; ?>
          </form>
          <?php if (!$exam_id || !$class_id): ?>
            <div class="alert alert-warning">Please provide exam_id and class_id in the URL.</div>
          <?php elseif(empty($subjects)): ?>
            <div class="alert alert-info">No subjects configured for this exam.</div>
          <?php endif; ?>
          <?php if ($selected_subject): ?>
            <div id="printArea" class="mt-3">
              <style>
              @media print { .no-print, .main-sidebar, .main-header, .main-footer { display:none !important; } body, html { background:#fff; } #printArea { margin:0; } }
              .bm-header { text-align:center; margin-bottom:14px; }
              .bm-header h2 { font-size:22px; margin:0; }
              .bm-meta { font-size:18px; margin-bottom:10px; width:100%; }
              .bm-meta .line { display:flex; justify-content:space-between; align-items:baseline; gap:24px; width:100%; }
              .bm-meta .line span { flex:1; }
              .bm-meta .line .subject { flex:3 1 0; }
              .bm-meta .line .code { flex:1 0 auto; }
              .bm-meta .line .teacher { flex:2 1 0; }
              table.blank-table { width:100%; border-collapse:collapse; font-size:14.5px; }
              table.blank-table th, table.blank-table td { border:1px solid #000; padding:8px 10px; vertical-align:middle; }
              table.blank-table th { text-align:center; }
              table.blank-table td { height:32px; }
              .logo-cell { width:110px; }
              </style>
              <div class="bm-header">
                <table style="width:100%; border:0; margin-bottom:10px;">
                  <tr>
                    <td class="logo-cell" style="text-align:center;">
                      <?php
                        $logo_path = __DIR__.'/../assets/logo.png';
                        if (file_exists($logo_path)) {
                          echo '<img src="'.htmlspecialchars(BASE_URL.'assets/logo.png').'" style="height:90px;" alt="logo">';
                        }
                      ?>
                    </td>
                    <td style="text-align:center;">
                      <h2><?= htmlspecialchars($SCHOOL_NAME) ?></h2>
                      <div><?= htmlspecialchars($SCHOOL_ADDRESS) ?></div>
                      <div style="font-weight:bold;"><?= htmlspecialchars($exam_name) ?></div>
                      <div>Class: <?= htmlspecialchars($class_name) ?></div>
                    </td>
                    <td class="logo-cell"></td>
                  </tr>
                </table>
                <div class="bm-meta" style="text-align:left; padding:4px 0;">
                  <?php
                    $meta_subject = $selected_subject ? ($selected_subject['subject_name'] ?? '') : '';
                    $meta_code = $selected_subject ? ($selected_subject['subject_code'] ?? '') : '';
                    $meta_teacher = $subject_teacher ?: '';
                    // Determine mark components present for this subject
                    $creativeMax = isset($selected_subject['creative_marks']) ? (int)$selected_subject['creative_marks'] : 0;
                    $objectiveMax = isset($selected_subject['objective_marks']) ? (int)$selected_subject['objective_marks'] : 0;
                    $practicalMax = isset($selected_subject['practical_marks']) ? (int)$selected_subject['practical_marks'] : 0;
                  ?>
                  <div class="line">
                    <span class="subject">Subject: <strong><?= htmlspecialchars($meta_subject ?: '________________') ?></strong></span>
                    <span class="code">Code: <strong><?= htmlspecialchars($meta_code ?: '____________') ?></strong></span>
                    <span class="teacher">Subject Teacher: <strong><?= htmlspecialchars($meta_teacher ?: '________________________') ?></strong></span>
                  </div>
                </div>
              </div>
              <?php
                // Fetch only students who have this subject
                $stuSql = 'SELECT s.student_id, s.student_name, s.roll_no
                           FROM students s
                           JOIN student_subjects ss ON s.student_id = ss.student_id
                           WHERE s.class_id = ? AND s.year = ? AND ss.subject_id = ?
                           ORDER BY s.roll_no';
                $stuQ = $conn->prepare($stuSql);
                $stuQ->bind_param('iii', $class_id, $year, $subject_id);
                $stuQ->execute();
                $stuRes = $stuQ->get_result();
              ?>
              <?php if ($stuRes->num_rows > 0): ?>
              <table class="blank-table">
                <thead>
                    <tr>
                        <th style="width:60px">Serial</th>
                        <th>Student Name</th>
                        <th style="width:90px">Roll No</th>
                        <?php if ($creativeMax > 0): ?><th style="width:110px">Creative</th><?php endif; ?>
                        <?php if ($objectiveMax > 0): ?><th style="width:110px">MCQ</th><?php endif; ?>
                        <?php if ($practicalMax > 0): ?><th style="width:110px">Practical</th><?php endif; ?>
                        <th style="width:110px">Total</th>
                        <th style="width:110px">Grade</th>  
                    </tr>
                </thead>
                <tbody>
                <?php $i=1; while($stu=$stuRes->fetch_assoc()): ?>
                  <tr>
                    <td style="text-align:center;"><?= $i++; ?></td>
                    <td><?= htmlspecialchars($stu['student_name']) ?></td>
                    <td style="text-align:center;"><?= htmlspecialchars($stu['roll_no']) ?></td>
                    <?php if ($creativeMax > 0): ?><td></td><?php endif; ?>
                    <?php if ($objectiveMax > 0): ?><td></td><?php endif; ?>
                    <?php if ($practicalMax > 0): ?><td></td><?php endif; ?>
                    <td></td>
                    <td></td>
                  </tr>
                <?php endwhile; $stuQ->close(); ?>
                </tbody>
              </table>
              <?php else: $stuQ->close(); ?>
                <div class="alert alert-info">No students found for this subject.</div>
              <?php endif; ?>
              <br>
              <div class="row" style="margin-top:70px;">
                <div style="width:33%; text-align:center; float:left;">Teacher's Signature</div>
                <div style="width:33%; text-align:center; float:left;">Exam Controller</div>
                <div style="width:33%; text-align:center; float:left;">Head Teacher</div>
              </div>
            </div>
            <script class="no-print"></script>
          <?php elseif ($exam_id && $class_id): ?>
            <div class="alert alert-info mb-0">Select a subject from the list above.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
