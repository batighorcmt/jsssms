<?php
session_start();
// Optional access control (disabled for preview)
// if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin'])) { header('Location: ../auth/login.php'); exit; }

require_once __DIR__ . '/../config/db.php'; // mysqli $conn

// Inputs
$exam_id = intval($_GET['exam_id'] ?? 0);
$class_id = intval($_GET['class_id'] ?? 0);
$year     = intval($_GET['year'] ?? 0);
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$student_ids_param = trim($_GET['student_ids'] ?? ''); // optional: comma separated students.id
$search_roll = trim($_GET['search_roll'] ?? '');

if ($exam_id <= 0) {
    echo '<div style="font-family: sans-serif; padding:20px; color:#b91c1c;">exam_id parameter is required.</div>';
    exit;
}

// Institute info (fallbacks)
$institute_name = 'Jorepukuria Secondary School';
$institute_address = 'Gangni, Meherpur';
$institute_phone = '';
$institute_logo = '../assets/logo.png';
if ($conn) {
    $q = $conn->query("SHOW TABLES LIKE 'institute_info'");
    if ($q && $q->num_rows > 0) {
    $row = $conn->query("SELECT name, address, phone, logo FROM institute_info LIMIT 1");
        if ($row && $row->num_rows > 0) {
            $inf = $row->fetch_assoc();
            $institute_name = $inf['name'] ?? $institute_name;
            $institute_address = $inf['address'] ?? $institute_address;
            $institute_phone = $inf['phone'] ?? $institute_phone;
      if (!empty($inf['logo'])) { $institute_logo = '../uploads/logo/' . $inf['logo']; }
        }
    }
}

// Exam info
$exam = null; $class_name = '';
$ex = $conn->query("SELECT exams.*, classes.class_name FROM exams JOIN classes ON classes.id = exams.class_id WHERE exams.id = " . $exam_id);
if ($ex && $ex->num_rows > 0) {
    $exam = $ex->fetch_assoc();
    $class_name = $exam['class_name'] ?? '';
    if ($class_id <= 0) { $class_id = intval($exam['class_id'] ?? 0); }
} else {
    echo '<div style="font-family: sans-serif; padding:20px; color:#b91c1c;">Exam not found.</div>';
    exit;
}
$exam_name = $exam['exam_name'] ?? 'পরীক্ষা';

// Exam schedule (by exam) - include subject_code for display
$schedule = [];
$sched_sql = "SELECT es.subject_id, s.subject_name, s.subject_code, es.exam_date, es.exam_time
              FROM exam_subjects es
              JOIN subjects s ON s.id = es.subject_id
              WHERE es.exam_id = ?
              ORDER BY es.exam_date IS NULL, es.exam_date ASC, es.exam_time IS NULL, es.exam_time ASC, s.subject_code ASC, s.subject_name ASC";
if ($st = $conn->prepare($sched_sql)) {
    $st->bind_param('i', $exam_id);
    $st->execute();
    $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $schedule[] = $r; }
    $st->close();
}

// Detect students table columns
$col_has_class_id=false;$col_has_class=false;$col_has_section_id=false;$col_has_section=false;$col_has_roll_no=false;$col_has_roll_number=false;$col_has_year=false;$col_has_photo=false;$col_has_student_id_str=false;
if ($conn) {
    if ($cres = $conn->query("SHOW COLUMNS FROM students")) {
        while ($c = $cres->fetch_assoc()) {
            $n = strtolower($c['Field']);
            if ($n==='class_id') $col_has_class_id=true;
            if ($n==='class') $col_has_class=true;
            if ($n==='section_id') $col_has_section_id=true;
            if ($n==='section') $col_has_section=true;
            if ($n==='roll_no') $col_has_roll_no=true;
            if ($n==='roll_number') $col_has_roll_number=true;
            if ($n==='year') $col_has_year=true;
            if ($n==='photo') $col_has_photo=true;
            if ($n==='student_id') $col_has_student_id_str=true;
        }
    }
}

// Build students list
$students = [];
if ($student_ids_param !== '') {
    $ids = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $student_ids_param))));
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT * FROM students WHERE id IN ($in)";
        $types = str_repeat('i', count($ids));
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$ids);
        $st->execute(); $res = $st->get_result();
        while ($r = $res->fetch_assoc()) { $students[] = $r; }
        $st->close();
    }
}
if (empty($students)) {
    // filter by class/year/section/roll
    $conds=[];$params=[];$types='';
    if ($class_id>0){ if($col_has_class_id){$conds[]='class_id=?';$params[]=$class_id;$types.='i';}elseif($col_has_class){$conds[]='class=?';$params[]=$class_id;$types.='i';}}
    if ($year>0 && $col_has_year){$conds[]='year=?';$params[]=$year;$types.='i';}
    if ($section_id>0){ if($col_has_section_id){$conds[]='section_id=?';$params[]=$section_id;$types.='i';}elseif($col_has_section){$conds[]='section=?';$params[]=$section_id;$types.='i';}}
    if ($search_roll!==''){ if($col_has_roll_no){$conds[]='roll_no=?';$params[]=$search_roll;$types.='s';}elseif($col_has_roll_number){$conds[]='roll_number=?';$params[]=$search_roll;$types.='s';}}
    $where = !empty($conds)?('WHERE '.implode(' AND ',$conds)) : '';
    $orderCol = $col_has_roll_no?'roll_no':'roll_number';
    $sql = "SELECT * FROM students $where ORDER BY $orderCol ASC";
    $st = $conn->prepare($sql);
    if (!empty($params)) { $st->bind_param($types, ...$params); }
    $st->execute(); $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $students[] = $r; }
    $st->close();
}

// Assigned subjects by student (support both numeric students.id and string students.student_id)
$assigned_by_student = [];
$assigned_by_student_str = [];
if (!empty($students)) {
    $ids = array_map(fn($r)=> intval($r['id']), $students);
    $ids = array_values(array_filter($ids));
    if (!empty($ids)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT student_id, subject_id FROM student_subjects WHERE student_id IN ($in)";
        $types = str_repeat('i', count($ids));
        $st = $conn->prepare($sql);
        $st->bind_param($types, ...$ids);
        $st->execute(); $res = $st->get_result();
        while ($r = $res->fetch_assoc()) {
            $sid = intval($r['student_id']); $subId = intval($r['subject_id']);
            if (!isset($assigned_by_student[$sid])) $assigned_by_student[$sid] = [];
            $assigned_by_student[$sid][$subId] = true;
        }
        $st->close();
    }
    // Also try string-based mapping if students table has a student_id (string) column
    $stu_id_strs = [];
    if ($col_has_student_id_str) {
        foreach ($students as $r) {
            $sidStr = trim((string)($r['student_id'] ?? ''));
            if ($sidStr !== '') $stu_id_strs[] = $sidStr;
        }
        $stu_id_strs = array_values(array_unique($stu_id_strs));
        if (!empty($stu_id_strs)) {
            $in2 = implode(',', array_fill(0, count($stu_id_strs), '?'));
            $sql2 = "SELECT student_id, subject_id FROM student_subjects WHERE student_id IN ($in2)";
            $types2 = str_repeat('s', count($stu_id_strs));
            $st2 = $conn->prepare($sql2);
            $st2->bind_param($types2, ...$stu_id_strs);
            $st2->execute(); $res2 = $st2->get_result();
            while ($r2 = $res2->fetch_assoc()) {
                $sidStr = (string)$r2['student_id']; $subId = intval($r2['subject_id']);
                if (!isset($assigned_by_student_str[$sidStr])) $assigned_by_student_str[$sidStr] = [];
                $assigned_by_student_str[$sidStr][$subId] = true;
            }
            $st2->close();
        }
    }
}

// Helpers
function fmt_date($d){ return $d ? date('d/m/Y', strtotime($d)) : '-'; }
function fmt_time($t){ return $t ? date('h:i A', strtotime($t)) : '-'; }
function bn_num($v){ $en=['0','1','2','3','4','5','6','7','8','9']; $bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯']; return str_replace($en,$bn,(string)$v); }
$bnDays=['রবিবার','সোমবার','মঙ্গলবার','বুধবার','বৃহস্পতিবার','শুক্রবার','শনিবার'];
function shortSubjectName($name){
  $n = trim((string)$name);
  if ($n === '') return $n;
  $normalized = strtolower(preg_replace('/\s+/', ' ', $n));
  if ($normalized === 'information and communication technology') return 'ICT';
  if ($normalized === 'history of bangladesh and world civilization') return 'History';
  if (strpos($normalized, 'information') !== false && strpos($normalized, 'communication') !== false && strpos($normalized, 'technology') !== false) return 'ICT';
  if (strpos($normalized, 'history of bangladesh') !== false && strpos($normalized, 'world civilization') !== false) return 'History';
  return $n;
}

?><!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>সরল প্রবেশপত্র - <?php echo htmlspecialchars($institute_name); ?></title>
<style>
  /* Use Kalpurush for Bangla text */
  @font-face{font-family:'Kalpurush';src:url('https://fonts.maateen.me/kalpurush/font.ttf') format('truetype');font-display:swap}
  body{margin:0;padding:0;font-family:'Kalpurush', Arial, Helvetica, sans-serif}
  .sheet{padding:8mm 8mm; break-inside: avoid; page-break-inside: avoid}
  /* Header: logo left, institute info center, photo right */
  .school-header{display:grid;grid-template-columns:70px 1fr 80px;gap:8px;align-items:center;margin-bottom:8px}
  .logo{width:70px;height:70px;display:flex;align-items:center;justify-content:center}
  .logo img{max-width:100%;max-height:100%;object-fit:contain}
  .school-text{text-align:center}
  .school-name{font-weight:800;font-size:22px;line-height:1.1;margin:0}
  .school-meta{color:#444;line-height:1.2;margin:2px 0 0 0}
  .exam-title{font-weight:800;margin:2px 0 0 0}
  .admit-text{display:inline-block;font-weight:800;margin-top:4px;padding:3px 8px;border:2px solid #222;border-radius:4px;font-size:18px;letter-spacing:0.3px}
  .photo{width:80px;height:100px;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;overflow:hidden;border-radius:4px;background:#fafafa;font-size:12px;color:#777}
  /* Fill frame but avoid head cut by anchoring to top */
  .photo img{width:100%;height:100%;object-fit:cover;object-position:top center}
  .student-info{margin:6px 0}
  .info-row{display:flex;align-items:baseline;justify-content:space-between;gap:10px;white-space:nowrap}
  .kv .k{font-weight:700;color:#222}
  .kv .v{color:#111}
  table{width:100%;border-collapse:collapse;font-family:'Kalpurush', Arial, sans-serif}
  /* Minimize table cell height (slightly larger font for readability) */
  th,td{border:1px solid #ddd;padding:2px 3px;text-align:center;line-height:1.1;font-size:13px}
  th{background:#f2f2f2}
  .signature-area{margin-top:12px;text-align:center}
  .signature-area table{width:90%;margin:0 auto;border:none;table-layout:fixed}
  .signature-area td{border:none;padding-top:0;vertical-align:bottom}
  .sig-space{height:12mm}
  .sig-line{width:55%;margin:0 auto 3mm;border-top:1px solid #333}
  .sig-label{font-weight:600}
  .sig-gap{width:20%}
  /* Floating print action */
  .actions{ position:fixed; top:10px; left:50%; transform:translateX(-50%); z-index:99999; text-align:center; background:rgba(255,255,255,0.95); border:1px solid #ddd; border-radius:8px; padding:6px 10px; box-shadow:0 2px 8px rgba(0,0,0,0.08) }
  .print-btn{ background:#1e3a8a; color:#fff; border:none; border-radius:6px; padding:8px 14px; cursor:pointer }
  .print-btn:hover{ background:#172554 }
  /* Cut line across the middle of each printed page */
  .cutline{display:none}
  @media print{
    @page{size:A4 portrait;margin:10mm}
    /* Two-cards-per-page: ensure total height fits even at 100% zoom */
  .sheet{height:122mm;padding:4mm 8mm}
  /* Keep a blank space below the cut line before bottom card starts */
  .sheet:nth-child(odd){ margin-bottom:18mm }
    /* break after every 2 sheets */
    .sheet:nth-child(2n){page-break-after:always}
    .sheet:last-child{page-break-after:auto}
    .cutline{display:block;position:fixed;left:0;right:0;top:50%;border-top:1px dashed #999;z-index:9999}
    .actions{ display:none }
  }
</style>
</head>
<body>
<div class="actions"><button class="print-btn" onclick="window.print()">প্রিন্ট করুন</button></div>
<div class="cutline" aria-hidden="true"></div>
<?php foreach ($students as $stu) {
    $full_name = trim(($stu['student_name'] ?? (($stu['first_name'] ?? '') . ' ' . ($stu['last_name'] ?? ''))));
    $roll = $col_has_roll_no ? ($stu['roll_no'] ?? '') : ($col_has_roll_number ? ($stu['roll_number'] ?? '') : '');
    $section_name = '';
    $group = trim((string)($stu['student_group'] ?? ''));
    $sid = null;
    if ($col_has_section_id && !empty($stu['section_id'])) { $sid = intval($stu['section_id']); }
    elseif ($col_has_section && !empty($stu['section'])) { $sid = intval($stu['section']); }
    if (!empty($sid)) {
        $sres = $conn->query("SELECT section_name FROM sections WHERE id = $sid");
        if ($sres && $sres->num_rows > 0) { $section_name = $sres->fetch_assoc()['section_name'] ?? ''; }
    }

    // Student photo (right side)
    $photoUrl = '';
    if (!empty($col_has_photo) && !empty($stu['photo'])) {
        $photoUrl = '../uploads/students/' . $stu['photo'];
    }

    // Student's assigned subjects (try numeric id first, then string student_id)
    $assigned = [];
    $stuIdNum = intval($stu['id'] ?? 0);
    if (!empty($assigned_by_student[$stuIdNum])) {
        $assigned = array_keys($assigned_by_student[$stuIdNum]);
    } elseif ($col_has_student_id_str && !empty($stu['student_id']) && !empty($assigned_by_student_str[(string)$stu['student_id']])) {
        $assigned = array_keys($assigned_by_student_str[(string)$stu['student_id']]);
    }

    // Build schedule strictly for assigned subjects only (fallback to full if none)
    $sched_for_student = [];
    if (!empty($assigned)) {
        foreach ($schedule as $row) {
            if (isset($row['subject_id']) && in_array(intval($row['subject_id']), $assigned, true)) { $sched_for_student[] = $row; }
        }
    } else {
        $sched_for_student = $schedule; // fallback
    }
?>
<div class="sheet">
  <div class="school-header">
    <div class="logo">
      <?php if ($institute_logo): ?>
        <img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" onerror="this.style.display='none'">
      <?php endif; ?>
    </div>
    <div class="school-text">
      <h1 class="school-name"><?php echo htmlspecialchars($institute_name); ?></h1>
      <div class="school-meta"><?php echo htmlspecialchars($institute_address . ($institute_phone!==''? (' | মোবাইলঃ '.$institute_phone) : '')); ?></div>
      <div class="exam-title"><?php echo htmlspecialchars($exam_name); ?></div>
      <div class="admit-text">Admit Card</div>
    </div>
    <div class="photo">
      <?php if ($photoUrl): ?>
        <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Photo" onerror="this.parentNode.textContent='ছবি';this.remove();">
      <?php else: ?>ছবি<?php endif; ?>
    </div>
  </div>

  <div class="student-info">
    <div class="info-row">
      <div class="kv"><span class="k">নামঃ</span> <span class="v"><?php echo htmlspecialchars($full_name); ?></span></div>
      <div class="kv"><span class="k">শ্রেণীঃ</span> <span class="v"><?php echo htmlspecialchars($class_name); ?></span></div>
      <div class="kv"><span class="k">শাখাঃ</span> <span class="v"><?php echo htmlspecialchars($section_name !== '' ? $section_name : '—'); ?></span></div>
      <div class="kv"><span class="k">রোল নংঃ</span> <span class="v bn"><?php echo htmlspecialchars(bn_num($roll)); ?></span></div>
      <div class="kv"><span class="k">গ্রুপঃ</span> <span class="v"><?php echo htmlspecialchars($group !== '' ? $group : '—'); ?></span></div>
    </div>
  </div>

  <table>
    <thead>
      <tr>
        <th>ক্রমিক নং</th>
        <th>তারিখ</th>
        <th>বার</th>
        <th>বিষয়</th>
        <th>বিষয় কোড</th>
        <th>সময়</th>
      </tr>
    </thead>
    <tbody>
      <?php $i=0; foreach ($sched_for_student as $row) { $i++; $d=$row['exam_date']??null; ?>
      <tr>
        <td><?php echo htmlspecialchars(bn_num($i)); ?></td>
        <td><span class="bn"><?php echo htmlspecialchars(bn_num(fmt_date($row['exam_date'] ?? null))); ?></span></td>
        <td><?php echo $d? htmlspecialchars($bnDays[intval(date('w', strtotime($d)))]) : '-'; ?></td>
        <td><?php echo htmlspecialchars(shortSubjectName($row['subject_name'] ?? '')); ?></td>
        <td><?php echo htmlspecialchars((string)($row['subject_code'] ?? '')); ?></td>
        <td><span class="bn"><?php echo htmlspecialchars(bn_num(fmt_time($row['exam_time'] ?? null))); ?></span></td>
      </tr>
      <?php } if ($i===0) { ?>
      <tr><td colspan="6">এই শিক্ষার্থীর জন্য কোনো সময়সূচী পাওয়া যায়নি।</td></tr>
      <?php } ?>
    </tbody>
  </table>

  <div class="signature-area">
    <table>
      <tr>
        <td>
          <div class="sig-space"></div>
          <div class="sig-line"></div>
          <div class="sig-label">শ্রেণি শিক্ষক</div>
        </td>
        <td class="sig-gap"></td>
        <td>
          <div class="sig-space"></div>
          <div class="sig-line"></div>
          <div class="sig-label">প্রধান শিক্ষক</div>
        </td>
      </tr>
    </table>
  </div>
</div>
<?php } ?>
</body>
</html>
