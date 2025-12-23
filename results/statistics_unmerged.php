<?php
@include_once __DIR__ . '/../config/config.php';
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// Branding
$institute_name = isset($institute_name) ? $institute_name : 'Institution Name';
$institute_address = isset($institute_address) ? $institute_address : '';

// Exam info
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='".$conn->real_escape_string($exam_id)."'"));
$exam = $examRow['exam_name'] ?? '';
$subjects_without_fourth = isset($examRow['total_subjects_without_fourth']) ? (int)$examRow['total_subjects_without_fourth'] : 0;
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='".$conn->real_escape_string($class_id)."'"))['class_name'] ?? '';

// Load subjects (UNMERGED)
$subjectPassTypeSelect = "NULL AS subject_pass_type";
$__chk = mysqli_query($conn, "SHOW COLUMNS FROM subjects LIKE 'pass_type'");
if ($__chk && mysqli_num_rows($__chk) > 0) { $subjectPassTypeSelect = "s.pass_type AS subject_pass_type"; }
$subjects_sql = "
    SELECT 
        s.id as subject_id,
        s.subject_name,
        s.subject_code, 
        s.has_creative,
        s.has_objective,
        s.has_practical,
        es.creative_marks AS creative_full,
        es.objective_marks AS objective_full,
        es.practical_marks AS practical_full,
        es.creative_pass,
        es.objective_pass,
        es.practical_pass,
        es.total_marks as max_marks,
        gm.group_name,
        gm.class_id,
        gm.type AS subject_type,
        es.pass_type,
        $subjectPassTypeSelect
    FROM exam_subjects es 
    JOIN subjects s ON es.subject_id = s.id
    JOIN subject_group_map gm ON gm.subject_id = s.id 
    WHERE es.exam_id = '".$conn->real_escape_string($exam_id)."' 
        AND gm.class_id = '".$conn->real_escape_string($class_id)."'
    ORDER BY FIELD(gm.type, 'Compulsory', 'Optional'), s.subject_code
";
$subjects_q = mysqli_query($conn, $subjects_sql);
$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $row['has_creative'] = (!empty($row['has_creative']) && intval($row['creative_full'] ?? 0) > 0) ? 1 : 0;
    $row['has_objective'] = (!empty($row['has_objective']) && intval($row['objective_full'] ?? 0) > 0) ? 1 : 0;
    $row['has_practical'] = (!empty($row['has_practical']) && intval($row['practical_full'] ?? 0) > 0) ? 1 : 0;
    $subjects[] = array_merge($row, ['type' => ($row['subject_type'] ?? 'Compulsory')]);
}
// Dedup prefer Compulsory
$dedup = [];
foreach ($subjects as $s) { $sid = $s['subject_id']; if (!isset($dedup[$sid])) { $dedup[$sid] = $s; } else { if (($dedup[$sid]['subject_type'] ?? '') !== 'Compulsory' && ($s['subject_type'] ?? '') === 'Compulsory') { $dedup[$sid]['subject_type'] = 'Compulsory'; } } }
$display_subjects = array_values($dedup);

// Order subjects
$compulsory_list = []; $optional_list = [];
foreach ($display_subjects as $ds) { $t = $ds['type'] ?? 'Compulsory'; if ($t === 'Optional') $optional_list[] = $ds; else $compulsory_list[] = $ds; }
$groupOrder = function($name) {
    $g = strtolower(trim($name ?? ''));
    if ($g === '' || $g === 'none' || $g === 'common' || $g === 'all' || $g === 'null') return 0;
    if ($g === 'science' || $g === 'science group') return 1;
    if ($g === 'humanities' || $g === 'arts') return 2;
    if ($g === 'business' || $g === 'business studies' || $g === 'commerce') return 3;
    return 4;
};
usort($compulsory_list, function($a, $b) use ($groupOrder) {
    $oa = $groupOrder($a['group_name'] ?? '');
    $ob = $groupOrder($b['group_name'] ?? '');
    if ($oa !== $ob) return $oa <=> $ob;
    $ca = $a['subject_code'] ?? '';
    $cb = $b['subject_code'] ?? '';
    $na = is_numeric($ca) ? (float)$ca : (float)PHP_INT_MAX;
    $nb = is_numeric($cb) ? (float)$cb : (float)PHP_INT_MAX;
    if ($na !== $nb) return $na <=> $nb;
    return strcmp((string)($a['subject_name'] ?? ''), (string)($b['subject_name'] ?? ''));
});
$display_subjects = array_merge($compulsory_list, $optional_list);

// Assigned subjects
$assignedByStudent = []; $assignedCodesByStudent = []; $subjectCodeById = [];
$assign_sql = "SELECT ss.student_id, ss.subject_id, sb.subject_code
               FROM student_subjects ss
               JOIN students st ON st.student_id = ss.student_id AND st.class_id = '".$conn->real_escape_string($class_id)."' AND st.status='Active'
               JOIN subjects sb ON sb.id = ss.subject_id";
$assign_res = mysqli_query($conn, $assign_sql);
if ($assign_res) {
    while ($ar = mysqli_fetch_assoc($assign_res)) {
        $sid = $ar['student_id'];
        if (!isset($assignedByStudent[$sid])) { $assignedByStudent[$sid] = []; $assignedCodesByStudent[$sid] = []; }
        $subId = (int)$ar['subject_id'];
        $subCode = (string)$ar['subject_code'];
        $assignedByStudent[$sid][$subId] = true;
        $assignedCodesByStudent[$sid][$subCode] = true;
        $subjectCodeById[$subId] = $subCode;
    }
}

// Optional detection
$optionalIdByStudent = [];
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $optIdRes = mysqli_query($conn, "SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND status='Active' AND optional_subject_id IS NOT NULL");
    if ($optIdRes) { while ($row = mysqli_fetch_assoc($optIdRes)) { $optionalIdByStudent[$row['student_id']] = (int)$row['optional_subject_id']; } }
}
$effectiveTypeByStudent = []; $optionalCodeByStudent = [];
$optSql = "
        SELECT st.student_id, s.id AS subject_id, s.subject_code,
               CASE
                 WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                   THEN CASE WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                              THEN 'Optional' ELSE 'Compulsory' END
                   ELSE CASE WHEN SUM(CASE WHEN UPPER(gm.group_name) COLLATE utf8mb4_unicode_ci = 'NONE' COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                              THEN 'Optional' ELSE 'Compulsory' END
               END AS effective_type
        FROM student_subjects ss
        JOIN students st ON st.student_id = ss.student_id AND st.class_id = ? AND st.year = ? AND st.status = 'Active'
        JOIN subjects s ON s.id = ss.subject_id
        JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = st.class_id
        GROUP BY st.student_id, s.id, s.subject_code";
$optStmt = $conn->prepare($optSql);
$optStmt->bind_param('ss', $class_id, $year);
$optStmt->execute();
$optRes = $optStmt->get_result();
while ($r = $optRes->fetch_assoc()) { $effectiveTypeByStudent[$r['student_id']][(int)$r['subject_id']] = $r['effective_type']; }
foreach ($effectiveTypeByStudent as $stuId => $typeMap) {
    $optionalCandidates = [];
    foreach ($typeMap as $subId => $etype) {
        if (strcasecmp((string)$etype, 'Optional') === 0) { $code = $subjectCodeById[$subId] ?? ''; if ($code !== '') $optionalCandidates[$subId] = (string)$code; }
    }
    if (count($optionalCandidates) === 1) { $optionalCodeByStudent[$stuId] = (string)array_values($optionalCandidates)[0]; }
    elseif (count($optionalCandidates) > 1) { $chosenCode = ''; $maxNum = -1; foreach ($optionalCandidates as $code) { $num = is_numeric($code) ? (int)$code : -1; if ($num > $maxNum) { $maxNum = $num; $chosenCode = (string)$code; } } if ($chosenCode !== '') { $optionalCodeByStudent[$stuId] = $chosenCode; } }
}
if (!empty($optionalIdByStudent)) { foreach ($optionalIdByStudent as $stuId => $optSubId) { if (!empty($subjectCodeById[$optSubId])) { $optionalCodeByStudent[$stuId] = (string)$subjectCodeById[$optSubId]; } } }

// Helpers
function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $stmt = $conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
    $stmt->bind_param("sss", $student_id, $subject_id, $exam_id);
    $stmt->execute();
    $marks = 0; $stmt->bind_result($marks);
    if ($stmt->fetch()) { $val = (float)$marks; $stmt->close(); return $val; }
    $stmt->close(); return 0;
}
function subjectGPA($marks, $full_marks) {
    $percentage = ($full_marks > 0) ? ($marks / $full_marks) * 100 : 0;
    if ($percentage >= 80) return 5.00;
    elseif ($percentage >= 70) return 4.00;
    elseif ($percentage >= 60) return 3.50;
    elseif ($percentage >= 50) return 3.00;
    elseif ($percentage >= 40) return 2.00;
    elseif ($percentage >= 33) return 1.00;
    else return 0.00;
}
function getPassStatus($class_id, $marks, $pass_marks, $pass_type = 'total') {
    $total = ($marks['creative'] ?? 0) + ($marks['objective'] ?? 0) + ($marks['practical'] ?? 0);
    if (strtolower((string)$pass_type) === 'total') {
        $required_total = ($pass_marks['creative'] ?? 0) + ($pass_marks['objective'] ?? 0) + ($pass_marks['practical'] ?? 0);
        return $total >= $required_total;
    } else {
        if (isset($pass_marks['creative']) && isset($marks['creative']) && ($marks['creative'] < $pass_marks['creative'])) return false;
        if (isset($pass_marks['objective']) && isset($marks['objective']) && ($marks['objective'] < $pass_marks['objective'])) return false;
        if (isset($pass_marks['practical']) && isset($marks['practical']) && ($marks['practical'] < $pass_marks['practical'])) return false;
        return true;
    }
}

// Aggregations
$total_students = 0; $pass_students = 0; $fail_students = 0;
$grade_buckets = ['A+' => 0, 'A' => 0, 'A-' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
$class_total_sum = 0.0; $class_gpa_sum = 0.0; $class_max_total = -1; $class_min_total = PHP_INT_MAX;

// Subject-wise stats (compulsory-only)
$subjectStats = [];
foreach ($display_subjects as $s) { $code = (string)($s['subject_code'] ?? ''); if ($code==='') continue; $subjectStats[$code] = ['name' => $s['subject_name'] ?? $code, 'assigned' => 0, 'comp_assigned' => 0, 'pass' => 0, 'fail' => 0]; }

$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id = '".$conn->real_escape_string($class_id)."' AND year = '".$conn->real_escape_string($year)."' AND status='Active' ORDER BY roll_no");
if ($students_q) {
    while ($stu = mysqli_fetch_assoc($students_q)) {
        $total_students++;
        $student_total = 0; $fail_count = 0; $compulsory_gpa_total = 0; $compulsory_gpa_subjects = 0; $optional_gpas = [];
        $student_optional_id = isset($optionalIdByStudent[$stu['student_id']]) ? (int)$optionalIdByStudent[$stu['student_id']] : 0;
        $student_optional_code = isset($optionalCodeByStudent[$stu['student_id']]) ? (string)$optionalCodeByStudent[$stu['student_id']] : '';

        foreach ($display_subjects as $sub) {
            $subject_code = $sub['subject_code'] ?? null; if (!$subject_code) continue;
            $sidSub = (int)($sub['subject_id'] ?? 0);
            $assigned = !empty($assignedByStudent[$stu['student_id']][$sidSub]);
            if (!$assigned) continue;
            if (isset($subjectStats[$subject_code])) { $subjectStats[$subject_code]['assigned']++; }

            $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'creative') : 0;
            $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'objective') : 0;
            $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'practical') : 0;
            $sub_total = $c + $o + $p;

            $is_compulsory = true;
            if (!empty($student_optional_id) && $sidSub === $student_optional_id) { $is_compulsory = false; }
            elseif (!empty($student_optional_code) && (string)$subject_code === (string)$student_optional_code) { $is_compulsory = false; }

            $pass_type_raw = isset($sub['pass_type']) ? trim((string)$sub['pass_type']) : '';
            $pass_type_sub = isset($sub['subject_pass_type']) ? trim((string)$sub['subject_pass_type']) : '';
            $pass_type = strtolower($pass_type_raw !== '' ? $pass_type_raw : ($pass_type_sub !== '' ? $pass_type_sub : 'total'));
            $pm = []; $mk = [];
            if (!empty($sub['has_creative'])) { $pm['creative'] = (int)($sub['creative_pass'] ?? 0); $mk['creative'] = $c; }
            if (!empty($sub['has_objective'])) { $pm['objective'] = (int)($sub['objective_pass'] ?? 0); $mk['objective'] = $o; }
            if (!empty($sub['has_practical'])) { $pm['practical'] = (int)($sub['practical_pass'] ?? 0); $mk['practical'] = $p; }
            $isPass = getPassStatus($class_id, $mk, $pm, $pass_type);

            if ($is_compulsory && isset($subjectStats[$subject_code])) {
                $subjectStats[$subject_code]['comp_assigned']++;
                if ($isPass) $subjectStats[$subject_code]['pass']++; else $subjectStats[$subject_code]['fail']++;
            }

            if (!$isPass && $is_compulsory) { $fail_count++; }
            $gpa = !$isPass ? 0.00 : subjectGPA($sub_total, $sub['max_marks']);
            if ($is_compulsory) { $compulsory_gpa_total += $gpa; $compulsory_gpa_subjects++; } else { $optional_gpas[] = $gpa; }
            $student_total += $sub_total;
        }

        $optional_bonus = 0.00;
        if (!empty($optional_gpas)) { $max_optional = max($optional_gpas); if ($max_optional > 2.00) $optional_bonus = $max_optional - 2.00; }
        $final_gpa = 0.00;
        $divisor = ($subjects_without_fourth > 0) ? min($subjects_without_fourth, $compulsory_gpa_subjects) : $compulsory_gpa_subjects;
        if ($divisor > 0) { $final_gpa = ($compulsory_gpa_total + $optional_bonus) / $divisor; }
        if ($fail_count > 0) $final_gpa = 0.00;
        $final_gpa = (float)number_format(min($final_gpa, 5.00), 2);

        if ($fail_count === 0) $pass_students++; else $fail_students++;
        $class_total_sum += $student_total; $class_gpa_sum += $final_gpa;
        if ($student_total > $class_max_total) $class_max_total = $student_total;
        if ($student_total < $class_min_total) $class_min_total = $student_total;

        if ($final_gpa >= 5.00) $grade_buckets['A+']++;
        elseif ($final_gpa >= 4.00) $grade_buckets['A']++;
        elseif ($final_gpa >= 3.50) $grade_buckets['A-']++;
        elseif ($final_gpa >= 3.00) $grade_buckets['B']++;
        elseif ($final_gpa >= 2.00) $grade_buckets['C']++;
        elseif ($final_gpa >= 1.00) $grade_buckets['D']++;
        else $grade_buckets['F']++;
    }
}

$pass_rate = ($pass_students + $fail_students > 0) ? round(($pass_students / ($pass_students + $fail_students)) * 100, 2) : 0.0;
$avg_total = ($pass_students + $fail_students > 0) ? round($class_total_sum / ($pass_students + $fail_students), 2) : 0.0;
$avg_gpa = ($pass_students + $fail_students > 0) ? round($class_gpa_sum / ($pass_students + $fail_students), 2) : 0.0;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Statistics (Unmerged)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> th, td { vertical-align: middle !important; font-size: 13px; padding: 3px; } @media print { .no-print { display: none; } } </style>
</head>
<body>
<div class="container my-4">
    <div class="text-center mb-3">
        <h5><?= htmlspecialchars($institute_name) ?></h5>
        <p><?= htmlspecialchars($institute_address) ?></p>
        <h4><?= htmlspecialchars($exam) ?> - <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($year) ?>)</h4>
        <button onclick="window.print()" class="btn btn-primary no-print">প্রিন্ট করুন</button>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="card"><div class="card-body"><strong>মোট শিক্ষার্থী:</strong> <?= (int)($pass_students + $fail_students) ?></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><strong>পাস:</strong> <?= (int)$pass_students ?></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><strong>ফেল:</strong> <?= (int)$fail_students ?></div></div></div>
        <div class="col-md-3"><div class="card"><div class="card-body"><strong>পাস রেট:</strong> <?= htmlspecialchars((string)$pass_rate) ?>%</div></div></div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="card"><div class="card-body"><strong>গড় মোট নম্বর:</strong> <?= htmlspecialchars((string)$avg_total) ?></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><strong>গড় GPA:</strong> <?= htmlspecialchars((string)$avg_gpa) ?></div></div></div>
        <div class="col-md-4"><div class="card"><div class="card-body"><strong>সর্বোচ্চ / সর্বনিম্ন মোট:</strong> <?= htmlspecialchars((string)$class_max_total) ?> / <?= htmlspecialchars((string)$class_min_total) ?></div></div></div>
    </div>

    <h6 class="mt-4">Grade Distribution</h6>
    <table class="table table-bordered text-center">
        <thead class="table-light"><tr><th>A+</th><th>A</th><th>A-</th><th>B</th><th>C</th><th>D</th><th>F</th></tr></thead>
        <tbody><tr>
            <td><?= (int)$grade_buckets['A+'] ?></td>
            <td><?= (int)$grade_buckets['A'] ?></td>
            <td><?= (int)$grade_buckets['A-'] ?></td>
            <td><?= (int)$grade_buckets['B'] ?></td>
            <td><?= (int)$grade_buckets['C'] ?></td>
            <td><?= (int)$grade_buckets['D'] ?></td>
            <td><?= (int)$grade_buckets['F'] ?></td>
        </tr></tbody>
    </table>

    <h6 class="mt-4">বিষয়ভিত্তিক পাস/ফেল (Compulsory)</h6>
    <table class="table table-bordered text-center">
        <thead class="table-primary">
            <tr>
                <th>Subject Code</th>
                <th>Subject Name</th>
                <th>Assigned (All)</th>
                <th>Compulsory Assigned</th>
                <th>Pass</th>
                <th>Fail</th>
                <th>Pass Rate</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($display_subjects as $s): $code = (string)($s['subject_code'] ?? ''); if ($code==='') continue; $st = $subjectStats[$code] ?? null; if (!$st) continue; $rate = ($st['comp_assigned']>0) ? round(($st['pass']/$st['comp_assigned'])*100,2) : 0; ?>
            <tr>
                <td><?= htmlspecialchars($code) ?></td>
                <td><?= htmlspecialchars($st['name']) ?></td>
                <td><?= (int)$st['assigned'] ?></td>
                <td><?= (int)$st['comp_assigned'] ?></td>
                <td><?= (int)$st['pass'] ?></td>
                <td><?= (int)$st['fail'] ?></td>
                <td><?= htmlspecialchars((string)$rate) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>
<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';
if (!$exam_id || !$class_id || !$year) { echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>"; exit; }

// Institute info
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";
// Resolve app base URL path (e.g., /jsssms) for absolute asset URLs
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$appBasePath = rtrim(str_replace('\\','/', dirname(dirname($scriptName))), '/');
if ($appBasePath === '/') { $appBasePath = ''; }

// Try to load institute info (name/address/logo) from DB if table exists
$logo_src = '';
$__tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'institute_info'");
if ($__tblCheck && mysqli_num_rows($__tblCheck) > 0) {
  $__ii = mysqli_query($conn, "SELECT school_name, address, logo FROM institute_info ORDER BY id DESC LIMIT 1");
  if ($__ii) {
    $__ir = mysqli_fetch_assoc($__ii);
    if (!empty($__ir['school_name'])) { $institute_name = $__ir['school_name']; }
    if (!empty($__ir['address'])) { $institute_address = $__ir['address']; }
    $dbLogo = trim((string)($__ir['logo'] ?? ''));
    if ($dbLogo !== '') {
      $dbLogoFs = __DIR__ . '/../uploads/logo/' . $dbLogo;
      if (file_exists($dbLogoFs)) {
        $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode($dbLogo);
      }
    }
  }
}

// Determine logo file (filesystem) then build URL relative to app base; keep DB-provided $logo_src if present
if (empty($logo_src)) {
  $defaultLogo = '1757281102_jss_logo.png';
  $defaultLogoFs = __DIR__ . '/../uploads/logo/' . $defaultLogo;
  if (file_exists($defaultLogoFs)) {
    $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode($defaultLogo);
  } else {
    $pattern = __DIR__ . '/../uploads/logo/*.{png,jpg,jpeg,gif}';
    $candidates = glob($pattern, GLOB_BRACE);
    if ($candidates && count($candidates) > 0) {
      usort($candidates, function($a,$b){ return @filemtime($b) <=> @filemtime($a); });
      $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode(basename($candidates[0]));
    }
  }
  // Final fallback to assets logo if uploads are missing
  if (empty($logo_src)) {
    $assetsLogoFs = __DIR__ . '/../assets/logo.png';
    if (file_exists($assetsLogoFs)) {
      $logo_src = $appBasePath . '/assets/logo.png';
    }
  }
}

// Exam and class
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='".$conn->real_escape_string($exam_id)."'"));
$exam = $examRow['exam_name'] ?? '';
// In unmerged stats, we'll normalize GPA by number of compulsory papers counted
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='".$conn->real_escape_string($class_id)."'"))['class_name'];

// Subjects list with exam configs (no merging here)
$subjectPassTypeSelect = "NULL AS subject_pass_type";
$__chk = mysqli_query($conn, "SHOW COLUMNS FROM subjects LIKE 'pass_type'");
if ($__chk && mysqli_num_rows($__chk) > 0) { $subjectPassTypeSelect = "s.pass_type AS subject_pass_type"; }
$subjects_sql = "
  SELECT s.id as subject_id, s.subject_name, s.subject_code, s.has_creative, s.has_objective, s.has_practical,
         es.creative_marks AS creative_full, es.objective_marks AS objective_full, es.practical_marks AS practical_full,
         es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks as max_marks,
         gm.group_name, gm.class_id, gm.type AS subject_type, es.pass_type, $subjectPassTypeSelect
  FROM exam_subjects es JOIN subjects s ON es.subject_id=s.id JOIN subject_group_map gm ON gm.subject_id=s.id
  WHERE es.exam_id='".$conn->real_escape_string($exam_id)."' AND gm.class_id='".$conn->real_escape_string($class_id)."'
  ORDER BY FIELD(gm.type,'Compulsory','Optional'), s.subject_code";
$subjects_q = mysqli_query($conn, $subjects_sql);
$subjects = []; while ($row = mysqli_fetch_assoc($subjects_q)) { $subjects[] = $row; }
// Dedup prefer Compulsory mapping when same subject appears with different types
$dedup = [];
foreach ($subjects as $s) {
  $sid = $s['subject_id'];
  if (!isset($dedup[$sid])) $dedup[$sid]=$s; 
  else {
    if (($dedup[$sid]['subject_type']??'')!=='Compulsory' && ($s['subject_type']??'')==='Compulsory') { $dedup[$sid]['subject_type']='Compulsory'; }
  }
}
$subjects = array_values($dedup);

// Build display subject list (papers shown individually)
$display_subjects = [];
foreach ($subjects as $sub) {
  $eff_has_creative = (!empty($sub['has_creative']) && intval($sub['creative_full']??0)>0)?1:0;
  $eff_has_objective = (!empty($sub['has_objective']) && intval($sub['objective_full']??0)>0)?1:0;
  $eff_has_practical = (!empty($sub['has_practical']) && intval($sub['practical_full']??0)>0)?1:0;
  $sub['has_creative']=$eff_has_creative; $sub['has_objective']=$eff_has_objective; $sub['has_practical']=$eff_has_practical;
  $display_subjects[] = array_merge($sub, ['is_merged'=>false, 'type'=>($sub['subject_type']??'Compulsory')]);
}

// Ensure optional after compulsory and stable sort by group, then code
$compulsory_list=[]; $optional_list=[]; foreach ($display_subjects as $ds){ $t=$ds['type']??'Compulsory'; if($t==='Optional') $optional_list[]=$ds; else $compulsory_list[]=$ds; }
$groupOrder=function($name){ $g=strtolower(trim($name??'')); if($g===''||$g==='none'||$g==='common'||$g==='all'||$g==='null') return 0; if($g==='science'||$g==='science group') return 1; if($g==='humanities'||$g==='arts') return 2; if($g==='business'||$g==='business studies'||$g==='commerce') return 3; return 4; };
usort($compulsory_list,function($a,$b) use($groupOrder){ $oa=$groupOrder($a['group_name']??''); $ob=$groupOrder($b['group_name']??''); if($oa!==$ob) return $oa<=>$ob; $ca=$a['subject_code']??''; $cb=$b['subject_code']??''; $na=is_numeric($ca)?(float)$ca:(float)PHP_INT_MAX; $nb=is_numeric($cb)?(float)$cb:(float)PHP_INT_MAX; if($na!==$nb) return $na<=>$nb; return strcmp((string)($a['subject_name']??''),(string)($b['subject_name']??'')); });
$display_subjects=array_merge($compulsory_list,$optional_list);

// Assigned subjects and codes
$assignedByStudent=[]; $assignedCodesByStudent=[]; $subjectCodeById=[];
$assign_sql = "SELECT ss.student_id, ss.subject_id, sb.subject_code FROM student_subjects ss JOIN students st ON st.student_id=ss.student_id AND st.class_id='".$conn->real_escape_string($class_id)."' JOIN subjects sb ON sb.id=ss.subject_id";
$assign_res = mysqli_query($conn,$assign_sql);
if($assign_res){ while($ar=mysqli_fetch_assoc($assign_res)){ $sid=$ar['student_id']; if(!isset($assignedByStudent[$sid])){ $assignedByStudent[$sid]=[]; $assignedCodesByStudent[$sid]=[]; } $subId=(int)$ar['subject_id']; $subCode=(string)$ar['subject_code']; $assignedByStudent[$sid][$subId]=true; $assignedCodesByStudent[$sid][$subCode]=true; $subjectCodeById[$subId]=$subCode; } }

// Optional mapping
$optionalIdByStudent=[]; $colCheck=mysqli_query($conn,"SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if($colCheck && mysqli_num_rows($colCheck)>0){ $optIdRes=mysqli_query($conn,"SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND optional_subject_id IS NOT NULL"); if($optIdRes){ while($row=mysqli_fetch_assoc($optIdRes)){ $optionalIdByStudent[$row['student_id']]=(int)$row['optional_subject_id']; } } }
$effectiveTypeByStudent=[]; $optionalCodeByStudent=[];
$optSql = "SELECT st.student_id, s.id AS subject_id, s.subject_code,
           CASE WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                THEN CASE WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0 THEN 'Optional' ELSE 'Compulsory' END
                ELSE CASE WHEN SUM(CASE WHEN UPPER(gm.group_name) COLLATE utf8mb4_unicode_ci = 'NONE' COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0 THEN 'Optional' ELSE 'Compulsory' END END AS effective_type
          FROM student_subjects ss JOIN students st ON st.student_id=ss.student_id AND st.class_id=? AND st.year=? JOIN subjects s ON s.id=ss.subject_id JOIN subject_group_map gm ON gm.subject_id=s.id AND gm.class_id=st.class_id GROUP BY st.student_id, s.id, s.subject_code";
$optStmt=$conn->prepare($optSql); $optStmt->bind_param('ss',$class_id,$year); $optStmt->execute(); $optRes=$optStmt->get_result();
while($r=$optRes->fetch_assoc()){ $effectiveTypeByStudent[$r['student_id']][(int)$r['subject_id']]=$r['effective_type']; }
foreach($effectiveTypeByStudent as $stuId=>$typeMap){ $optionalCandidates=[]; foreach($typeMap as $subId=>$etype){ if(strcasecmp((string)$etype,'Optional')===0){ $code=$subjectCodeById[$subId]??''; if($code!==''){ $optionalCandidates[$subId]=$code; } } } if(count($optionalCandidates)===1){ $optionalCodeByStudent[$stuId]=(string)array_values($optionalCandidates)[0]; } elseif(count($optionalCandidates)>1){ $chosenCode=''; $maxNum=-1; foreach($optionalCandidates as $code){ $num=is_numeric($code)?(int)$code:-1; if($num>$maxNum){ $maxNum=$num; $chosenCode=(string)$code; } } if($chosenCode!==''){ $optionalCodeByStudent[$stuId]=$chosenCode; } } }
if(!empty($optionalIdByStudent)){ foreach($optionalIdByStudent as $stuId=>$optSubId){ if(!empty($subjectCodeById[$optSubId])){ $optionalCodeByStudent[$stuId]=(string)$subjectCodeById[$optSubId]; } } }

// Utilities
function getMarks($student_id, $subject_id, $exam_id, $type){ global $conn; $stmt=$conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id=? AND subject_id=? AND exam_id=?"); $stmt->bind_param('sss',$student_id,$subject_id,$exam_id); $stmt->execute(); $marks=0; $stmt->bind_result($marks); if($stmt->fetch()){ $val=(float)$marks; $stmt->close(); return $val; } $stmt->close(); return 0; }
function subjectGPA($marks,$full){ $pct=($full>0)?($marks/$full)*100:0; if($pct>=80) return 5.00; elseif($pct>=70) return 4.00; elseif($pct>=60) return 3.50; elseif($pct>=50) return 3.00; elseif($pct>=40) return 2.00; elseif($pct>=33) return 1.00; else return 0.00; }
function getPassStatus($class_id,$marks,$pass_marks,$pass_type='total'){ if(strtolower((string)$pass_type)==='total'){ $req=($pass_marks['creative']??0)+($pass_marks['objective']??0)+($pass_marks['practical']??0); $tot=($marks['creative']??0)+($marks['objective']??0)+($marks['practical']??0); return $tot>=$req; } else { if(isset($pass_marks['creative']) && isset($marks['creative']) && ($marks['creative']<$pass_marks['creative'])) return false; if(isset($pass_marks['objective']) && isset($marks['objective']) && ($marks['objective']<$pass_marks['objective'])) return false; if(isset($pass_marks['practical']) && isset($marks['practical']) && ($marks['practical']<$pass_marks['practical'])) return false; return true; } }

// Short name helper for long subject titles
function shortSubjectName($name){
  $n = trim((string)$name);
  $map = [
    'Information And Communication Technology' => 'ICT',
    'Information & Communication Technology' => 'ICT',
    'History Of Bangladesh And World Civilization' => 'History',
    'History of Bangladesh And World Civilization' => 'History',
    'Bangladesh and Global Studies' => 'BGS',
    'Higher Mathematics' => 'H. Math',
    'General Science' => 'Science',
  ];
  foreach($map as $long=>$short){
    if(strcasecmp($n,$long)===0){ return $short; }
  }
  return $n;
}

// Fetch students
$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id = ".$conn->real_escape_string($class_id)." AND year = ".$conn->real_escape_string($year)." ORDER BY roll_no");
$students=[]; while($stu=mysqli_fetch_assoc($students_q)){ $students[]=$stu; }
$total_students = count($students);

// Aggregations
$overall_pass=0; $overall_fail=0; $gpa_sum=0.0; $gpa_max=0.0; $gpa_buckets=[ '5.00'=>0, '4.00-4.99'=>0, '3.50-3.99'=>0, '3.00-3.49'=>0, '2.00-2.99'=>0, '1.00-1.99'=>0, '0.00-0.99'=>0 ];
$group_stats=[]; // group_name => ['count'=>0,'pass'=>0]
$section_stats=[]; // section_id => ['name'=>..., 'count'=>0,'pass'=>0]
$sections_map=[]; $sres=mysqli_query($conn,"SELECT id, section_name FROM sections"); if($sres){ while($r=mysqli_fetch_assoc($sres)){ $sections_map[(int)$r['id']]=$r['section_name']; } }

$subject_stats=[]; // key by subject_code; store totals, counts, highest, lowest, passes
foreach($display_subjects as $sub){ $key = $sub['subject_code'] ?? ''; if($key==='') continue; $subject_stats[$key]=[ 'name'=>$sub['subject_name'] ?? (string)$key, 'code'=>$sub['subject_code'] ?? (string)$key, 'total'=>0, 'count'=>0, 'highest'=>-INF, 'lowest'=>INF, 'pass'=>0, 'fail'=>0, 'max_marks'=>(int)($sub['max_marks']??0), 'has_creative'=>!empty($sub['has_creative']), 'has_objective'=>!empty($sub['has_objective']), 'has_practical'=>!empty($sub['has_practical']), 'pass_type'=>strtolower((string)($sub['pass_type']??($sub['subject_pass_type']??'total'))), 'creative_pass'=>(int)($sub['creative_pass']??0), 'objective_pass'=>(int)($sub['objective_pass']??0), 'practical_pass'=>(int)($sub['practical_pass']??0) ]; }

$top_students=[]; // collect for top 10
$failed_students=[]; // collect failing students list

foreach($students as $stu){
  $stu_id=$stu['student_id']; $roll=(int)($stu['roll_no'] ?? 0); $group=trim((string)($stu['student_group'] ?? ''));
  if(!isset($group_stats[$group])) $group_stats[$group]=['count'=>0,'pass'=>0]; $group_stats[$group]['count']++;
  $sec_id = isset($stu['section_id']) ? (int)$stu['section_id'] : 0; $sec_name = $sections_map[$sec_id] ?? ''; if(!isset($section_stats[$sec_id])) $section_stats[$sec_id]=['name'=>$sec_name,'count'=>0,'pass'=>0]; $section_stats[$sec_id]['count']++;

  $total_marks=0; $fail_count=0; $comp_gpa_total=0; $comp_gpa_subjects=0; $optional_gpas=[]; $failed_list=[];
  foreach($display_subjects as $sub){
    $subject_code = $sub['subject_code'] ?? null; if(!$subject_code) continue;
    $sidSub=(int)($sub['subject_id'] ?? 0); if(empty($assignedByStudent[$stu_id][$sidSub])){ continue; }
    $c=!empty($sub['has_creative'])? getMarks($stu_id,$sidSub,$exam_id,'creative'):0;
    $o=!empty($sub['has_objective'])? getMarks($stu_id,$sidSub,$exam_id,'objective'):0;
    $p=!empty($sub['has_practical'])? getMarks($stu_id,$sidSub,$exam_id,'practical'):0;

    $sub_total = $c+$o+$p;
    $key = $subject_code;

    $pm=[]; $mk=[]; if(!empty($sub['has_creative'])){ $pm['creative']=(int)($sub['creative_pass']??0); $mk['creative']=$c; }
    if(!empty($sub['has_objective'])){ $pm['objective']=(int)($sub['objective_pass']??0); $mk['objective']=$o; }
    if(!empty($sub['has_practical'])){ $pm['practical']=(int)($sub['practical_pass']??0); $mk['practical']=$p; }
    $pass_type=strtolower((string)($sub['pass_type']??($sub['subject_pass_type']??'total')));
    $isPass = getPassStatus($class_id,$mk,$pm,$pass_type);

    // Determine optional vs compulsory
    $opt_code = $optionalCodeByStudent[$stu_id] ?? '';
    $is_optional = ($opt_code!=='' && (string)$subject_code === (string)$opt_code);

    if(!$isPass){
      if(!$is_optional){
        $fail_count++;
        $disp_name = $sub['subject_name'] ?? (string)$subject_code;
        $failed_list[] = shortSubjectName($disp_name);
      }
      $subject_stats[$key]['fail']++;
    } else {
      $subject_stats[$key]['pass']++;
    }

    $gpa = $isPass ? subjectGPA($sub_total, (int)($sub['max_marks']??0)) : 0.00;
    $total_marks += $sub_total;
    if($is_optional){ $optional_gpas[] = $gpa; }
    else { $comp_gpa_total += $gpa; $comp_gpa_subjects++; }

    // Subject aggregates
    if(isset($subject_stats[$key])){
      $subject_stats[$key]['total'] += $sub_total; $subject_stats[$key]['count']++;
      if($sub_total>$subject_stats[$key]['highest']) $subject_stats[$key]['highest']=$sub_total;
      if($sub_total<$subject_stats[$key]['lowest']) $subject_stats[$key]['lowest']=$sub_total;
    }
  }

  $optional_bonus=0.00; if(!empty($optional_gpas)){ $max_opt=max($optional_gpas); if($max_opt>2.00){ $optional_bonus=$max_opt-2.00; } }
  // In unmerged stats, normalize by the number of compulsory papers counted
  $divisor = $comp_gpa_subjects;
  $final_gpa = ($divisor>0) ? (($comp_gpa_total + $optional_bonus)/$divisor) : 0.00; if($fail_count>0) $final_gpa=0.00; $final_gpa = min($final_gpa,5.00);
  $gpa_sum += $final_gpa; if($final_gpa>$gpa_max) $gpa_max=$final_gpa;
  if($fail_count===0){ $overall_pass++; $group_stats[$group]['pass']++; if(isset($section_stats[$sec_id])) $section_stats[$sec_id]['pass']++; } else { $overall_fail++; }
  // GPA bucket
  $g = $final_gpa+1e-8; if($g>=5.00) $gpa_buckets['5.00']++; elseif($g>=4.00) $gpa_buckets['4.00-4.99']++; elseif($g>=3.50) $gpa_buckets['3.50-3.99']++; elseif($g>=3.00) $gpa_buckets['3.00-3.49']++; elseif($g>=2.00) $gpa_buckets['2.00-2.99']++; elseif($g>=1.00) $gpa_buckets['1.00-1.99']++; else $gpa_buckets['0.00-0.99']++;

  if($fail_count===0){
    $top_students[] = [ 'name'=>$stu['student_name']??'', 'roll'=>$roll, 'group'=>$group, 'total'=>$total_marks, 'gpa'=>number_format($final_gpa,2) ];
  }
  if($fail_count>0){ $failed_students[] = [ 'name'=>$stu['student_name']??'', 'roll'=>$roll, 'group'=>$group, 'section'=>$sec_name, 'fail_count'=>$fail_count, 'fails'=>implode(', ', $failed_list) ]; }
}

$avg_gpa = ($total_students>0) ? number_format($gpa_sum/$total_students, 2) : '0.00';
$pass_rate = ($total_students>0) ? round(($overall_pass*100.0)/$total_students) : 0;

// Sort failing students by section (asc), then roll (asc)
usort($failed_students,function($a,$b){
  $sa = strtolower(trim($a['section'] ?? ''));
  $sb = strtolower(trim($b['section'] ?? ''));
  if($sa === '') $sa = 'zzz';
  if($sb === '') $sb = 'zzz';
  $cmp = strcmp($sa,$sb);
  if($cmp !== 0) return $cmp;
  return ($a['roll'] <=> $b['roll']);
});

// Build order map from display_subjects to match chosen order
$order_map = [];
$__idx = 0;
foreach($display_subjects as $ds){
  $k = $ds['subject_code'] ?? '';
  if($k==='') continue; if(!isset($order_map[$k])){ $order_map[$k] = $__idx++; }
}

// Prepare subject rows with averages and pass/fail counts
$subject_rows = [];
foreach($subject_stats as $code=>$st){ if($st['count']<=0) continue; $avg = $st['total']/$st['count']; $pr = ($st['pass']+$st['fail'])>0 ? round(($st['pass']*100.0)/($st['pass']+$st['fail'])) : 0; $subject_rows[] = [ 'order'=>($order_map[$code] ?? 9999), 'name'=>$st['name'], 'code'=>$st['code'], 'avg'=>round($avg,2), 'high'=>($st['highest']===-INF?0:$st['highest']), 'low'=>($st['lowest']===INF?0:$st['lowest']), 'count'=>$st['count'], 'pass'=>$st['pass'], 'fail'=>$st['fail'], 'pass_rate'=>$pr, 'max'=>$st['max_marks'] ]; }
usort($subject_rows,function($a,$b){ return ($a['order']<=>$b['order']); });

// Top 10 students by total desc then roll asc
usort($top_students,function($a,$b){ return ($b['total']<=>$a['total']) ?: ($a['roll']<=>$b['roll']); });
$top_students = array_slice($top_students,0,10);

// Build fail-count summary (subjects failed -> count, rolls list)
$fail_summary = [];
foreach ($failed_students as $fs) {
  $fc = (int)($fs['fail_count'] ?? 0);
  $roll = (int)($fs['roll'] ?? 0);
  if ($fc <= 0) continue;
  if (!isset($fail_summary[$fc])) { $fail_summary[$fc] = ['count'=>0,'rolls'=>[]]; }
  $fail_summary[$fc]['count']++;
  $fail_summary[$fc]['rolls'][] = $roll;
}
ksort($fail_summary);
foreach ($fail_summary as $k => $info) { sort($fail_summary[$k]['rolls'], SORT_NUMERIC); }
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>বিস্তারিত পরিসংখ্যান (অমার্জিত) - <?= htmlspecialchars($institute_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0a0a0a; --muted:#374151; --border:#1f2937; --bg:#ffffff; --blue:#0ea5e9; --green:#22c55e; --red:#ef4444; --amber:#f59e0b; --violet:#8b5cf6; }
  *{ box-sizing:border-box }
  body{ margin:0; font-family:'Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); }
  .actions{ position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--border); padding:8px; text-align:center }
  .btn{ background:var(--blue); color:#fff; border:none; border-radius:8px; padding:8px 14px; font-weight:800; cursor:pointer }
  .page{ width:100%; max-width:297mm; margin:0 auto; padding:0 }
  .header{ display:grid; grid-template-columns:80px 1fr; gap:12px; align-items:center; padding:6px 8px; border-bottom:1px solid var(--border) }
  .logo img{ width:72px; height:72px; object-fit:contain }
  .brand h1{ font-size:22px; margin:0; font-weight:800 }
  .brand .meta{ color:var(--muted); font-weight:700; margin-top:2px }
  .brand .exam{ font-weight:800; color:#111 }
  .brand .pagetitle{ margin-top:4px; font-size:18px; font-weight:900; color:#111 }

  .kpis{ display:grid; grid-template-columns: repeat(6, 1fr); gap:8px; margin:8px 0 }
  .kpi{ border:1px solid var(--border); border-radius:8px; padding:6px 8px }
  .kpi .label{ color:#111; font-weight:800; font-size:12px }
  .kpi .value{ font-weight:900; font-size:18px }

  .section{ margin:8px 0 }
  .section h3{ margin:6px 0; font-size:16px; font-weight:900; color:#111 }

  table{ width:100%; border-collapse:collapse }
  th,td{ border:1px solid var(--border); padding:6px 8px; font-size:12px; text-align:center }
  thead th{ background:#e5f3fb; font-weight:900; }
  td.left, th.left{ text-align:left }

  .bar{ height:12px; background:#e5e7eb; border-radius:6px; overflow:hidden }
  .bar > span{ display:block; height:100%; background:linear-gradient(90deg, var(--blue), #60a5fa); }
  .bar.green > span{ background:linear-gradient(90deg, var(--green), #86efac) }
  .bar.red > span{ background:linear-gradient(90deg, var(--red), #fca5a5) }
  .bar.amber > span{ background:linear-gradient(90deg, var(--amber), #fde68a) }
  .bar.violet > span{ background:linear-gradient(90deg, var(--violet), #c4b5fd) }

  .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:8px }
  .grid-3{ display:grid; grid-template-columns: repeat(3, 1fr); gap:8px }

  /* Per-page print footer */
  .print-footer{ position: static; left:0; right:0; bottom:0; background:#fff; border-top:1px solid var(--border); padding:6px 8px; text-align:center; font-weight:900 }
  .spacer{ display:none }

  @media print{
    /* Print only the footer on the last page by keeping it in normal flow */
    @page{ size:A4 landscape; margin: 1in 0.5in 0.5in 0.5in }
    .actions{ display:none }
    .page{ padding:0; display:flex; flex-direction:column; min-height:100vh }
    .spacer{ display:block; flex:1 0 auto; page-break-inside: avoid }
    .print-footer{ position: static; display:block; margin-top:8px; padding:6px 8px; }
    .print-footer{ page-break-inside: avoid }
    html, body, table, th, td, thead, tbody{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  }
  /* subtle hint on page kind */
  .badge{ display:inline-block; margin-left:6px; padding:2px 6px; border-radius:6px; background:#eef2ff; color:#3730a3; font-weight:800; font-size:11px }
  .brand .exam .badge{ vertical-align:middle }
  .brand .pagetitle .badge{ vertical-align:middle }
  .brand .exam small{ color:#374151 }
  .brand .pagetitle small{ color:#374151 }
  .bar.blue > span{ background:linear-gradient(90deg, #0ea5e9, #60a5fa) }
  .bar.amber > span{ background:linear-gradient(90deg, #f59e0b, #fde68a) }
  .bar.violet > span{ background:linear-gradient(90deg, #8b5cf6, #c4b5fd) }
  .bar.green > span{ background:linear-gradient(90deg, #22c55e, #86efac) }
  .bar.red > span{ background:linear-gradient(90deg, #ef4444, #fca5a5) }
</style>
</head>
<body>
<div class="actions"><button class="btn" onclick="window.print()">প্রিন্ট করুন</button></div>
<div class="page">
  <div class="header">
    <div class="logo"><?php if (!empty($logo_src)): ?><img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" onerror="this.style.display='none'"><?php endif; ?></div>
    <div class="brand">
      <h1><?= htmlspecialchars($institute_name) ?></h1>
      <div class="meta"><?= htmlspecialchars($institute_address) ?></div>
      <div class="exam"><?= htmlspecialchars($exam) ?> - <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($year) ?>) <span class="badge">অমার্জিত</span></div>
      <div class="pagetitle">বিস্তারিত পরিসংখ্যান <span class="badge">পেপার-ভিত্তিক</span></div>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="label">মোট শিক্ষার্থী</div><div class="value"><?= $total_students ?></div></div>
    <div class="kpi"><div class="label">পাস</div><div class="value" style="color:#16a34a"><?= $overall_pass ?></div></div>
    <div class="kpi"><div class="label">ফেল</div><div class="value" style="color:#dc2626"><?= $overall_fail ?></div></div>
    <div class="kpi"><div class="label">পাস হার</div><div class="value"><?= $pass_rate ?>%</div></div>
    <div class="kpi"><div class="label">গড় GPA</div><div class="value"><?= $avg_gpa ?></div></div>
    <div class="kpi"><div class="label">সর্বোচ্চ GPA</div><div class="value"><?= number_format($gpa_max,2) ?></div></div>
  </div>

  <div class="section">
    <h3>বিষয়ভিত্তিক সারাংশ</h3>
    <table>
      <thead><tr>
        <th class="left">বিষয় (কোড)</th>
        <th>গড়</th>
        <th>সর্বোচ্চ</th>
        <th>সর্বনিম্ন</th>
        <th>মোট</th>
        <th>পাস</th>
        <th>ফেল</th>
        <th>পাস হার</th>
        <th style="width:35%">চার্ট</th>
      </tr></thead>
      <tbody>
        <?php foreach ($subject_rows as $sr): $w = max(0,min(100,(int)$sr['pass_rate'])); ?>
          <tr>
            <td class="left"><?= htmlspecialchars($sr['name']) ?> <small>(<?= htmlspecialchars($sr['code']) ?>)</small></td>
            <td><?= htmlspecialchars($sr['avg']) ?></td>
            <td><?= htmlspecialchars($sr['high']) ?></td>
            <td><?= htmlspecialchars($sr['low']) ?></td>
            <td><?= (int)$sr['count'] ?></td>
            <td><?= (int)$sr['pass'] ?></td>
            <td><?= (int)$sr['fail'] ?></td>
            <td><?= htmlspecialchars($sr['pass_rate']) ?>%</td>
            <td>
              <div class="bar green"><span style="width: <?= $w ?>%"></span></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- Fail-count summary below subject summary -->
  <div class="section">
    <h3>ফেলের সংখ্যা ভিত্তিক সারাংশ</h3>
    <table>
      <thead>
        <tr>
          <th>ফেলকৃত বিষয় সংখ্যা</th>
          <th>শিক্ষার্থী সংখ্যা</th>
          <th class="left">ফেলকৃত শিক্ষার্থীর রোল নং</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($fail_summary)): ?>
          <tr><td colspan="3">কেউ ফেল করেনি</td></tr>
        <?php else: ?>
          <?php foreach ($fail_summary as $fc => $info): ?>
            <?php $rolls = implode(', ', array_map('strval', $info['rolls'])); ?>
            <tr>
              <td><?= (int)$fc ?></td>
              <td><?= (int)$info['count'] ?></td>
              <td class="left"><?= htmlspecialchars($rolls) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>


  <div class="grid-2">
    <div class="section">
      <h3>শাখাভিত্তিক পাস হার</h3>
      <table>
        <thead><tr><th class="left">শাখা</th><th>শিক্ষার্থী</th><th>পাস</th><th>পাস হার</th><th style="width:50%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($section_stats as $sid=>$st): $cnt=$st['count']; if($cnt<=0) continue; $pr=round(($st['pass']*100.0)/$cnt); ?>
            <tr>
              <td class="left"><?= htmlspecialchars($st['name'] !== '' ? $st['name'] : 'N/A') ?></td>
              <td><?= (int)$cnt ?></td>
              <td><?= (int)$st['pass'] ?></td>
              <td><?= $pr ?>%</td>
              <td><div class="bar blue"><span style="width: <?= $pr ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="section">
      <h3>গ্রুপভিত্তিক পাস হার</h3>
      <table>
        <thead><tr><th class="left">গ্রুপ</th><th>শিক্ষার্থী</th><th>পাস</th><th>পাস হার</th><th style="width:50%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($group_stats as $g=>$st): $cnt=$st['count']; if($cnt<=0) continue; $pr=round(($st['pass']*100.0)/$cnt); ?>
            <tr>
              <td class="left"><?= htmlspecialchars($g !== '' ? $g : 'N/A') ?></td>
              <td><?= (int)$cnt ?></td>
              <td><?= (int)$st['pass'] ?></td>
              <td><?= $pr ?>%</td>
              <td><div class="bar amber"><span style="width: <?= $pr ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid-2">
    <div class="section">
      <h3>GPA বন্টন</h3>
      <table>
        <thead><tr><th class="left">রেঞ্জ</th><th>সংখ্যা</th><th style="width:60%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($gpa_buckets as $label=>$cnt): $w = ($total_students>0)? round(($cnt*100.0)/$total_students) : 0; ?>
            <tr>
              <td class="left"><?= htmlspecialchars($label) ?></td>
              <td><?= (int)$cnt ?></td>
              <td><div class="bar violet"><span style="width: <?= $w ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="section">
      <h3>শীর্ষ ১০ শিক্ষার্থী</h3>
      <table>
        <thead><tr><th>#</th><th class="left">নাম</th><th>রোল</th><th>গ্রুপ</th><th>মোট</th><th>GPA</th></tr></thead>
        <tbody>
          <?php $i=0; foreach ($top_students as $ts): $i++; ?>
            <tr>
              <td><?= $i ?></td>
              <td class="left"><?= htmlspecialchars($ts['name']) ?></td>
              <td><?= (int)$ts['roll'] ?></td>
              <td><?= htmlspecialchars($ts['group']) ?></td>
              <td><?= (int)$ts['total'] ?></td>
              <td><?= htmlspecialchars($ts['gpa']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section">
    <h3>ফেল করা শিক্ষার্থীদের তালিকা</h3>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th class="left">শিক্ষার্থীর নাম</th>
          <th>রোল নং</th>
          <th>গ্রুপ</th>
          <th>শাখা</th>
          <th>ফেলের সংখ্যা</th>
          <th class="left">ফেলকৃত বিষয় সমূহ</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($failed_students)): ?>
          <tr>
            <td colspan="7">কোনো শিক্ষার্থী ফেল করেনি।</td>
          </tr>
        <?php else: $i=0; foreach ($failed_students as $fs): $i++; ?>
          <tr>
            <td><?= $i ?></td>
            <td class="left"><?= htmlspecialchars($fs['name']) ?></td>
            <td><?= (int)$fs['roll'] ?></td>
            <td><?= htmlspecialchars($fs['group'] ?? '') !== '' ? htmlspecialchars($fs['group']) : 'N/A' ?></td>
            <td><?= htmlspecialchars($fs['section']) !== '' ? htmlspecialchars($fs['section']) : 'N/A' ?></td>
            <td><?= (int)$fs['fail_count'] ?></td>
            <td class="left"><?= htmlspecialchars($fs['fails']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>
<div class="spacer"></div>
<div class="print-footer">কারিগরি সহযোগীতায়ঃ বাতিঘর কম্পিউটার’স</div>
</div>
</body>
</html>