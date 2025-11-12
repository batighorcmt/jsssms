<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') { header("Location: " . BASE_URL . "auth/login.php"); exit(); }
include '../config/db.php';

// Optional debug toggle: add ?debug=1 to see errors
if (isset($_GET['debug']) && $_GET['debug']=='1'){
    error_reporting(E_ALL);
    ini_set('display_errors','1');
    // Robust fatal error surface
    set_error_handler(function($errno,$errstr,$errfile,$errline){
        echo "<pre style=\"background:#fee;border:1px solid #f88;padding:8px;\">PHP Error [$errno]: $errstr\n$errfile:$errline</pre>";
        return false; // allow normal handling too
    });
    register_shutdown_function(function(){
        $e = error_get_last();
        if ($e) {
            echo "<pre style=\"background:#fee;border:2px solid #d00;padding:10px;\">FATAL: {$e['message']}\n{$e['file']}:{$e['line']}</pre>";
        }
    });
}

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$room_id = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 0;
if ($plan_id<=0 || $room_id<=0){ echo 'Invalid request'; exit; }

// Load plan and room
$plan = null; $room = null;
$rp = $conn->query("SELECT * FROM seat_plans WHERE id=".$plan_id." LIMIT 1");
if ($rp && $rp->num_rows) $plan = $rp->fetch_assoc();
$rr = $conn->query("SELECT * FROM seat_plan_rooms WHERE id=".$room_id." AND plan_id=".$plan_id);
if ($rr && $rr->num_rows) $room = $rr->fetch_assoc();
if (!$plan || !$room){ echo 'Plan/Room not found'; if(isset($_GET['debug'])){ echo "<pre>plan_id=$plan_id room_id=$room_id</pre>";} exit; }

// Prepare shift label for header (right side)
$shiftName = (string)($plan['shift'] ?? 'Morning');
$sn = strtolower(trim($shiftName));
if (strpos($sn,'even') !== false) { $shiftLabel = 'Evening Shift'; }
elseif (strpos($sn,'morn') !== false) { $shiftLabel = 'Morning Shift'; }
else { $shiftLabel = ucwords($shiftName).' Shift'; }

// Load allocations + try to enrich with students info (support both id and student_id keys)
$alloc = [];
// Detect optional/migrated columns safely to avoid unknown column fatals
$hasStudentGroup = false; $hasLegacyGroup = false; $hasOptionalCol = false;
if ($chk = $conn->query("SHOW COLUMNS FROM students LIKE 'student_group'")) { $hasStudentGroup = ($chk->num_rows>0); }
if ($chk = $conn->query("SHOW COLUMNS FROM students LIKE 'group'")) { $hasLegacyGroup = ($chk->num_rows>0); }
if ($chk = $conn->query("SHOW COLUMNS FROM students LIKE 'optional_subject_id'")) { $hasOptionalCol = ($chk->num_rows>0); }

$selGroupExpr = 'NULL AS student_group';
if ($hasStudentGroup) { $selGroupExpr = 'COALESCE(s1.student_group, s2.student_group) AS student_group'; }
elseif ($hasLegacyGroup) { $selGroupExpr = 'COALESCE(s1.`group`, s2.`group`) AS student_group'; }

$selOptionalExpr = 'NULL AS optional_subject_id';
if ($hasOptionalCol) { $selOptionalExpr = 'COALESCE(s1.optional_subject_id, s2.optional_subject_id) AS optional_subject_id'; }

$sql = "SELECT a.col_no, a.bench_no, a.position, a.student_id,
        COALESCE(s1.student_name, s2.student_name) AS student_name,
        COALESCE(s1.roll_no, s2.roll_no) AS roll_no,
        COALESCE(c1.class_name, c2.class_name) AS class_name,
        COALESCE(s1.class_id, s2.class_id) AS class_id,
        $selGroupExpr,
        $selOptionalExpr,
        COALESCE(s1.student_id, s2.student_id) AS sid_str,
        COALESCE(s1.id, s2.id) AS sid_num,
        COALESCE(s1.photo, s2.photo) AS photo
    FROM seat_plan_allocations a
    LEFT JOIN students s1 ON s1.student_id=a.student_id
    LEFT JOIN students s2 ON s2.id=a.student_id
    LEFT JOIN classes c1 ON c1.id = s1.class_id
    LEFT JOIN classes c2 ON c2.id = s2.class_id
    WHERE a.room_id=".$room_id;
$rs = $conn->query($sql);
if ($rs){
    while($r=$rs->fetch_assoc()){
        $c = (int)$r['col_no']; $b=(int)$r['bench_no']; $p=$r['position'];
        if(!isset($alloc[$c])) $alloc[$c]=[];
        if(!isset($alloc[$c][$b])) $alloc[$c][$b]=['L'=>null,'R'=>null];
        $alloc[$c][$b][$p]=$r;
    }
} else {
    if (isset($_GET['debug'])){
        echo "<pre style=\"background:#fee;border:1px solid #f88;padding:8px;\">SQL Error (alloc load): ".$conn->error."\nQuery: $sql</pre>";
    } else { echo 'Data load error.'; }
    exit;
}

$colCounts = [1=>(int)$room['col1_benches'], 2=>(int)$room['col2_benches'], 3=>(int)$room['col3_benches']];
function normalizeGroupName($name){
    $n = strtolower(trim($name ?? ''));
    if ($n==='') return '';
    if ($n==='science' || $n==='science group' || $n==='general science' || $n==='বিজ্ঞান') return 'Science';
    if ($n==='humanities' || $n==='arts' || $n==='মানবিক') return 'Humanities';
    if ($n==='business' || $n==='business studies' || $n==='commerce' || $n==='বাণিজ্য') return 'Business Studies';
    return ucfirst($n);
}
function detectGradeFromClass($className){
    $c = trim((string)$className);
    if ($c==='') return null;
    $lc = strtolower($c);
    if (strpos($lc,'six')!==false) return 6;
    if (strpos($lc,'seven')!==false) return 7;
    if (strpos($lc,'eight')!==false) return 8;
    if (strpos($lc,'nine')!==false) return 9;
    if (strpos($lc,'ten')!==false) return 10;
    if (preg_match('/ষষ্ঠ/u',$c)) return 6;
    if (preg_match('/সপ্তম/u',$c)) return 7;
    if (preg_match('/অষ্টম/u',$c)) return 8;
    if (preg_match('/নবম/u',$c)) return 9;
    if (preg_match('/দশম/u',$c)) return 10;
    if (preg_match('/\b(6|৬)\b/u', $c)) return 6;
    if (preg_match('/\b(7|৭)\b/u', $c)) return 7;
    if (preg_match('/\b(8|৮)\b/u', $c)) return 8;
    if (preg_match('/\b(9|৯)\b/u', $c)) return 9;
    if (preg_match('/\b(10|১০)\b/u', $c)) return 10;
    return null;
}
function seatCell($data, $pos){
    if (!$data) return '<div class="seat empty">--</div>';
    $name = htmlspecialchars($data['student_name'] ?? '');
    $roll = htmlspecialchars($data['roll_no'] ?? '');
    $clsRaw = $data['class_name'] ?? '';
    $cls = htmlspecialchars($clsRaw);
    $grpRaw = normalizeGroupName($data['student_group'] ?? '');
    $grade = detectGradeFromClass($clsRaw);
    $displayClass = $cls;
    if ($grade===9 || $grade===10) {
        if ($grpRaw!=='') { $displayClass .= ' - '.htmlspecialchars(strtoupper(substr($grpRaw,0,1))); }
    }
    $photoTag = '';
    if (!empty($data['photo'])){
        $base = defined('BASE_URL') ? BASE_URL : '../';
        $src = htmlspecialchars($base."uploads/students/".$data['photo']);
        $photoTag = '<img src="'.$src.'" alt="photo">';
    }
    $posClass = ($pos==='L')?'left':'right';
    $gradeClass = $grade?(' grade-'.(int)$grade):'';
    return '<div class="seat '.$posClass.$gradeClass.'">'.$photoTag.'<div class="roll">'.$roll.'</div><div class="name">'.$name.'</div><div class="class">'.$displayClass.'</div></div>';
}
// Compute total assigned for this room
$totalAssigned = 0;
for ($cc=1; $cc<=3; $cc++){
    if (!isset($alloc[$cc])) continue;
    foreach ($alloc[$cc] as $bb => $row){
        if (!empty($row['L'])) $totalAssigned++;
        if (!empty($row['R'])) $totalAssigned++;
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Seat Plan — Print</title>
    <style>
        @media print {
            @page { size: A4; margin: 8mm; }
            .page { page-break-after: always; }
            body { margin: 0; }
            .print-toolbar{display:none;}
            .no-print, .no-print * { display: none !important; }
            /* Keep three columns side-by-side in print */
            .seat-area{ display: grid !important; grid-template-columns: repeat(3, minmax(0, 1fr)); column-gap: 8mm; }
            .column{ width:auto !important; min-width: 0 !important; }
            /* Avoid cutting a bench across pages */
            .bench{ break-inside: avoid; page-break-inside: avoid; }
            /* Ensure space for fixed footer on paper */
            .page{ padding-bottom: 22mm !important; }
            .fixed-footer{ position: fixed; left: 0; right: 0; bottom: 0; }
        }
        body { font-family: 'Noto Sans', 'Arial', sans-serif; padding: 20px; color:#000; }
        .page { border: 1px solid #e3e3e3; padding: 18px; margin-bottom: 20px; border-radius: 6px; padding-bottom: 70px; }
    .header { text-align: center; margin-bottom: 8px; }
    .header-brand{ display:flex; align-items:center; justify-content:space-between; gap:15px; width:100%; }
    .brand-left{ display:flex; align-items:center; gap:12px; }
    .brand-text{ display:flex; flex-direction:column; justify-content:center; text-align:center; flex:1; }
    .shift-box{ border:2px solid #333; padding:6px 12px; font-weight:800; background:#fff7a8; color:#000; border-radius:6px; white-space:nowrap; align-self:center; }
        .school-name { font-size: 28px; font-weight: 800; line-height:1.1; margin: 2px 0; }
        .school-address { font-size: 12px; color: #444; line-height:1.2; margin: 2px 0; }
        .exam-title { text-align: center; margin: 6px 0 4px; line-height:1.2; }
        .room-number { font-weight: 800; font-size: 22px; text-align: center; margin: 6px 0 8px; }
    .school-logo { display:block; width:72px; height:72px; object-fit:contain; object-position:center center; margin:0; align-self:center; }
    .seat-area { display: flex; gap: 12px; align-items: flex-start; justify-content: center; flex-wrap: nowrap; }
    .column { flex: 0 0 33.333%; min-width: 0; }
    *, *::before, *::after { box-sizing: border-box; }
        .col-title { text-align: center; font-weight: 700; margin-bottom: 6px; }
        .bench { border: 1px dashed #bbb; padding: 8px; margin-bottom: 8px; border-radius: 6px; display: flex; justify-content: space-between; align-items: center; }
        /* Seat cell redesigned to keep photo inside border and beside roll */
        .seat { width: 48%; padding: 6px 6px; font-size: 13px; min-height: 40px; text-align: center;
            display: grid; align-items: center; column-gap: 6px; row-gap: 2px;
            grid-template-columns: auto 1fr;
            grid-template-areas: "img roll" "name name" "class class";
        }
        .seat.right{
            grid-template-columns: 1fr auto;
            grid-template-areas: "roll img" "name name" "class class";
        }
        .seat img { grid-area: img; width: 40px; height: 40px; border-radius: 50%; object-fit: cover; position: static; }
        .seat .roll { grid-area: roll; font-size: 24px; font-weight: 900; color: #b00; line-height:1; }
        .seat .name { grid-area: name; font-size: 12px; font-weight: 600; line-height:1.1; }
        .seat .class { grid-area: class; font-size: 16px; color: #333; line-height:1.1; }
        .stats { margin-top: 14px; border-top: 1px solid #eee; padding-top: 10px; font-size: 13px; }
        .stats h5{ margin:6px 0; }
        .stats ul{ margin:0; padding-left:18px; }
        @media (max-width: 800px) { .seat-area { flex-direction: column; } .column { min-width: auto; } }

        /* Class color coding */
        .seat.grade-10 .roll, .seat.grade-10 .name, .seat.grade-10 .class { color: #0a8a0a; } /* Green */
        .seat.grade-9 .roll, .seat.grade-9 .name, .seat.grade-9 .class { color: #0b2e7a; } /* Dark blue */
        .seat.grade-8 .roll, .seat.grade-8 .name, .seat.grade-8 .class { color: #c40000; } /* Red */
        .seat.grade-7 .roll, .seat.grade-7 .name, .seat.grade-7 .class { color: #800000; } /* Maroon */
        .seat.grade-6 .roll, .seat.grade-6 .name, .seat.grade-6 .class { color: #000000; } /* Black */

        /* Stats layout */
        .stats-grid{ display:grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .stat-col{ border:1px solid #e6e6e6; border-radius:6px; padding:8px; }
        .badge{ display:inline-block; padding:2px 6px; border-radius:4px; font-weight:700; }
        .badge.grade-10{ color:#0a8a0a; border:1px solid #0a8a0a; }
        .badge.grade-9{ color:#0b2e7a; border:1px solid #0b2e7a; }
        .badge.grade-8{ color:#c40000; border:1px solid #c40000; }
        .badge.grade-7{ color:#800000; border:1px solid #800000; }
        .badge.grade-6{ color:#000; border:1px solid #000; }

        /* Fixed highlighted footer (both screen and print) */
        .fixed-footer{
            position: fixed;
            left: 0; right: 0; bottom: 0;
            text-align: center;
            font-size: 12px; font-weight: 800;
            background: #fff7a8; /* light highlight */
            color: #000;
            padding: 8px 10px;
            border-top: 2px solid #333;
            z-index: 9999;
        }
    </style>
</head>
<body>
    <div class="print-toolbar no-print" style="display:flex; gap:8px; align-items:center;">
        <button onclick="window.print()">Print</button>
        <a href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>" style="text-decoration:none; border:1px solid #ccc; padding:6px 10px; border-radius:4px; color:#333;">Back to Rooms</a>
        <a href="seat_plan.php" style="text-decoration:none; border:1px solid #ccc; padding:6px 10px; border-radius:4px; color:#333;">All Seat Plans</a>
    </div>
    <div class="page">
        <div class="header">
            <div class="header-brand">
                <div class="brand-left">
                    <?php if (!empty($institute_logo)): $logoUrl = (defined('BASE_URL') ? BASE_URL : '/') . ltrim($institute_logo,'/'); ?>
                        <img class="school-logo" src="<?= htmlspecialchars($logoUrl) ?>" alt="logo">
                    <?php endif; ?>
                </div>
                <div class="brand-text">
                    <div class="school-name"><?= htmlspecialchars($institute_name ?? 'Institute Name') ?></div>
                    <div class="school-address"><?= htmlspecialchars($institute_address ?? '') ?></div>
                </div>
                <div class="shift-box"><?= htmlspecialchars($shiftLabel) ?></div>
            </div>
        </div>
        <div class="exam-title">
            <div><strong><?= htmlspecialchars($plan['plan_name'] ?? 'Seat Plan') ?></strong></div>
            <div style="margin-top:6px; font-size:16px;">Seat Plan</div>
        </div>
    <div class="room-number">Room: <?= htmlspecialchars($room['room_no']) ?></div>

        <div class="seat-area">
            <?php $colNames = [1=>'Left Column', 2=>'Middle Column', 3=>'Right Column'];
            for($c=1;$c<=3;$c++): $maxB=$colCounts[$c]; ?>
            <div class="column">
                <div class="col-title"><?= $colNames[$c] ?></div>
                <?php for($b=1; $b<=$maxB; $b++): $row=$alloc[$c][$b] ?? ['L'=>null,'R'=>null]; ?>
                    <div class="bench">
                        <?= seatCell($row['L'], 'L'); ?>
                        <?= seatCell($row['R'], 'R'); ?>
                    </div>
                <?php endfor; ?>
            </div>
            <?php endfor; ?>
        </div>

        <?php
        // Build statistics
        $classCounts = [];
        $groupCounts = [];
        $optionalCounts = [];
        $has9or10 = false;
        $assignedSidStr = [];
        if (!empty($alloc)){
            for ($cc=1;$cc<=3;$cc++){
                if (empty($alloc[$cc])) continue;
                foreach ($alloc[$cc] as $bb=>$row){
                    foreach (['L','R'] as $pp){
                        $d = $row[$pp] ?? null; if(!$d) continue;
                        $clsName = (string)($d['class_name'] ?? '');
                        $grade = detectGradeFromClass($clsName);
                        $k = $clsName;
                        if ($k!=='') { $classCounts[$k] = ($classCounts[$k] ?? 0) + 1; }
                        if ($grade===9 || $grade===10) {
                            $has9or10 = true;
                            $grp = normalizeGroupName($d['student_group'] ?? '');
                            if ($grp!=='') { $groupCounts[$grp] = ($groupCounts[$grp] ?? 0) + 1; }
                            $sidStr = (string)($d['sid_str'] ?? '');
                            if ($sidStr!=='') { $assignedSidStr[$sidStr] = true; }
                        }
                    }
                }
            }
        }
        // Optional subjects for 9/10 using optional_subject_id if present, else derive from student_subjects
        if ($has9or10 && !empty($assignedSidStr)){
            // Verify required tables exist before querying optional/group maps
            $tableExists = function($name) use ($conn){
                $name = mysqli_real_escape_string($conn, $name);
                $q = $conn->query("SHOW TABLES LIKE '$name'");
                return ($q && $q->num_rows > 0);
            };
            $hasSubjects = $tableExists('subjects');
            $hasStuSubs  = $tableExists('student_subjects');
            $hasMap      = $tableExists('subject_group_map');
            
            $sidList = array_map(function($s) use ($conn){ return "'".mysqli_real_escape_string($conn, $s)."'"; }, array_keys($assignedSidStr));
            $sidIn = implode(',', $sidList);
            // Check optional_subject_id column (if present)
            $colCheck = $conn->query("SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
            $optMap = [];
            if ($colCheck && $colCheck->num_rows>0 && $hasSubjects){
                $q1 = $conn->query("SELECT st.student_id, st.optional_subject_id, sb.subject_name FROM students st LEFT JOIN subjects sb ON sb.id=st.optional_subject_id WHERE st.student_id IN ($sidIn) AND st.optional_subject_id IS NOT NULL");
                if ($q1){ while($r=$q1->fetch_assoc()){ $sn = trim((string)($r['subject_name'] ?? '')); if($sn!==''){ $optMap[$r['student_id']] = $sn; } } }
            }
            // Fallback via student_subjects and subject_group_map
            $missing = array_diff(array_keys($assignedSidStr), array_keys($optMap));
            if (!empty($missing) && $hasSubjects && $hasStuSubs && $hasMap){
                $mList = array_map(function($s) use ($conn){ return "'".mysqli_real_escape_string($conn, $s)."'"; }, $missing);
                $mIn = implode(',', $mList);
                $q2 = $conn->query("SELECT st.student_id, s.subject_name, s.subject_code FROM students st JOIN student_subjects ss ON ss.student_id=st.student_id JOIN subjects s ON s.id=ss.subject_id JOIN subject_group_map gm ON gm.subject_id=s.id AND gm.class_id=st.class_id AND gm.type='Optional' WHERE st.student_id IN ($mIn)");
                if ($q2){
                    $best = [];
                    while($r=$q2->fetch_assoc()){
                        $sid = (string)$r['student_id']; $name = (string)($r['subject_name'] ?? ''); $code = (string)($r['subject_code'] ?? '');
                        $num = is_numeric($code)? (int)$code : -1;
                        if (!isset($best[$sid]) || $num > $best[$sid]['num']){
                            $best[$sid] = ['num'=>$num, 'name'=>$name];
                        }
                    }
                    foreach ($best as $sid=>$info){ if(!isset($optMap[$sid]) && !empty($info['name'])) $optMap[$sid] = $info['name']; }
                }
            }
            foreach ($optMap as $sid=>$subName){ $subName = trim($subName); if($subName!==''){ $optionalCounts[$subName] = ($optionalCounts[$subName] ?? 0) + 1; } }
        }
        // Helper to pick badge class for class name
        function badgeClassFor($className){ $g = detectGradeFromClass($className); return $g ? ('grade-'.(int)$g) : ''; }
        ?>
        <div class="stats">
            <div class="stats-grid">
                <div class="stat-col">
                    <h5>শ্রেণিভিত্তিক পরিসংখ্যান</h5>
                    <div><strong>মোট শিক্ষার্থী — </strong> <?= (int)$totalAssigned ?>  জন</div>
                    <?php if (!empty($classCounts)): ?>
                        <ul>
                            <?php foreach ($classCounts as $cn=>$cnt): $bc = badgeClassFor($cn); ?>
                                <li><span class="badge <?= htmlspecialchars($bc) ?>"><?= htmlspecialchars($cn) ?></span> — <?= (int)$cnt ?> জন</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="stat-col">
                    <h5>গ্রুপভিত্তিক (৯-১০)</h5>
                    <?php if (!empty($groupCounts)): ?>
                        <ul>
                            <?php foreach ($groupCounts as $g=>$cnt): ?>
                                <li><?= htmlspecialchars($g) ?> — <?= (int)$cnt ?> জন</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div style="color:#666;">প্রযোজ্য নয়</div>
                    <?php endif; ?>
                </div>
                <div class="stat-col">
                    <h5>ঐচ্ছিক বিষয় (৯-১০)</h5>
                    <?php if (!empty($optionalCounts)): ?>
                        <ul>
                            <?php foreach ($optionalCounts as $sub=>$cnt): ?>
                                <li><?= htmlspecialchars($sub) ?> — <?= (int)$cnt ?> জন</li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div style="color:#666;">প্রযোজ্য নয়</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    
    <!-- Fixed, highlighted developer credit at bottom of page -->
    <div class="fixed-footer">
        <strong>Developed by Md. Abdul Halim</strong> | <strong>Batighor Computers</strong> - <strong>https://batighorbd.com</strong>
    </div>
</body>
</html>
