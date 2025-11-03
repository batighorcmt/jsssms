<?php
session_start();
// Access: restrict to super_admin/admin by default
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin'])) {
    // header('Location: ../auth/login.php');
    // exit;
}

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

// Exam schedule (by exam)
$schedule = [];
$sched_sql = "SELECT es.subject_id, s.subject_name, es.exam_date, es.exam_time
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
    $sql = "SELECT * FROM students $where ORDER BY ".($col_has_roll_no?'roll_no':'roll_number')." ASC";
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
function fmt_date($d){ return $d ? date('d-m-Y', strtotime($d)) : '-'; }
function fmt_time($t){ return $t ? date('h:i A', strtotime($t)) : '-'; }
function bn_num($v){ $en=['0','1','2','3','4','5','6','7','8','9']; $bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯']; return str_replace($en,$bn,(string)$v); }
$bnDays=['রবিবার','সোমবার','মঙ্গলবার','বুধবার','বৃহস্পতিবার','শুক্রবার','শনিবার'];

// Shorten long subject names for compact layout
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>প্রবেশপত্র (Modern) - <?php echo htmlspecialchars($institute_name); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#0ea5e9; --primary-dark:#0284c7; --ink:#0f172a; --muted:#64748b; --border:#e2e8f0; --bg:#ffffff; }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:'Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .actions{position:sticky;top:0;z-index:10;background:linear-gradient(180deg,#ffffff,rgba(255,255,255,.7));backdrop-filter:saturate(140%) blur(2px);padding:10px 0;text-align:center;border-bottom:1px solid var(--border)}
    .btn{background:var(--primary);border:none;color:#fff;padding:10px 16px;border-radius:8px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(2,132,199,.2)}
    .btn:hover{background:var(--primary-dark)}
  .page{width:100%;max-width:210mm;margin:0 auto;padding:14mm 10mm}
  .card{position:relative;page-break-after:always;background:#fff;border:1px solid var(--border);border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,.06)}
  .wm{position:absolute;inset:0;background-repeat:no-repeat;background-position:center;background-size:75% auto;opacity:.06;pointer-events:none;z-index:0}
    .card:last-child{page-break-after:auto}
  .hdr{display:grid;grid-template-columns:70px 1fr 120px;gap:12px;align-items:center;padding:10px 12px;background:linear-gradient(90deg,#e0f2fe,#ffffff)}
  .logo{width:90px;height:90px;border:none;border-radius:0;display:flex;align-items:center;justify-content:center;background:transparent;overflow:hidden}
    .logo img{max-width:100%;max-height:100%}
    .brand{text-align:center}
    .school-name{font-size:22px;font-weight:800;color:#0b3a67;margin:0}
    .school-meta{margin:2px 0;color:var(--muted);font-weight:600}
    .exam-pill{display:inline-block;margin-top:6px;padding:6px 12px;border-radius:999px;background:#0ea5e91a;color:#0369a1;font-weight:800;border:1px solid #7dd3fc}
  .photo{justify-self:end;width:110px;height:130px;border:2px solid #0ea5e9;border-radius:10px;display:flex;align-items:center;justify-content:center;background:#ffffff;color:#475569;font-weight:600;box-shadow:0 2px 10px rgba(2,132,199,.15);padding:4px}
    .photo img{width:100%;height:100%;object-fit:cover;border-radius:6px}
  .body{padding:10px 12px;position:relative;z-index:1}
  /* Stack student info on top and schedule below */
  .info{display:grid;grid-template-columns:1fr;row-gap:8px}
    .panel{border:1px solid var(--border);border-radius:10px}
  .panel .ttl{padding:6px 10px;background:#f1f5f9;border-bottom:1px solid var(--border);font-weight:800;color:#0f172a}
  /* two-column layout: label/value | label/value (4-column grid) */
  .dl{display:grid;grid-template-columns:120px 1fr 120px 1fr;row-gap:4px;column-gap:8px;padding:8px 10px;align-items:center}
  .dl .k{color:#475569;font-weight:700;line-height:1.2}
  .dl .v{color:#0f172a;line-height:1.2}
  table{width:100%;border-collapse:collapse}
  th,td{padding:4px 6px;border-bottom:1px solid var(--border);text-align:left;line-height:1.2}
  thead th{background:transparent;color:#0f172a;border-bottom:2px solid var(--border)}
    tbody tr:nth-child(odd){background:transparent}
  .foot{display:flex;flex-direction:column;gap:16px;margin-top:12px}
  .sign{border-top:1px solid #475569;margin-top:0;text-align:center;padding-top:0;padding-bottom:0.2in;font-weight:700;color:#0f172a}
    .note{font-size:13px;color:#475569}
  .note ul{list-style:disc;margin:6px 0 0;padding-left:18px;-webkit-column-count:1;column-count:1}
  .brandbar{padding:8px 12px;background:transparent;color:#0f172a;text-align:center;font-weight:800;letter-spacing:.3px;position:relative;z-index:1;border-top:1px solid #bbb}
  .hdr{position:relative;z-index:1}
  /* signatures */
  .sigs{display:flex;flex-direction:row;gap:16px;align-items:flex-end;justify-content:space-between}
  .sig{width:48%;display:flex;flex-direction:column;align-items:center}
  .sig-space{width:100%;height:0.3in;display:flex;align-items:flex-end;justify-content:center}
  .sig-space.hm img{max-height:0.28in;max-width:100%;object-fit:contain;opacity:.9}
  /* Bengali numerals font */
  .bn{font-family:'Noto Sans Bengali','Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    @media print{
      @page{size:A4 portrait;margin:10mm}
      .actions{display:none}
      .page{padding:0}
      .card{box-shadow:none;border:1px solid #bbb}
    }
  </style>
</head>
<body>
  <div class="actions"><button class="btn" onclick="window.print()">প্রিন্ট করুন</button></div>
  <div class="page">
<?php foreach ($students as $stu):
    $full_name = trim(($stu['student_name'] ?? (($stu['first_name'] ?? '') . ' ' . ($stu['last_name'] ?? ''))));
    $father = $stu['father_name'] ?? '';
    $mother = $stu['mother_name'] ?? '';
    $roll = $col_has_roll_no ? ($stu['roll_no'] ?? '') : ($stu['roll_number'] ?? '');
    $section_name = '';
    $sid = null;
    if ($col_has_section_id && !empty($stu['section_id'])) { $sid = intval($stu['section_id']); }
    elseif ($col_has_section && !empty($stu['section'])) { $sid = intval($stu['section']); }
    if (!empty($sid)) {
        $sres = $conn->query("SELECT section_name FROM sections WHERE id = $sid");
        if ($sres && $sres->num_rows > 0) { $section_name = $sres->fetch_assoc()['section_name'] ?? ''; }
    }
    $photo = $col_has_photo ? ($stu['photo'] ?? '') : '';
    $photoUrl = $photo ? ('../uploads/students/' . $photo) : '';

  // Student's assigned subjects (try numeric id first, then string student_id)
  $assigned = [];
  $stuIdNum = intval($stu['id'] ?? 0);
  if (!empty($assigned_by_student[$stuIdNum])) {
    $assigned = array_keys($assigned_by_student[$stuIdNum]);
  } elseif ($col_has_student_id_str && !empty($stu['student_id']) && !empty($assigned_by_student_str[(string)$stu['student_id']])) {
    $assigned = array_keys($assigned_by_student_str[(string)$stu['student_id']]);
  }

    // Build schedule strictly for assigned subjects only
  $sched_for_student = [];
  if (!empty($assigned)) {
    foreach ($schedule as $row) {
      if (isset($row['subject_id']) && in_array(intval($row['subject_id']), $assigned, true)) { $sched_for_student[] = $row; }
    }
  } else {
    // Fallback: show full exam schedule instead of empty state
    $sched_for_student = $schedule;
  }
?>
    <div class="card">
      <div class="wm" style="background-image:url('<?php echo htmlspecialchars($institute_logo); ?>');"></div>
      <div class="hdr">
        <div class="logo">
          <?php if ($institute_logo): ?>
            <img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" onerror="this.remove()">
          <?php else: ?>লোগো<?php endif; ?>
        </div>
        <div class="brand">
          <h1 class="school-name"><?php echo htmlspecialchars($institute_name); ?></h1>
          <div class="school-meta"><?php echo htmlspecialchars($institute_address); ?><?php echo $institute_phone!==''?' | মোবাইলঃ '.htmlspecialchars($institute_phone):''; ?></div>
          <div class="exam-pill"><?php echo htmlspecialchars($exam_name); ?> – প্রবেশপত্র</div>
        </div>
        <div class="photo">
          <?php if ($photoUrl): ?><img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Photo" onerror="this.parentNode.textContent='শিক্ষার্থীর ছবি';this.remove();"><?php else: ?>শিক্ষার্থীর ছবি<?php endif; ?>
        </div>
      </div>

      <div class="body">
        <div class="info">
          <div class="panel">
            <div class="ttl">শিক্ষার্থীর তথ্য</div>
            <div class="dl">
              <div class="k">নাম</div><div class="v"><?php echo htmlspecialchars($full_name); ?></div>
              <div class="k">রোল</div><div class="v"><span class="bn"><?php echo htmlspecialchars(bn_num($roll)); ?></span></div>
              <div class="k">পিতার নাম</div><div class="v"><?php echo htmlspecialchars($father); ?></div>
              <div class="k">মাতার নাম</div><div class="v"><?php echo htmlspecialchars($mother); ?></div>
              <div class="k">শ্রেণি</div><div class="v"><?php echo htmlspecialchars($class_name . ($section_name? (' - '.$section_name) : '')); ?></div>
              <div class="k">আইডি</div><div class="v"><?php echo htmlspecialchars((string)($stu['student_id'] ?? $stu['id'])); ?></div>
            </div>
          </div>
          <div class="panel">
            <div class="ttl">পরীক্ষার সময়সূচী</div>
            <table>
              <thead><tr><th>তারিখ</th><th>বার</th><th>বিষয়</th><th>সময়</th></tr></thead>
              <tbody>
              <?php foreach ($sched_for_student as $row): ?>
                <tr>
                  <td><span class="bn"><?php echo htmlspecialchars(bn_num(fmt_date($row['exam_date'] ?? null))); ?></span></td>
                  <td><?php $d=$row['exam_date']??null; echo $d? htmlspecialchars($bnDays[intval(date('w', strtotime($d)))]):'-'; ?></td>
                  <td><?php echo htmlspecialchars(shortSubjectName($row['subject_name'] ?? '')); ?></td>
                  <td><span class="bn"><?php echo htmlspecialchars(bn_num(fmt_time($row['exam_time'] ?? null))); ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="foot">
          <div class="note">
            <strong>পরীক্ষার নির্দেশাবলী:</strong>
            <ul>
              <li>পরীক্ষা শুরুর ৩০ মিনিট আগে পরীক্ষা কক্ষে উপস্থিত হতে হবে।</li>
              <li>প্রবেশপত্র ছাড়া পরীক্ষা কেন্দ্রে প্রবেশ করা যাবে না।</li>
              <li>পরীক্ষার সময় মোবাইল ফোন বা ইলেকট্রনিক ডিভাইস ব্যবহার করা যাবে না।</li>
              <li>পরীক্ষার হলে নকল করা সম্পূর্ণ নিষিদ্ধ।</li>
              <li>প্রশ্নপত্র ও উত্তরপত্রে নাম, রোল নং ইত্যাদি সঠিকভাবে লিখতে হবে।</li>
            </ul>
          </div>
          <div class="sigs">
            <div class="sig sig-teacher">
              <div class="sig-space"></div>
              <div class="sign">শ্রেণি শিক্ষক</div>
            </div>
            <div class="sig sig-hm">
              <div class="sig-space hm">
                <?php if (file_exists(__DIR__ . '/../assets/hm_sign.jpg')): ?>
                  <img src="<?php echo htmlspecialchars('../assets/hm_sign.jpg'); ?>" alt="Headmaster Signature" onerror="this.style.display='none'">
                <?php endif; ?>
              </div>
              <div class="sign">প্রধান শিক্ষক</div>
            </div>
          </div>
        </div>
      </div>

      <div class="brandbar">কারিগরি সহযোগীতায়ঃ বাতিঘর কম্পিউটার’স</div>
    </div>
<?php endforeach; ?>
  </div>
</body>
</html>
