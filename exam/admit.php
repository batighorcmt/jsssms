<?php
session_start();
// Access control: allow super_admin by default; relax if needed
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin'])) {
    // You can relax this later if teachers should print too
    // header('Location: ../auth/login.php');
    // exit;
}

require_once __DIR__ . '/../config/db.php'; // provides $conn (mysqli)

// Inputs
$exam_id = intval($_GET['exam_id'] ?? 0);
$class_id = intval($_GET['class_id'] ?? 0);
$year     = intval($_GET['year'] ?? 0);
$section_id = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$student_ids_param = trim($_GET['student_ids'] ?? ''); // comma-separated internal students.id list (if you have numeric PK)
$search_roll = trim($_GET['search_roll'] ?? '');

if ($exam_id <= 0) {
    echo '<div style="font-family: sans-serif; padding:20px; color:#b91c1c;">exam_id parameter is required.</div>';
    exit;
}

// Institute info (fallback to marksheet defaults if no table available)
$institute_name = 'Jorepukuria Secondary School';
$institute_address = 'Gangni, Meherpur';
$institute_phone = '';
$institute_logo = '../assets/logo.png';

// Try to load from possible settings table if exists
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

// Exam info (exam_name, class)
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

// Exam schedule for the exam (date/time)
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

// Detect students table column variants
$col_has_class_id = false; $col_has_class = false; $col_has_section_id = false; $col_has_section = false; $col_has_roll_no = false; $col_has_roll_number = false; $col_has_year = false; $col_has_photo = false; $col_has_student_id_str = false;
if ($conn) {
  $cres = $conn->query("SHOW COLUMNS FROM students");
  if ($cres) {
    while ($c = $cres->fetch_assoc()) {
      $n = strtolower($c['Field']);
      if ($n === 'class_id') $col_has_class_id = true;
      if ($n === 'class') $col_has_class = true;
      if ($n === 'section_id') $col_has_section_id = true;
      if ($n === 'section') $col_has_section = true;
      if ($n === 'roll_no') $col_has_roll_no = true;
      if ($n === 'roll_number') $col_has_roll_number = true;
      if ($n === 'year') $col_has_year = true;
      if ($n === 'photo') $col_has_photo = true;
      if ($n === 'student_id') $col_has_student_id_str = true;
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
    // fallback by class/year/section or by roll search
  $conds = []; $params = []; $types = '';
  if ($class_id > 0) {
    if ($col_has_class_id) { $conds[] = 'class_id = ?'; $params[] = $class_id; $types .= 'i'; }
    elseif ($col_has_class) { $conds[] = 'class = ?'; $params[] = $class_id; $types .= 'i'; }
  }
  if ($year > 0 && $col_has_year) { $conds[] = 'year = ?'; $params[] = $year; $types .= 'i'; }
  if ($section_id > 0) {
    if ($col_has_section_id) { $conds[] = 'section_id = ?'; $params[] = $section_id; $types .= 'i'; }
    elseif ($col_has_section) { $conds[] = 'section = ?'; $params[] = $section_id; $types .= 'i'; }
  }
  if ($search_roll !== '') {
    if ($col_has_roll_no) { $conds[] = 'roll_no = ?'; $params[] = $search_roll; $types .= 's'; }
    elseif ($col_has_roll_number) { $conds[] = 'roll_number = ?'; $params[] = $search_roll; $types .= 's'; }
  }
    $where = !empty($conds) ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $sql = "SELECT * FROM students $where ORDER BY roll_no ASC";
  $st = $conn->prepare($sql);
    if (!empty($params)) { $st->bind_param($types, ...$params); }
    $st->execute(); $res = $st->get_result();
    while ($r = $res->fetch_assoc()) { $students[] = $r; }
    $st->close();
}

// Preload student assigned subjects (ids) to filter schedule
$assigned_by_student = [];
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
}

// Helpers
function fmt_date($d){ return $d ? date('d/m/Y', strtotime($d)) : '-'; }
function fmt_time($t){ return $t ? date('H:i', strtotime($t)) : '-'; }
function bn_num($v){ $en=['0','1','2','3','4','5','6','7','8','9']; $bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯']; return str_replace($en,$bn,(string)$v); }

?><!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>প্রবেশপত্র (Admit Card) - <?php echo htmlspecialchars($institute_name); ?></title>
  <link href="https://fonts.maateen.me/solaiman-lipi/font.css" rel="stylesheet">
  <style>
    :root { --primary:#1e5799; --accent:#ff9800; --muted:#6b7280; --border:#e5e7eb; }
    * { box-sizing: border-box; }
    body { margin:0; background:#f3f4f6; color:#111827; font-family:'SolaimanLipi', system-ui, -apple-system, Segoe UI, Roboto, sans-serif; }
    .actions { text-align:center; margin:16px 0 24px; }
    .print-btn { background:var(--primary); color:#fff; border:none; border-radius:6px; padding:10px 18px; cursor:pointer; font-size:14px; }
    .print-btn:hover { background:#154274; }
    .sheet { width:100%; max-width:148mm; margin:0 auto; padding:8mm; }
    .admit-card { background:#fff; border:1px solid var(--border); border-radius:8px; padding:8mm 8mm 6mm; margin-bottom:6mm; box-shadow:0 2px 6px rgba(0,0,0,0.04); min-height:135mm; display:flex; flex-direction:column; justify-content:space-between; page-break-inside:avoid; }
    .card-head { display:grid; grid-template-columns:28mm 1fr 30mm; align-items:center; gap:8mm; margin-bottom:4mm; }
    .logo-box { width:28mm; height:28mm; border:1px solid var(--border); border-radius:6px; display:flex; align-items:center; justify-content:center; overflow:hidden; background:#fff; }
    .logo-box img { max-width:100%; max-height:100%; }
    .school-info { text-align:center; }
    .school-name { font-weight:700; font-size:18pt; line-height:1.2; color:var(--primary); }
    .school-meta { font-size:10pt; color:var(--muted); margin-top:2mm; }
    .photo-box { width:30mm; height:36mm; border:1px dashed var(--border); border-radius:4px; overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:9pt; color:#9ca3af; background:#fafafa; }
    .photo-box img { width:100%; height:100%; object-fit:cover; }
    .exam-title { text-align:center; font-weight:700; font-size:14pt; color:#0f172a; padding:3mm 0; margin-bottom:2mm; border-top:2px solid var(--border); border-bottom:2px solid var(--border); }
    .grid-2 { display:grid; grid-template-columns:30% 70%; gap:6mm; align-items:start; }
    .section { border:1px solid var(--border); border-radius:6px; padding:4mm; }
    .section-title { font-weight:700; color:var(--primary); margin-bottom:3mm; font-size:11pt; }
    .info-list { display:grid; grid-template-columns:1fr 1fr; gap:2mm 6mm; font-size:10pt; }
    .info-item { display:flex; gap:3mm; }
    .label { color:#374151; font-weight:600; min-width:28mm; }
    .value { color:#111827; }
    table { border-collapse:collapse; width:100%; font-size:10pt; }
    th,td { border-bottom:1px solid var(--border); padding:6px 8px; text-align:left; }
    th { background:#f9fafb; color:#111827; font-weight:700; }
    .instructions { font-size:10pt; }
    .instructions ul { margin:2mm 0 0 5mm; }
    .sign-row { display:flex; justify-content:space-between; gap:10mm; margin-top:6mm; }
    .sign-col { flex:1; text-align:center; }
    .sign-line { margin:12mm 0 2mm; border-top:1px solid #4b5563; }
    .sign-label { font-weight:600; color:#111827; }
    .branding { text-align:center; font-size:9pt; color:#6b7280; margin-top:0; }
    @media print { @page { size: A5 portrait; margin: 8mm; } body { background:#fff; } .actions{ display:none; } .sheet{ padding:0; max-width:148mm; width:148mm; } .admit-card{ box-shadow:none; } }
  </style>
</head>
<body>
  <div class="actions"><button class="print-btn" onclick="window.print()">প্রিন্ট করুন</button></div>
  <div class="sheet">
<?php
$bnDays = ['রবিবার','সোমবার','মঙ্গলবার','বুধবার','বৃহস্পতিবার','শুক্রবার','শনিবার'];
foreach ($students as $stu):
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

    // Assigned subject ids for this student
    $assigned = [];
    if (!empty($assigned_by_student[intval($stu['id'] ?? 0)])) { $assigned = array_keys($assigned_by_student[intval($stu['id'])]); }

    // Filter schedule
    $sched_for_student = [];
    if (!empty($schedule)) {
        if (!empty($assigned)) {
            foreach ($schedule as $row) {
                if (isset($row['subject_id']) && in_array(intval($row['subject_id']), $assigned, true)) { $sched_for_student[] = $row; }
            }
        } else {
            $sched_for_student = $schedule; // fallback: show all subjects
        }
    }
?>
    <div class="admit-card">
      <div>
        <div class="card-head">
          <div class="logo-box">
            <?php if ($institute_logo && file_exists(__DIR__ . '/../' . ltrim($institute_logo, './'))) : ?>
              <img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" onerror="this.remove()">
            <?php else: ?>
              <span style="font-size:10pt;color:#9ca3af;">লোগো</span>
            <?php endif; ?>
          </div>
          <div class="school-info">
            <div class="school-name"><?php echo htmlspecialchars($institute_name); ?></div>
            <div class="school-meta"><?php echo htmlspecialchars($institute_address); ?></div>
            <?php if ($institute_phone !== ''): ?><div class="school-meta">মোবাইলঃ <?php echo htmlspecialchars(bn_num($institute_phone)); ?></div><?php endif; ?>
          </div>
          <div class="photo-box">
            <?php if ($photoUrl): ?>
              <img src="<?php echo htmlspecialchars($photoUrl); ?>" alt="Photo" onerror="this.parentNode.textContent='শিক্ষার্থীর ছবি';this.remove();">
            <?php else: ?>শিক্ষার্থীর ছবি<?php endif; ?>
          </div>
        </div>
        <div class="exam-title"><?php echo htmlspecialchars($exam_name); ?> - প্রবেশপত্র</div>

        <div class="grid-2">
          <div class="section">
            <div class="section-title">শিক্ষার্থীর তথ্য</div>
            <div class="info-list">
              <div class="info-item"><div class="label">নাম</div><div class="value"><?php echo htmlspecialchars($full_name); ?></div></div>
              <div class="info-item"><div class="label">রোল</div><div class="value"><?php echo htmlspecialchars(bn_num($roll)); ?></div></div>
              <div class="info-item"><div class="label">পিতার নাম</div><div class="value"><?php echo htmlspecialchars($father); ?></div></div>
              <div class="info-item"><div class="label">মাতার নাম</div><div class="value"><?php echo htmlspecialchars($mother); ?></div></div>
              <div class="info-item"><div class="label">শ্রেণি</div><div class="value"><?php echo htmlspecialchars($class_name . ($section_name? (' - '.$section_name) : '')); ?></div></div>
              <div class="info-item"><div class="label">আইডি</div><div class="value"><?php echo htmlspecialchars((string)($stu['student_id'] ?? $stu['id'])); ?></div></div>
            </div>
          </div>
          <div class="section">
            <div class="section-title">পরীক্ষার সময়সূচী</div>
            <table>
              <thead><tr><th>তারিখ</th><th>বার</th><th>বিষয়</th><th>সময়</th></tr></thead>
              <tbody>
              <?php if (empty($sched_for_student)): ?>
                <tr><td colspan="4">কোনো সময়সূচী নির্ধারিত নেই</td></tr>
              <?php else: foreach ($sched_for_student as $row): ?>
                <tr>
                  <td><?php echo htmlspecialchars(bn_num(fmt_date($row['exam_date'] ?? null))); ?></td>
                  <td><?php
                    $d = $row['exam_date'] ?? null;
                    if ($d) { $wd = intval(date('w', strtotime($d))); echo htmlspecialchars($bnDays[$wd] ?? ''); } else { echo '-'; }
                  ?></td>
                  <td><?php echo htmlspecialchars($row['subject_name'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars(bn_num(fmt_time($row['exam_time'] ?? null))); ?></td>
                </tr>
              <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="section instructions" style="margin-top: 4mm;">
          <div class="section-title">পরীক্ষার নির্দেশাবলী</div>
          <ul>
            <li>পরীক্ষা শুরুর ৩০ মিনিট আগে পরীক্ষা কক্ষে উপস্থিত হতে হবে।</li>
            <li>প্রবেশপত্র ছাড়া পরীক্ষা কেন্দ্রে প্রবেশ করা যাবে না।</li>
            <li>পরীক্ষার সময় মোবাইল ফোন বা ইলেকট্রনিক ডিভাইস ব্যবহার করা যাবে না।</li>
            <li>পরীক্ষার হলে নকল করা সম্পূর্ণ নিষিদ্ধ।</li>
            <li>প্রশ্নপত্র ও উত্তরপত্রে নাম, রোল নং ইত্যাদি সঠিকভাবে লিখতে হবে।</li>
          </ul>
        </div>
      </div>

      <div>
        <div class="sign-row">
          <div class="sign-col">
            <div class="sign-line"></div>
            <div class="sign-label">শ্রেণি শিক্ষক</div>
          </div>
          <div class="sign-col">
            <div class="sign-line"></div>
            <div class="sign-label">প্রধান শিক্ষক</div>
          </div>
        </div>
        <div class="branding">কারিগরি সহযোগীতায়ঃ <strong>বাতিঘর কম্পিউটার’স</strong></div>
      </div>
    </div>
<?php endforeach; ?>
  </div>
</body>
</html>
