<?php
session_start();
// Restrict if needed
// if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','admin','teacher'])) { header('Location: ../auth/login.php'); exit; }

require_once __DIR__ . '/../config/db.php'; // mysqli $conn

// Params
$class_id = intval($_GET['class_id'] ?? 0);
$section_id = intval($_GET['section_id'] ?? 0);
$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));

// Institute info
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

// Load classes and sections
$classes = [];
$clsq = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
if ($clsq) { while ($r = $clsq->fetch_assoc()) { $classes[] = $r; } }
$sections = [];
$secq = $conn->query("SELECT id, section_name FROM sections ORDER BY section_name ASC");
if ($secq) { while ($r = $secq->fetch_assoc()) { $sections[] = $r; } }

// Detect student columns
$col_has_class_id=false;$col_has_class=false;$col_has_section_id=false;$col_has_section=false;$col_has_roll_no=false;$col_has_roll_number=false;$col_has_student_name=false;
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
      if ($n==='student_name' || $n==='first_name') $col_has_student_name=true;
    }
  }
}

// Fetch students (by class/section if provided)
$students = [];
{
  $conds=[];$params=[];$types='';
  if ($class_id>0){ if($col_has_class_id){$conds[]='class_id=?';$params[]=$class_id;$types.='i';}elseif($col_has_class){$conds[]='class=?';$params[]=$class_id;$types.='i';}}
  if ($section_id>0){ if($col_has_section_id){$conds[]='section_id=?';$params[]=$section_id;$types.='i';}elseif($col_has_section){$conds[]='section=?';$params[]=$section_id;$types.='i';}}
  $where = !empty($conds)?('WHERE '.implode(' AND ',$conds)) : '';
  $orderRoll = $col_has_roll_no?'roll_no':($col_has_roll_number?'roll_number':'id');
  $sql = "SELECT * FROM students $where ORDER BY $orderRoll ASC";
  $st = $conn->prepare($sql);
  if (!empty($params)) { $st->bind_param($types, ...$params); }
  $st->execute(); $res = $st->get_result();
  while ($r = $res->fetch_assoc()) { $students[] = $r; }
  $st->close();
}

function bn_num($v){ $en=['0','1','2','3','4','5','6','7','8','9']; $bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯']; return str_replace($en,$bn,(string)$v); }
$month_names = ['','জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর'];
$bnDays=['রবিবার','সোমবার','মঙ্গলবার','বুধবার','বৃহস্পতিবার','শুক্রবার','শনিবার'];

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, max(1,min(12,$month)), $year);

?><!DOCTYPE html>
<html lang="bn">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>উপস্থিতি শীট - <?php echo htmlspecialchars($institute_name); ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --primary:#0ea5e9; --border:#e2e8f0; --ink:#0f172a; --muted:#475569; --bg:#ffffff; }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg);color:var(--ink);font-family:'Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .actions{position:sticky;top:0;z-index:10;background:#fff;backdrop-filter:saturate(140%) blur(2px);padding:10px;text-align:center;border-bottom:1px solid var(--border)}
    .btn{background:var(--primary);border:none;color:#fff;padding:8px 14px;border-radius:8px;font-weight:700;cursor:pointer}
    .page{width:100%;max-width:297mm;margin:0 auto;padding:10mm}
    .card{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
    .hdr{display:grid;grid-template-columns:80px 1fr 280px;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border)}
    .logo{width:80px;height:80px;display:flex;align-items:center;justify-content:center}
    .logo img{max-width:100%;max-height:100%}
    .brand{text-align:center}
    .school-name{font-size:20px;font-weight:800;color:#0b3a67;margin:0}
    .school-meta{margin:2px 0;color:var(--muted);font-weight:600}
    .filters{display:flex;gap:8px;justify-content:flex-end;align-items:center}
    .filters select,.filters input{padding:6px 8px;border:1px solid var(--border);border-radius:8px}

    table{width:100%;border-collapse:collapse}
    th,td{border:1px solid var(--border);padding:4px 6px;text-align:center;font-size:12px}
    thead th{background:#f8fafc}
    tbody td.name, thead th.name{text-align:left}
    .bn{font-family:'Noto Sans Bengali','Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif}
    .weekend{background:#f5f5f5}

    .foot{display:flex;justify-content:space-between;gap:16px;margin:14px 12px 16px}
    .sig{width:250px;text-align:center}
    .sig .line{border-top:1px solid #475569;height:0;margin-top:30px}
    .sig .label{margin-top:6px;font-weight:700}

    @media print{
      @page{size:A4 landscape;margin:10mm}
      .actions{display:none}
      .page{padding:0}
      .card{border:none}
    }
  </style>
</head>
<body>
  <div class="actions">
    <button class="btn" onclick="window.print()">প্রিন্ট</button>
  </div>
  <div class="page">
    <div class="card">
      <div class="hdr">
        <div class="logo">
          <?php if ($institute_logo): ?><img src="<?php echo htmlspecialchars($institute_logo); ?>" alt="Logo" onerror="this.remove()"><?php endif; ?>
        </div>
        <div class="brand">
          <h1 class="school-name"><?php echo htmlspecialchars($institute_name); ?></h1>
          <div class="school-meta"><?php echo htmlspecialchars($institute_address); ?><?php echo $institute_phone!==''?' | মোবাইলঃ '.htmlspecialchars($institute_phone):''; ?></div>
          <div class="school-meta"><strong>উপস্থিতি শীট</strong> — <span class="bn"><?php echo htmlspecialchars($month_names[$month] . ' ' . bn_num($year)); ?></span></div>
        </div>
        <form class="filters" method="get">
          <input type="hidden" name="year" value="<?php echo htmlspecialchars($year); ?>">
          <label>
            শ্রেণি
            <select name="class_id" onchange="this.form.submit()">
              <option value="0">সকল</option>
              <?php foreach ($classes as $c): ?>
                <option value="<?php echo intval($c['id']); ?>" <?php echo $class_id==intval($c['id'])?'selected':''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            শাখা
            <select name="section_id" onchange="this.form.submit()">
              <option value="0">সকল</option>
              <?php foreach ($sections as $s): ?>
                <option value="<?php echo intval($s['id']); ?>" <?php echo $section_id==intval($s['id'])?'selected':''; ?>><?php echo htmlspecialchars($s['section_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>
            মাস
            <select name="month" onchange="this.form.submit()">
              <?php for($m=1;$m<=12;$m++): ?>
                <option value="<?php echo $m; ?>" <?php echo $m==$month?'selected':''; ?>><?php echo htmlspecialchars($month_names[$m]); ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            বছর
            <input type="number" name="year" value="<?php echo htmlspecialchars($year); ?>" style="width:90px" onchange="this.form.submit()" />
          </label>
        </form>
      </div>

      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:50px">ক্রম</th>
              <th style="width:70px">রোল</th>
              <th class="name">শিক্ষার্থীর নাম</th>
              <?php for($d=1;$d<=$daysInMonth;$d++): $w = (int)date('w', strtotime($year.'-'.$month.'-'.$d)); ?>
                <th class="bn <?php echo ($w==5||$w==6)?'weekend':''; ?>" style="width:22px"><?php echo bn_num($d); ?></th>
              <?php endfor; ?>
              <th style="width:60px">মোট</th>
            </tr>
          </thead>
          <tbody>
            <?php $i=0; foreach ($students as $stu): $i++; $roll = $col_has_roll_no?($stu['roll_no']??''):($col_has_roll_number?($stu['roll_number']??''):''); $name = $stu['student_name'] ?? trim(($stu['first_name']??'').' '.($stu['last_name']??'')); ?>
              <tr>
                <td class="bn"><?php echo bn_num($i); ?></td>
                <td class="bn"><?php echo htmlspecialchars(bn_num($roll)); ?></td>
                <td class="name"><?php echo htmlspecialchars($name); ?></td>
                <?php for($d=1;$d<=$daysInMonth;$d++): $w = (int)date('w', strtotime($year.'-'.$month.'-'.$d)); ?>
                  <td class="<?php echo ($w==5||$w==6)?'weekend':''; ?>">&nbsp;</td>
                <?php endfor; ?>
                <td>&nbsp;</td>
              </tr>
            <?php endforeach; if (empty($students)): ?>
              <tr><td colspan="<?php echo 4+$daysInMonth; ?>" style="text-align:center;color:#64748b">শিক্ষার্থী পাওয়া যায়নি</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="foot">
        <div class="sig">
          <div class="line"></div>
          <div class="label">শ্রেণি শিক্ষক</div>
        </div>
        <div class="sig">
          <div class="line"></div>
          <div class="label">প্রধান শিক্ষক</div>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
