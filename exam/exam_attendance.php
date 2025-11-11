<?php
session_start();
// Access: optional restrict to admin/teacher
// if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin','teacher'])) { header('Location: ../auth/login.php'); exit; }

require_once __DIR__ . '/../config/db.php'; // mysqli $conn

// Inputs
$exam_id = intval($_GET['exam_id'] ?? 0);
$class_id = intval($_GET['class_id'] ?? 0);
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$year     = intval($_GET['year'] ?? 0);

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

// Preload sections map for quick lookup
$sections_map = [];
if ($conn) {
  if ($sres = $conn->query("SHOW TABLES LIKE 'sections'")) {
    if ($sres && $sres->num_rows > 0) {
      $q = $conn->query("SELECT id, section_name FROM sections");
      if ($q) { while ($r = $q->fetch_assoc()) { $sections_map[intval($r['id'])] = $r['section_name']; } }
    }
  }
}

// Subjects columns detection
$has_subject_code = false;
if ($conn) {
  if ($cres = $conn->query("SHOW COLUMNS FROM subjects")) {
    while ($c = $cres->fetch_assoc()) {
      if (strtolower($c['Field']) === 'subject_code') { $has_subject_code = true; break; }
    }
  }
}

// Exam schedule
$schedule = [];
$sched_sql = "SELECT es.subject_id, s.subject_name" . ($has_subject_code? ", s.subject_code" : ", '' AS subject_code") . ", es.exam_date, es.exam_time
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
$col_has_class_id=false;$col_has_class=false;$col_has_section_id=false;$col_has_section=false;$col_has_roll_no=false;$col_has_roll_number=false;$col_has_year=false;$col_has_student_name=false;$col_has_student_id_str=false;$col_has_group=false;$col_has_department=false;$col_has_division=false;$col_has_stream=false;$col_has_photo=false;$col_has_student_group=false;
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
      if ($n==='student_name' || $n==='first_name') $col_has_student_name=true;
      if ($n==='student_id') $col_has_student_id_str=true;
      if ($n==='group' || $n==='class_group') $col_has_group=true;
      if ($n==='department' || $n==='dept') $col_has_department=true;
      if ($n==='division') $col_has_division=true;
      if ($n==='stream') $col_has_stream=true;
  if ($n==='photo') $col_has_photo=true;
  if ($n==='student_group') $col_has_student_group=true;
    }
  }
}

// Students list for class/section/year
$students = [];
{
  $conds=[];$params=[];$types='';
  if ($class_id>0){ if($col_has_class_id){$conds[]='class_id=?';$params[]=$class_id;$types.='i';}elseif($col_has_class){$conds[]='class=?';$params[]=$class_id;$types.='i';}}
  if ($section_id>0){ if($col_has_section_id){$conds[]='section_id=?';$params[]=$section_id;$types.='i';}elseif($col_has_section){$conds[]='section=?';$params[]=$section_id;$types.='i';}}
  if ($year>0 && $col_has_year){$conds[]='year=?';$params[]=$year;$types.='i';}
  $where = !empty($conds)?('WHERE '.implode(' AND ',$conds)) : '';
  $orderRoll = $col_has_roll_no?'roll_no':($col_has_roll_number?'roll_number':'id');
  $sql = "SELECT * FROM students $where ORDER BY $orderRoll ASC";
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
  if ($col_has_student_id_str) {
    $stu_id_strs = [];
    foreach ($students as $r) { $sidStr = trim((string)($r['student_id'] ?? '')); if ($sidStr !== '') $stu_id_strs[] = $sidStr; }
    $stu_id_strs = array_values(array_unique($stu_id_strs));
    if (!empty($stu_id_strs)) {
      $in2 = implode(',', array_fill(0, count($stu_id_strs), '?'));
      $sql2 = "SELECT student_id, subject_id FROM student_subjects WHERE student_id IN ($in2)";
      $types2 = str_repeat('s', count($stu_id_strs));
      $st2 = $conn->prepare($sql2);
      $st2->bind_param($types2, ...$stu_id_strs);
      $st2->execute(); $res2 = $st2->get_result();
      while ($r2 = $res2->fetch_assoc()) { $sidStr = (string)$r2['student_id']; $subId = intval($r2['subject_id']); if (!isset($assigned_by_student_str[$sidStr])) $assigned_by_student_str[$sidStr] = []; $assigned_by_student_str[$sidStr][$subId] = true; }
      $st2->close();
    }
  }
}

function bn_num($v){ $en=['0','1','2','3','4','5','6','7','8','9']; $bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯']; return str_replace($en,$bn,(string)$v); }
function fmt_date($d){ return $d ? date('d/m/Y', strtotime($d)) : '-'; }
function fmt_time($t){ return $t ? date('h:i A', strtotime($t)) : '-'; }
function shortSubjectName($name){
  $n = trim((string)$name);
  if ($n === '') return $n;
  // Normalize whitespace and compare case-insensitively
  $normalized = strtolower(preg_replace('/\s+/', ' ', $n));
  if ($normalized === 'information and communication technology') return 'ICT';
  if ($normalized === 'history of bangladesh and world civilization') return 'History';
  // Heuristic contains check
  if (strpos($normalized, 'information') !== false && strpos($normalized, 'communication') !== false && strpos($normalized, 'technology') !== false) return 'ICT';
  if (strpos($normalized, 'history of bangladesh') !== false && strpos($normalized, 'world civilization') !== false) return 'History';
  return $n;
}

?><!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>পরীক্ষার হাজিরা শীট - <?php echo htmlspecialchars($institute_name); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
  :root { --primary:#0ea5e9; --border:#1f2937; --ink:#0a0a0a; --muted:#111827; --bg:#ffffff; }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:'Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .actions{position:sticky;top:0;z-index:10;background:#fff;padding:10px;text-align:center;border-bottom:1px solid var(--border)}
    .btn{background:var(--primary);border:none;color:#fff;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer}
    .page{width:100%;max-width:210mm;margin:0 auto;padding:10mm}
    .sheet{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.06);page-break-after:always}
    .sheet:last-child{page-break-after:auto}
  .hdr{display:grid;grid-template-columns:70px 1fr 85px;gap:12px;align-items:center;padding:4px 12px 4px;border-bottom:1px solid var(--border)}
    .logo{width:70px;height:70px;display:flex;align-items:center;justify-content:center}
    .logo img{max-width:100%;max-height:100%}
    .brand{text-align:center}
  .school-name{font-size:24px;font-weight:800;color:#000;margin:0;line-height:1.1}
  .school-meta{margin:0;color:#000;font-weight:700;line-height:1.1}
    .stud-photo{width:80px;height:95px;border:1px solid var(--border);border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden}
    .stud-photo img{width:100%;height:100%;object-fit:cover}
  .meta{padding:8px 12px;border-bottom:1px solid var(--border);display:flex;flex-direction:column;gap:6px}
    .meta .row{display:flex;flex-wrap:wrap;gap:18px;align-items:center}
    .meta .item{font-weight:800;color:#000}
    .meta .light{font-weight:700;color:#000}

  table{width:100%;border-collapse:collapse}
  th,td{border:1px solid var(--border);padding:8px 10px;text-align:center;font-size:12pt;color:#000}
  thead th{background:transparent;color:#000;font-weight:800}
    td.name, th.name{text-align:left}
    td.code, th.code{width:80px;white-space:nowrap}
    .bn{font-family:'Noto Sans Bengali','Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    td.sigc{height:18mm}
  /* Compact student info table spacing */
  table.info-table{margin:4px 0;padding-top:0;padding-bottom:5px;}
  table.info-table td{border:none;padding:2px 8px;font-size:13pt;color:#000}

  .brandbar{padding:8px 12px;background:transparent;color:#0a0a0a;text-align:center;font-weight:800;letter-spacing:.3px;position:relative;z-index:1;border-top:1px solid var(--border)}

    @media print{
      @page{size:A4 portrait;margin:10mm}
      .actions{display:none}
      .page{padding:0}
      .sheet{border:none;box-shadow:none}
      thead{display:table-header-group}
    }
  </style>
</head>
<body>
  <div class="actions"><button class="btn" onclick="window.print()">প্রিন্ট</button></div>
  <div class="page">
<?php
// Helpers for student fields
$students_list = $students; // keep order
$roll_col = $col_has_roll_no?'roll_no':($col_has_roll_number?'roll_number':'');
$full_name_fn = function($stu){ return $stu['student_name'] ?? trim(($stu['first_name']??'').' '.($stu['last_name']??'')); };

foreach ($students_list as $stu):
  $stu_name = $full_name_fn($stu);
  $stu_roll = $roll_col ? ($stu[$roll_col] ?? '') : ($stu['id'] ?? '');
  $stu_id_show = $col_has_student_id_str ? (string)($stu['student_id'] ?? '') : (string)($stu['id'] ?? '');
  // Photo
  $photoUrl = '';
  if ($col_has_photo) {
    $p = $stu['photo'] ?? '';
    if (!empty($p)) { $photoUrl = '../uploads/students/' . $p; }
  }
  // Section name
  $sec_name = '';
  if ($col_has_section_id && !empty($stu['section_id'])) {
    $sid = intval($stu['section_id']);
    $sec_name = $sections_map[$sid] ?? '';
  } elseif ($col_has_section && !empty($stu['section'])) {
    $sid = intval($stu['section']);
    $sec_name = $sections_map[$sid] ?? '';
  }
  // Division/Group
  $division = '';
  // Prefer explicit student_group like marksheet
  if ($col_has_student_group && !empty($stu['student_group'])) { $division = $stu['student_group']; }
  if ($division==='') { if ($col_has_group) $division = $stu['group'] ?? ($stu['class_group'] ?? $division); }
  if ($division==='') { if ($col_has_department) $division = $stu['department'] ?? ($stu['dept'] ?? $division); }
  if ($division==='') { if ($col_has_division) $division = $stu['division'] ?? $division; }
  if ($division==='') { if ($col_has_stream) $division = $stu['stream'] ?? $division; }

  // Build schedule strictly for this student's assigned subjects, else fallback full
  $assigned_subs = [];
  $sidNum = intval($stu['id'] ?? 0);
  if (!empty($assigned_by_student[$sidNum])) {
    $assigned_subs = array_keys($assigned_by_student[$sidNum]);
  } elseif ($col_has_student_id_str && !empty($stu['student_id']) && !empty($assigned_by_student_str[(string)$stu['student_id']])) {
    $assigned_subs = array_keys($assigned_by_student_str[(string)$stu['student_id']]);
  }
  $sched_for_student = [];
  if (!empty($assigned_subs)) {
    foreach ($schedule as $row) { if (isset($row['subject_id']) && in_array(intval($row['subject_id']), $assigned_subs, true)) { $sched_for_student[] = $row; } }
  } else {
    $sched_for_student = $schedule;
  }
  // Ensure per-student schedule is sorted by date then time
  usort($sched_for_student, function($a,$b){
    $ad = $a['exam_date'] ? strtotime($a['exam_date']) : PHP_INT_MAX;
    $bd = $b['exam_date'] ? strtotime($b['exam_date']) : PHP_INT_MAX;
    if ($ad === $bd) {
      $at = $a['exam_time'] ? strtotime($a['exam_time']) : 0;
      $bt = $b['exam_time'] ? strtotime($b['exam_time']) : 0;
      return $at <=> $bt;
    }
    return $ad <=> $bd;
  });
?>
    <div class="sheet">
      <div class="hdr">
        <div class="logo"><?php if ($institute_logo): ?><img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" onerror="this.remove()"><?php endif; ?></div>
        <div class="brand">
          <h1 class="school-name"><?php echo htmlspecialchars($institute_name); ?></h1>
          <div class="school-meta"><small><?php echo htmlspecialchars($institute_address); ?></small><?php echo $institute_phone!==''?' | মোবাইলঃ '.htmlspecialchars($institute_phone):''; ?></div>
          <div class="school-meta"><strong><?php echo htmlspecialchars($exam_name); ?></strong></div>
          <div class="school-meta"><strong>Attendance Sheet</strong></div>
        </div>
        <div class="stud-photo">
          <?php if (!empty($photoUrl)): ?>
            <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Photo" onerror="this.remove()">
          <?php endif; ?>
        </div>
      </div>
  <table class="info-table" cellpadding="0" cellspacing="0" style="margin:4px 0;width:100%">
          <tbody>
            <tr>
              <td>শিক্ষার্থীর নাম:</td>
              <td><strong><?php echo htmlspecialchars($stu_name); ?></strong></td>
              <td>আইডি:</td>
              <td><strong><?php echo htmlspecialchars($stu_id_show); ?></strong></td>
              <td>রোল নং:</td>
              <td style="text-align: left;"><strong class="bn"><?php echo htmlspecialchars(bn_num($stu_roll)); ?></strong></td>
            </tr>
            <tr>
                <td>শ্রেণি:</td>
                <td><strong><?php echo htmlspecialchars($class_name); ?></strong></td>
                <td>শাখা:</td>
                <td><strong><?php echo htmlspecialchars($sec_name); ?></strong></td>
                <td>গ্রুপ:</td>
                <td><strong><?php echo htmlspecialchars($division); ?></strong></td>
            </tr>
          </tbody>
        </table>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:50px">ক্রম</th>
              <th style="width:110px">তারিখ</th>
              <th class="code">বিষয় কোড</th>
              <th class="name">বিষয়ের নাম</th>
              <th class="sigc" style="width:160px">শিক্ষার্থীর স্বাক্ষর</th>
              <th class="sigc" style="width:180px">কক্ষ পরিদর্শকের স্বাক্ষর</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!empty($sched_for_student)):
            $i=0; foreach ($sched_for_student as $row): $i++; ?>
            <tr>
              <td class="bn"><?php echo bn_num($i); ?></td>
              <td class="bn"><?php echo htmlspecialchars(bn_num(fmt_date($row['exam_date'] ?? null))); ?></td>
              <td class="code"><?php echo htmlspecialchars($row['subject_code'] ?? ''); ?></td>
              <td class="name"><?php echo htmlspecialchars(shortSubjectName($row['subject_name'] ?? '')); ?></td>
              <td class="sigc">&nbsp;</td>
              <td class="sigc">&nbsp;</td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" style="text-align:center;color:#111827">সময়সূচী পাওয়া যায়নি</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div style="display:flex;justify-content:space-between;gap:16px;margin:16px 12px 12px">
        <div style="width:260px;text-align:center">
          <div style="border-top:1px solid #475569;height:0;margin-top:60px"></div>
          <div style="margin-top:6px;font-weight:700">যাচাইকারী</div>
        </div>
        <div style="width:260px;text-align:center">
          <div style="border-top:1px solid #475569;height:0;margin-top:60px"></div>
          <div style="margin-top:6px;font-weight:700">প্রধান শিক্ষক</div>
        </div>
      </div>
      <div class="brandbar">কারিগরি সহযোগীতায়ঃ বাতিঘর কম্পিউটার’স</div>
  </div>
<?php endforeach; ?>
  </div>
</body>
</html>
