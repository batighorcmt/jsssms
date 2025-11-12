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

// প্রতিষ্ঠানের তথ্য (config.php থেকে)
$institute_name = isset($institute_name) ? $institute_name : 'Institution Name';
$institute_address = isset($institute_address) ? $institute_address : '';
$institute_phone = isset($institute_phone) ? $institute_phone : '';
$institute_logo = isset($institute_logo) ? $institute_logo : '';
$logo_src = '';
if ($institute_logo !== '') {
    $logo_src = preg_match('~^https?://~', $institute_logo) ? $institute_logo : (BASE_URL . ltrim($institute_logo,'/'));
}

// পরীক্ষার নাম
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='$exam_id'"));
$exam = $examRow['exam_name'] ?? '';
$subjects_without_fourth = isset($examRow['total_subjects_without_fourth']) ? (int)$examRow['total_subjects_without_fourth'] : 0;
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

// === Custom Subject Merging ===
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

$merged_marks = [];
$individual_subjects = [];
$excluded_subject_codes = ['101', '102', '107', '108'];

// Load individual subject marks
foreach ($merged_subjects as $group_name => $sub_codes) {
    $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));

    // NOTE: Do NOT join subject_group_map here; it can create duplicate rows per subject mapping
    // and inadvertently double-count marks for merged subjects like Bangla/English.
    $sql = "SELECT m.student_id, m.subject_id, s.subject_code, 
             m.creative_marks, m.objective_marks, m.practical_marks
         FROM marks m
         JOIN subjects s ON m.subject_id = s.id
         JOIN students stu ON m.student_id = stu.student_id
         WHERE m.exam_id = ?
         AND stu.class_id = ?
         AND stu.year = ?
         AND s.subject_code IN ($placeholders)";

    $stmt = $conn->prepare($sql);

    $types = 'sss' . str_repeat('s', count($sub_codes));
    $params = array_merge([$exam_id, $class_id, $year], $sub_codes);

    $stmt->bind_param($types, ...$params);

    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $sid = $row['student_id'];
        $sub_code = $row['subject_code'];

        // Collect individual subject marks
        if (!isset($individual_subjects[$sid][$sub_code])) {
            $individual_subjects[$sid][$sub_code] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $individual_subjects[$sid][$sub_code]['creative'] += $row['creative_marks'];
        $individual_subjects[$sid][$sub_code]['objective'] += $row['objective_marks'];
        $individual_subjects[$sid][$sub_code]['practical'] += $row['practical_marks'];

        // Add to merged group
        if (!isset($merged_marks[$sid][$group_name])) {
            $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $merged_marks[$sid][$group_name]['creative'] += $row['creative_marks'];
        $merged_marks[$sid][$group_name]['objective'] += $row['objective_marks'];
        $merged_marks[$sid][$group_name]['practical'] += $row['practical_marks'];
    }
}

// Load remaining subjects (adapt to optional subjects.pass_type column)
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
    WHERE es.exam_id = '$exam_id' 
        AND gm.class_id = '$class_id'
    ORDER BY FIELD(gm.type, 'Compulsory', 'Optional'), s.subject_code
";
$subjects_q = mysqli_query($conn, $subjects_sql);


$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $subjects[] = $row;
}

// Deduplicate same subject (due to multiple group mappings); prefer Compulsory type
$dedup = [];
foreach ($subjects as $s) {
    $sid = $s['subject_id'];
    if (!isset($dedup[$sid])) {
        $dedup[$sid] = $s;
    } else {
        // prefer Compulsory when any mapping says so
        $existingType = $dedup[$sid]['subject_type'] ?? '';
        $newType = $s['subject_type'] ?? '';
        if ($existingType !== 'Compulsory' && $newType === 'Compulsory') {
            $dedup[$sid]['subject_type'] = 'Compulsory';
        }
    }
}
$subjects = array_values($dedup);

$display_subjects = [];
$merged_pass_marks = [];
$merged_added = [];
$insert_after_codes = [
    'Bangla' => '102',
    'English' => '108'
];

foreach ($subjects as $sub) {
    $code = $sub['subject_code'];
    // Effective parts for this exam: subject supports the part AND exam set full marks > 0 for that part
    $eff_has_creative = (!empty($sub['has_creative']) && intval($sub['creative_full'] ?? 0) > 0) ? 1 : 0;
    $eff_has_objective = (!empty($sub['has_objective']) && intval($sub['objective_full'] ?? 0) > 0) ? 1 : 0;
    $eff_has_practical = (!empty($sub['has_practical']) && intval($sub['practical_full'] ?? 0) > 0) ? 1 : 0;

    $sub['has_creative'] = $eff_has_creative;
    $sub['has_objective'] = $eff_has_objective;
    $sub['has_practical'] = $eff_has_practical;

    $display_subjects[] = array_merge($sub, ['is_merged' => false, 'type' => ($sub['subject_type'] ?? 'Compulsory')]);
    

    foreach ($merged_subjects as $group_name => $sub_codes) {
        if (in_array($code, $sub_codes) && $code === $insert_after_codes[$group_name] && !in_array($group_name, $merged_added)) {
            // Calculate merged subject pass marks and pass type only once
            if (!isset($merged_pass_marks[$group_name])) {
                $creative_pass = 0;
                $objective_pass = 0;
                $practical_pass = 0;
                $max_marks = 0;
                $type = 'Compulsory';
                // Default merged pass_type: 'total'; upgrade to 'individual' if any component requires it
                $merged_pass_type = 'total';
                // Track effective parts for merged subject based on exam full marks across components
                $mf_creative_full = 0;
                $mf_objective_full = 0;
                $mf_practical_full = 0;

                foreach ($subjects as $s) {
                    if (in_array($s['subject_code'], $merged_subjects[$group_name])) {
                        $creative_pass += $s['creative_pass'] ?? 0;
                        $objective_pass += $s['objective_pass'] ?? 0;
                        $practical_pass += $s['practical_pass'] ?? 0;
                        $max_marks += $s['max_marks'] ?? 0;
                        if (isset($s['subject_type'])) {
                            $type = $s['subject_type'];
                        }
                        // sum exam full marks to decide which parts exist for merged row
                        $mf_creative_full += intval($s['creative_full'] ?? 0);
                        $mf_objective_full += intval($s['objective_full'] ?? 0);
                        $mf_practical_full += intval($s['practical_full'] ?? 0);
                        // Determine effective pass_type for this component subject
                        $cmp_pass_raw = isset($s['pass_type']) ? trim((string)$s['pass_type']) : '';
                        $cmp_pass_sub  = isset($s['subject_pass_type']) ? trim((string)$s['subject_pass_type']) : '';
                        $cmp_effective = $cmp_pass_raw !== '' ? strtolower($cmp_pass_raw) : ($cmp_pass_sub !== '' ? strtolower($cmp_pass_sub) : 'total');
                        if ($cmp_effective === 'individual') {
                            $merged_pass_type = 'individual';
                        }
                    }
                }

                $merged_pass_marks[$group_name] = [
                    'creative_pass' => $creative_pass,
                    'objective_pass' => $objective_pass,
                    'practical_pass' => $practical_pass,
                    'max_marks' => $max_marks,
                    'type' => $type,
                    'pass_type' => $merged_pass_type,
                    'has_creative' => $mf_creative_full > 0 ? 1 : 0,
                    'has_objective' => $mf_objective_full > 0 ? 1 : 0,
                    'has_practical' => $mf_practical_full > 0 ? 1 : 0
                ];
            }

            // Insert merged subject now
            $display_subjects[] = [
                'subject_name' => $group_name,
                'subject_code' => $group_name,
                'has_creative' => $merged_pass_marks[$group_name]['has_creative'],
                'has_objective' => $merged_pass_marks[$group_name]['has_objective'],
                'has_practical' => $merged_pass_marks[$group_name]['has_practical'],
                'creative_pass' => $merged_pass_marks[$group_name]['creative_pass'],
                'objective_pass' => $merged_pass_marks[$group_name]['objective_pass'],
                'practical_pass' => $merged_pass_marks[$group_name]['practical_pass'],
                'max_marks' => $merged_pass_marks[$group_name]['max_marks'],
                'type' => $merged_pass_marks[$group_name]['type'],
                // Propagate resolved pass_type to merged subject so evaluation honors 'individual' when needed
                'pass_type' => $merged_pass_marks[$group_name]['pass_type'],
                'is_merged' => true
            ];
            $merged_added[] = $group_name;
        }
    }
}

// Ensure Optional subjects appear after Compulsory (fourth subject last)
$compulsory_list = [];
$optional_list = [];
foreach ($display_subjects as $ds) {
    $t = $ds['type'] ?? 'Compulsory';
    if ($t === 'Optional') $optional_list[] = $ds; else $compulsory_list[] = $ds;
}
// Sort compulsory by group order: Common/None first, then Science, Humanities, Business Studies
$groupOrder = function($name) {
    $g = strtolower(trim($name ?? ''));
    if ($g === '' || $g === 'none' || $g === 'common' || $g === 'all' || $g === 'null') return 0; // common
    if ($g === 'science' || $g === 'science group') return 1;
    if ($g === 'humanities' || $g === 'arts') return 2;
    if ($g === 'business' || $g === 'business studies' || $g === 'commerce') return 3;
    return 4; // others last
};

usort($compulsory_list, function($a, $b) use ($groupOrder) {
    $oa = $groupOrder($a['group_name'] ?? '');
    $ob = $groupOrder($b['group_name'] ?? '');
    if ($oa !== $ob) return $oa <=> $ob;
    // secondary: by numeric subject code if available, else by name
    $ca = $a['subject_code'] ?? '';
    $cb = $b['subject_code'] ?? '';
    // special placement: merged Bangla just after 102, merged English just after 108
    $isMergedA = !empty($a['is_merged']);
    $isMergedB = !empty($b['is_merged']);
    if ($isMergedA) {
        $sa = strtolower($a['subject_name'] ?? '');
        if ($sa === 'bangla') { $na = 102.1; }
        elseif ($sa === 'english') { $na = 108.1; }
        else { $na = PHP_INT_MAX - 0.5; }
    } else {
        $na = is_numeric($ca) ? (float)$ca : (float)PHP_INT_MAX;
    }
    if ($isMergedB) {
        $sb = strtolower($b['subject_name'] ?? '');
        if ($sb === 'bangla') { $nb = 102.1; }
        elseif ($sb === 'english') { $nb = 108.1; }
        else { $nb = PHP_INT_MAX - 0.5; }
    } else {
        $nb = is_numeric($cb) ? (float)$cb : (float)PHP_INT_MAX;
    }
    if ($na !== $nb) return $na <=> $nb;
    return strcmp((string)($a['subject_name'] ?? ''), (string)($b['subject_name'] ?? ''));
});

$display_subjects = array_merge($compulsory_list, $optional_list);

// Preload assigned subjects (ids and codes) per student of this class/year
$assignedByStudent = [];
$assignedCodesByStudent = [];
$subjectCodeById = [];
$assign_sql = "SELECT ss.student_id, ss.subject_id, sb.subject_code
               FROM student_subjects ss
               JOIN students st ON st.student_id = ss.student_id AND st.class_id = '".$conn->real_escape_string($class_id)."'
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
$optionalIdByStudent = [];
// Prefer explicit per-student Optional if stored in students.optional_subject_id
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $optIdRes = mysqli_query($conn, "SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND optional_subject_id IS NOT NULL");
    if ($optIdRes) {
        while ($row = mysqli_fetch_assoc($optIdRes)) {
            $sid = $row['student_id'];
            $optionalIdByStudent[$sid] = (int)$row['optional_subject_id'];
        }
    }
}
$effectiveTypeByStudent = [];
$optionalCodeByStudent = [];
// Preload effective types per student-subject (group precedence over NONE)
$optSql = "
        SELECT st.student_id,
                     s.id AS subject_id,
                     s.subject_code,
                     CASE
                         WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                             THEN CASE
                                            WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                                                THEN 'Optional'
                                            ELSE 'Compulsory'
                                        END
                             ELSE CASE
                                            WHEN SUM(CASE WHEN UPPER(gm.group_name) COLLATE utf8mb4_unicode_ci = 'NONE' COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                                                THEN 'Optional'
                                            ELSE 'Compulsory'
                                        END
                     END AS effective_type
        FROM student_subjects ss
        JOIN students st ON st.student_id = ss.student_id AND st.class_id = ? AND st.year = ?
        JOIN subjects s ON s.id = ss.subject_id
        JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = st.class_id
        GROUP BY st.student_id, s.id, s.subject_code";
$optStmt = $conn->prepare($optSql);
$optStmt->bind_param('ss', $class_id, $year);
$optStmt->execute();
$optRes = $optStmt->get_result();
while ($r = $optRes->fetch_assoc()) {
        $sidStu = $r['student_id'];
        $sidSub = (int)$r['subject_id'];
        $effectiveTypeByStudent[$sidStu][$sidSub] = $r['effective_type'];
}
// Determine each student's optional subject code ONLY from assigned subjects and effective type (no marks-based tie-breakers)
foreach ($effectiveTypeByStudent as $stuId => $typeMap) {
    $optionalCandidates = [];
    foreach ($typeMap as $subId => $etype) {
        if (strcasecmp((string)$etype, 'Optional') === 0) {
            $code = isset($subjectCodeById[$subId]) ? (string)$subjectCodeById[$subId] : '';
            if ($code !== '') {
                $optionalCandidates[$subId] = $code;
            }
        }
    }
    if (count($optionalCandidates) === 1) {
        $optionalCodeByStudent[$stuId] = (string)array_values($optionalCandidates)[0];
    } elseif (count($optionalCandidates) > 1) {
        // Deterministic tie-breaker for legacy data: choose the highest numeric subject code (e.g., prefer 138 over 126 in Science)
        $chosenCode = '';
        $maxNum = -1;
        foreach ($optionalCandidates as $code) {
            $num = is_numeric($code) ? (int)$code : -1;
            if ($num > $maxNum) { $maxNum = $num; $chosenCode = (string)$code; }
        }
        if ($chosenCode !== '') {
            $optionalCodeByStudent[$stuId] = $chosenCode;
        }
    }
}
// If explicit optional_subject_id is present, override optional code map to match exactly
if (!empty($optionalIdByStudent)) {
    foreach ($optionalIdByStudent as $stuId => $optSubId) {
        if (!empty($subjectCodeById[$optSubId])) {
            $optionalCodeByStudent[$stuId] = (string)$subjectCodeById[$optSubId];
        }
    }
}
?>
<?php
// ✅ মার্ক রিটার্ন ফাংশন
function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $stmt = $conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
    $stmt->bind_param("sss", $student_id, $subject_id, $exam_id);
    $stmt->execute();
    $marks = 0;
    $stmt->bind_result($marks);
    if ($stmt->fetch()) {
        $val = (float)$marks;
        $stmt->close();
        return $val;
    }
    $stmt->close();
    return 0;
}

// ✅ ফেল চেক ফাংশন
function isFail($marks, $pass_mark) {
    // ফেল কেবল তখনই যখন প্রাপ্ত নম্বর পাস মার্কের নিচে
    return $marks < $pass_mark;
}

// ✅ GPA ক্যালকুলেটর
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
// ✅ পাস স্ট্যাটাস চেক ফাংশন
function getPassStatus($class_id, $marks, $pass_marks, $pass_type = 'total') {
    // মোট মার্কস
    $total = ($marks['creative'] ?? 0) + ($marks['objective'] ?? 0) + ($marks['practical'] ?? 0);

    if (strtolower((string)$pass_type) === 'total') {
        // মোট পাশ মার্ক গণনা: প্রতিটি অংশের নির্ধারিত পাশ মার্কের যোগফল
        $required_total = ($pass_marks['creative'] ?? 0) + ($pass_marks['objective'] ?? 0) + ($pass_marks['practical'] ?? 0);
        return $total >= $required_total;
    } else {
        // প্রতিটি অংশে আলাদা পাস লাগবে (শুধু অন্তর্ভুক্ত অংশগুলো)
        if (isset($pass_marks['creative']) && isset($marks['creative']) && ($marks['creative'] < $pass_marks['creative'])) return false;
        if (isset($pass_marks['objective']) && isset($marks['objective']) && ($marks['objective'] < $pass_marks['objective'])) return false;
        if (isset($pass_marks['practical']) && isset($marks['practical']) && ($marks['practical'] < $pass_marks['practical'])) return false;

        return true;
    }
}
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Sheet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <style>
        th, td { vertical-align: middle !important; font-size: 13px; border-spacing: 0px; padding: 2px; }
        @media print {
            /* Ensure vivid colors and slightly larger text on print */
            html, body, table, th, td, thead, tbody {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            body { color: #000 !important; }
            .no-print { display: none !important; }
            table { font-size: 12.5px; border-spacing: 0px; padding: 2px; }
            .table-bordered th, .table-bordered td { border-color: #000 !important; }
            .table-primary { background-color: #bcdcff !important; }
            .fail-mark { color: #d10000 !important; font-weight: 700; }
            /* Disable sticky in print to avoid artifacts */
            #tabulationTable thead th { position: static !important; top: auto !important; }
            /* Remove any residual grey backgrounds from wrappers */
            .container, .header-brand, .brand-text { background: #fff !important; }
        }
        .fail-mark { color: red; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .text-center { text-align: center; }
        .table-bordered th, .table-bordered td { border: 1px solid #dee2e6; }
        .table-primary { background-color: #cfe2ff; }

        /* Sticky table header (two-row header) */
        #tabulationTable { --head1-h: 36px; }
        #tabulationTable thead tr:first-child th { position: sticky; top: 0; z-index: 5; background: #cfe2ff; }
        #tabulationTable thead tr:nth-child(2) th { position: sticky; top: var(--head1-h); z-index: 4; background: #e7f1ff; }

    /* Header brand (logo + titles) */
    .header-brand { display: flex; align-items: center; justify-content: center; gap: 12px; }
    .header-brand img.logo { width: 56px; height: 56px; object-fit: contain; }
    /* Floating print button at top-right */
    .btn-print-floating { position: fixed; top: 12px; right: 16px; z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,.2); }

.badge-pass, .badge-fail {
    display: inline-block;
    width: 22px;
    height: 22px;
    line-height: 22px;
    text-align: center;
    border-radius: 50%;
    font-size: 14px;
    color: #fff;
    font-weight: bold;
}
.badge-pass {
    background-color: #28a745; /* সবুজ */
}
.badge-fail {
    background-color: #dc3545; /* লাল */
}

    </style>
</head>
<body>
<div class="container my-4">
    <div class="mb-3">
        <div class="header-brand no-print">
            <?php if (!empty($logo_src)): ?>
                <img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" class="logo" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="brand-text text-center">
                <h3><?= htmlspecialchars($institute_name) ?></h3>
                <p><?= htmlspecialchars($institute_address) ?></p>
                <h5>Tabulation Sheet — <?= htmlspecialchars($exam) ?> | Class: <?= htmlspecialchars($class) ?> | Year: <?= htmlspecialchars($year) ?></h5>
            </div>
        </div>
        <button id="btnPrint" class="btn btn-primary no-print btn-print-floating">প্রিন্ট করুন</button>
    </div>
</div>
<!-- Print Header (only on print) -->
<div class="print-header d-print-block" style="display:none; margin:0 12px 6px 12px;">
    <?php
        $name = $institute_name ?: 'Institution Name';
        $addr = $institute_address ?: '';
        $phone = $institute_phone ?: '';
        $phLogo = $logo_src;
    ?>
    <div class="ph-grid">
        <div class="ph-left">
            <?php if (!empty($phLogo)): ?>
            <img src="<?= htmlspecialchars($phLogo) ?>" alt="Logo" onerror="this.style.display='none'">
            <?php endif; ?>
        </div>
        <div class="ph-center">
            <div class="ph-name"><?= htmlspecialchars($name) ?></div>
            <div class="ph-meta">
                <?= htmlspecialchars($addr) ?>
            </div>
            <div class="ph-title">Tabulation Sheet</div>
            <div class="ph-sub">Exam: <?= htmlspecialchars($exam) ?> | Class: <?= htmlspecialchars($class) ?> | Year: <?= htmlspecialchars($year) ?> | Printed: <?= date('d/m/Y') ?></div>
        </div>
        <div class="ph-right"></div>
    </div>
    <hr style="margin:4px 0;" />
    <style>
        @media print { .print-header{display:block !important;} }
        .ph-grid{ display:grid; grid-template-columns:auto 1fr auto; align-items:center; }
        .ph-left img{ height:60px; object-fit:contain; display:block; }
        .ph-center{ text-align:center; }
    .ph-name{ font-size:26px; font-weight:800; line-height:1.1; margin:0; }
    .ph-meta{ font-size:12px; margin:3px 0 0 0; line-height:1.2; }
    .ph-title{ font-size:17px; font-weight:700; margin:6px 0 0 0; display:inline-block; padding:2px 8px; border-radius:4px; background:#eef3ff; border:1px solid #cdd9ff; }
    .ph-sub{ font-size:13px; margin:4px 0 0 0; line-height:1.25; }
    </style>
</div>
<div class="align-middle">
    <table id="tabulationTable" class="table table-bordered text-center align-middle original">
        <thead class="table-primary">
            <tr>
                <th rowspan="2">#</th>
                <th rowspan="2">Student Name</th>
                <th id="thRoll" class="sortable" data-sort="roll" rowspan="2">Roll</th>
                <th id="thMerit" class="sortable" data-sort="merit" rowspan="2">Merit Position</th>
                <th rowspan="2">Group</th>
                <?php $__si=0; foreach ($display_subjects as $sub): 
                    $colspan = $sub['has_creative'] + $sub['has_objective'] + $sub['has_practical'] + 2;
                ?>
                    <th class="subject-group subgrp-<?php echo $__si; ?>" data-sub-index="<?php echo $__si; ?>" colspan="<?= $colspan ?>">
                        <?= $sub['subject_name'] ?><br>
                        <small>(<?= $sub['subject_code'] ?>)</small>
                    </th>
                <?php $__si++; endforeach; ?>
                <th rowspan="2">Optional Subject Code</th>
                <th rowspan="2">Total</th>
                <th rowspan="2">GPA</th>
                <th rowspan="2">Status</th>
                <th rowspan="2">Fail Count</th>
            </tr>
            <tr>
                <?php $__si=0; foreach ($display_subjects as $sub): ?>
                    <?php if ($sub['has_creative']) echo "<th class='subgrp-{$__si}'>C</th>"; ?>
                    <?php if ($sub['has_objective']) echo "<th class='subgrp-{$__si}'>O</th>"; ?>
                    <?php if ($sub['has_practical']) echo "<th class='subgrp-{$__si}'>P</th>"; ?>
                    <th class='subgrp-<?= $__si ?>'>Total</th>
                    <th class='subgrp-<?= $__si ?>'>GPA</th>
                <?php $__si++; endforeach; ?>
            </tr>
        </thead>
<tbody>
<?php
$all_students = [];
$query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year" . " ORDER BY roll_no";
$students_q = mysqli_query($conn, $query);
if (!$students_q) {
    echo "<tr><td colspan='100%' class='text-danger'>Error fetching students: " . mysqli_error($conn) . "</td></tr>";
    exit;
}

$excluded_subject_codes = [101, 102, 107, 108];

while ($stu = mysqli_fetch_assoc($students_q)) {
    $total_marks = 0;
    $fail_count = 0;
    $row_data = "";
    // Use pre-computed student's optional subject id/code if available (id has highest priority)
    $student_optional_id = isset($optionalIdByStudent[$stu['student_id']]) ? (int)$optionalIdByStudent[$stu['student_id']] : 0;
    $student_optional_code = isset($optionalCodeByStudent[$stu['student_id']]) ? (string)$optionalCodeByStudent[$stu['student_id']] : '';

    $compulsory_gpa_total = 0;
    $compulsory_gpa_subjects = 0;
    $optional_gpas = [];

    $__si = 0;
    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null;
        if (!$subject_code) continue;

        $fail = false;
        $sub_total = 0;
        $c = $o = $p = 0;

        // Determine if this student has this subject (per add_subjects selections)
        $skipSubject = false;
        if (!empty($sub['is_merged'])) {
            $group = $sub['subject_name'];
            // For merged groups, require all component codes to be assigned
            $codes = $merged_subjects[$group] ?? [];
            $hasAll = true;
            foreach ($codes as $codeReq) {
                if (empty($assignedCodesByStudent[$stu['student_id']][(string)$codeReq])) { $hasAll = false; break; }
            }
            if (!$hasAll) {
                $skipSubject = true;
            } else {
                $c = $merged_marks[$stu['student_id']][$group]['creative'] ?? 0;
                $o = $merged_marks[$stu['student_id']][$group]['objective'] ?? 0;
                $p = $merged_marks[$stu['student_id']][$group]['practical'] ?? 0;
            }
        } else {
            // Non-merged: check if this subject_id is assigned to this student
            $sidSub = (int)($sub['subject_id'] ?? 0);
            if (empty($assignedByStudent[$stu['student_id']][$sidSub])) {
                $skipSubject = true;
            } else {
                $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'creative') : 0;
                $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'objective') : 0;
                $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'practical') : 0;
            }
        }

        // If subject not assigned to this student, render '-' cells and skip counting
        if ($skipSubject) {
            if (!empty($sub['has_creative'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            if (!empty($sub['has_objective'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            if (!empty($sub['has_practical'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            $row_data .= "<td class='subgrp-{$__si}'></td>"; // Total
            $row_data .= "<td class='subgrp-{$__si}'></td>"; // GPA
            $__si++;
            continue; // do not include in totals or fail counts
        }

        $sub_total = $c + $o + $p;

        // Show marks (per-part cells get red highlight if below pass or zero)
        if ($sub['has_creative']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($c, $sub['creative_pass']) ? "<span class='fail-mark'>$c</span>" : $c) . "</td>";
        if ($sub['has_objective']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($o, $sub['objective_pass']) ? "<span class='fail-mark'>$o</span>" : $o) . "</td>";
        if ($sub['has_practical']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($p, $sub['practical_pass']) ? "<span class='fail-mark'>$p</span>" : $p) . "</td>";

        if (!in_array($subject_code, $excluded_subject_codes)) {
            // ✅ সঠিক নাম ব্যবহার করুন
            $creative = $c;
            $objective = $o;
            $practical = $p;

            // ✅ pass_type: exam_subjects.pass_type → fallback subjects.pass_type → 'total'
            $pass_type_raw = isset($sub['pass_type']) ? trim((string)$sub['pass_type']) : '';
            $pass_type_sub = isset($sub['subject_pass_type']) ? trim((string)$sub['subject_pass_type']) : '';
            $pass_type = $pass_type_raw !== '' ? $pass_type_raw : ($pass_type_sub !== '' ? $pass_type_sub : 'total');
            $pass_type = strtolower($pass_type);
            // Honor configured pass_type: 'total' => total-based pass; 'individual' => per-part pass
            // Determine compulsory/optional per-student: treat the student's chosen optional subject_code as Optional; others Compulsory
            if (empty($sub['is_merged'])) {
                // Prefer explicit optional by subject_id, else fallback to code match
                if (!empty($student_optional_id) && isset($sidSub) && $sidSub === $student_optional_id) {
                    $is_compulsory = false;
                } elseif (!empty($student_optional_code) && (string)$subject_code === (string)$student_optional_code) {
                    $is_compulsory = false; // chosen optional by code
                } else {
                    $is_compulsory = true;
                }
            } else {
                $is_compulsory = true; // merged Bangla/English are compulsory
            }

            // Evaluate subject-level pass/fail strictly according to pass_type and per-part thresholds
            // Consider only parts that exist for this subject to avoid penalizing non-existent parts
            $pm = [];
            $mk = [];
            if (!empty($sub['has_creative'])) { $pm['creative'] = (int)($sub['creative_pass'] ?? 0); $mk['creative'] = $creative; }
            if (!empty($sub['has_objective'])) { $pm['objective'] = (int)($sub['objective_pass'] ?? 0); $mk['objective'] = $objective; }
            if (!empty($sub['has_practical'])) { $pm['practical'] = (int)($sub['practical_pass'] ?? 0); $mk['practical'] = $practical; }
            $isPass = getPassStatus($class_id, $mk, $pm, $pass_type);
            if (!$isPass) {
                if ($is_compulsory) { $fail_count++; }
                $fail = true;
            }

            // Now append Total cell; show red if subject failed per resolved pass_type
            $row_data .= "<td class='subgrp-{$__si}'>" . ($fail ? "<span class='fail-mark'>$sub_total</span>" : $sub_total) . "</td>";

            // Subject GPA should be 0.00 if subject failed in any part
            $gpa = $fail ? 0.00 : subjectGPA($sub_total, $sub['max_marks']);
            $row_data .= "<td class='subgrp-{$__si}'>" . number_format($gpa, 2) . "</td>";

            if ($is_compulsory) {
                $compulsory_gpa_total += $gpa;
                $compulsory_gpa_subjects++;
            } else {
                // Optional for this student
                $optional_gpas[] = $gpa;
            }

            $total_marks += $sub_total;
        } else {
            // Excluded subjects (e.g., individual English/Bangla papers):
            // still show the subject total cell, but GPA is not applicable here.
            $row_data .= "<td class='subgrp-{$__si}'>$sub_total</td>"; // Total
            $row_data .= "<td class='subgrp-{$__si}'>-</td>"; // GPA not applicable
        }
        $__si++;
    }

    $optional_bonus = 0.00;
    if (!empty($optional_gpas)) {
        $max_optional = max($optional_gpas);
        if ($max_optional > 2.00) {
            $optional_bonus = $max_optional - 2.00;
        }
    }

    $final_gpa = 0.00;
    // Use configured divisor if provided for Class 9/10; fallback to counted compulsory subjects
    $divisor = ($subjects_without_fourth > 0) ? min($subjects_without_fourth, $compulsory_gpa_subjects) : $compulsory_gpa_subjects;
    if ($divisor > 0) {
        $final_gpa = ($compulsory_gpa_total + $optional_bonus) / $divisor;
    }
    if ($fail_count > 0) $final_gpa = 0.00;

    $final_gpa = number_format(min($final_gpa, 5.00), 2);
    $fail_display = $fail_count > 0 ? $fail_count : "";

    $status = ($fail_count == 0)
        ? '<i class="bi bi-check-circle-fill text-success"></i> Passed'
        : '<i class="bi bi-x-circle-fill text-danger"></i> Failed';

    $all_students[] = [
        'fail' => $fail_count,
        'total' => $total_marks,
        'roll' => isset($stu['roll_no']) ? (int)$stu['roll_no'] : 0,
        'row' => "<tr><td></td><td>{$stu['student_name']}</td><td>{$stu['roll_no']}</td><td class='merit'></td><td>" . (!empty($stu['student_group']) ? htmlspecialchars($stu['student_group']) : '') . "</td>$row_data<td>" . htmlspecialchars($student_optional_code) . "</td><td><strong>$total_marks</strong></td><td><strong>$final_gpa</strong></td><td>$status</td><td>$fail_display</td></tr>"
    ];
}

// ✅ Updated Merit Position Logic with Roll Number Tie-Breaker
$ranked_students = $all_students;
usort($ranked_students, function ($a, $b) {
    return $a['fail'] <=> $b['fail']   // Fewer fails first (ascending)
        ?: $b['total'] <=> $a['total'] // Higher totals next (descending)
        ?: $a['roll'] <=> $b['roll'];  // Lower roll numbers next (ascending)
});

// ✅ Render rows with merit position
foreach ($ranked_students as $i => $stu) {
    $serial = $i + 1;
    $row = preg_replace('/<td><\/td>/', "<td>$serial</td>", $stu['row'], 1);
    $merit_pos = $i + 1; // Merit position is same as serial in sorted list
    $row = preg_replace('/<td class=\'merit\'><\/td>/', "<td>$merit_pos</td>", $row, 1);
    echo $row;
}
?>
</tbody>
     </table>
     <div id="printSplitContainer" class="print-split" style="display:none"></div>
</div>
<script>
// Adjust sticky header offset based on first header row height
(function(){
    function updateStickyOffset(){
        var table = document.getElementById('tabulationTable');
        if(!table) return;
        var firstRow = table.querySelector('thead tr:first-child');
        if(!firstRow) return;
        var h = firstRow.getBoundingClientRect().height;
        table.style.setProperty('--head1-h', h + 'px');
    }
    window.addEventListener('load', updateStickyOffset);
    window.addEventListener('resize', updateStickyOffset);
})();

// Sorting by Roll and Merit
(function(){
    const table = document.getElementById('tabulationTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    function sortBy(colIndex, numeric=true, asc=true){
        const rows = Array.from(tbody.querySelectorAll('tr'));
        rows.sort((ra, rb)=>{
            const a = ra.children[colIndex]?.innerText.trim() || '';
            const b = rb.children[colIndex]?.innerText.trim() || '';
            const av = numeric ? parseFloat(a)||0 : a.toLowerCase();
            const bv = numeric ? parseFloat(b)||0 : b.toLowerCase();
            if (av<bv) return asc?-1:1;
            if (av>bv) return asc?1:-1;
            return 0;
        });
        rows.forEach((tr,i)=>{ tr.children[0].textContent = (i+1); tbody.appendChild(tr); });
    }
    function toggleSortOn(thId, colIndex){
        const th = document.getElementById(thId);
        if (!th) return;
        let asc = true;
        th.style.cursor = 'pointer';
        th.addEventListener('click', ()=>{ sortBy(colIndex, true, asc); asc=!asc; });
    }
    // Columns: 0 #, 1 Name, 2 Roll, 3 Merit, 4 Group
    toggleSortOn('thRoll', 2);
    toggleSortOn('thMerit', 3);
})();

// Smart print: split subjects into two halves keeping fixed left columns
(function(){
    const btn = document.getElementById('btnPrint');
    const table = document.getElementById('tabulationTable');
    const container = document.getElementById('printSplitContainer');
    if (!btn || !table || !container) return;
    btn.addEventListener('click', function(){
        // count subject groups
        const groups = Array.from(table.querySelectorAll('thead .subject-group'));
        const n = groups.length; if (n===0){ window.print(); return; }

        // If subjects are 12 or fewer: print in a single part (no split)
        if (n <= 12) {
            // Temporarily ensure original table is not hidden by print CSS
            const hadOriginal = table.classList.contains('original');
            if (hadOriginal) table.classList.remove('original');
            const prevDisplay = container.style.display;
            container.style.display = 'none';
            const restore = function(){
                if (hadOriginal) table.classList.add('original');
                container.style.display = prevDisplay;
                window.removeEventListener('afterprint', restore);
            };
            window.addEventListener('afterprint', restore);
            window.print();
            return;
        }

        // Else: split into two parts for printing
        // Move one subject from first part to second part (left half a bit narrower)
        const mid = Math.max(0, Math.ceil(n/2) + 1);
        // helper to clone and hide sets by class
        function buildClone(hideStart, hideEnd){
            const clone = table.cloneNode(true);
            // Remove id to avoid duplicates and drop 'original' class so it's not hidden in print
            clone.id = '';
            if (clone.classList) { clone.classList.remove('original'); }

            // Hide subject groups outside desired range and then recompute header colspans
            for(let i=0;i<n;i++){
                const keep = (i>=hideStart && i<hideEnd);
                // hide/show all cells that belong to this subject group
                clone.querySelectorAll('.subgrp-'+i).forEach(el=>{ el.style.display = keep ? '' : 'none'; });
            }

            // Recompute colspan for remaining subject-group header cells so merged headers don't break
            const headerGroups = Array.from(clone.querySelectorAll('thead .subject-group'));
            headerGroups.forEach(th => {
                const idx = th.getAttribute('data-sub-index');
                if (idx === null) return;
                // Count visible lower-row header cells for this subject index
                const visible = clone.querySelectorAll(`thead tr:nth-child(2) .subgrp-${idx}`);
                let visibleCount = 0;
                visible.forEach(v => { if (v.style.display !== 'none') visibleCount++; });
                // Set colspan to number of visible lower headers (Total/GPA + parts)
                th.setAttribute('colspan', visibleCount > 0 ? visibleCount : 0);

                // Abbreviate very long subject names for print clones (e.g., Information And Communication Technology -> ICT)
                const txt = (th.innerText || '').toLowerCase();
                if (txt.indexOf('information') !== -1 && txt.indexOf('communication') !== -1) {
                    const codeEl = th.querySelector('small');
                    const codeTxt = codeEl ? codeEl.innerText : '';
                    th.innerHTML = 'ICT' + (codeTxt ? '<br><small>' + codeTxt + '</small>' : '');
                }
            });

            return clone;
        }
        container.innerHTML = '';
        const left = buildClone(0, mid);
        const right = buildClone(mid, n);
        // Optionally hide overall summary columns in left copy (not per-subject)
        left.querySelectorAll('thead th').forEach(th=>{
            const t = th.innerText.trim();
            const hasSubgrp = (th.className||'').indexOf('subgrp-') !== -1;
            if (!hasSubgrp && ['Optional Subject Code','Total','GPA','Status','Fail Count'].includes(t)) th.style.display='none';
        });
        left.querySelectorAll('tbody tr').forEach(tr=>{
            // hide summary tds at the end (last 5 cells)
            for(let k=0;k<5;k++){
                const td = tr.children[tr.children.length-1-k]; if (td) td.style.display='none';
            }
        });
        container.appendChild(left);
        const brk = document.createElement('div'); brk.style.pageBreakAfter='always'; container.appendChild(brk);
        container.appendChild(right);
        // Show split container in print
        container.style.display='block';
        window.print();
        // after print, hide split container
        setTimeout(()=>{ container.style.display='none'; }, 1000);
    });
})();
</script>
<style>
@media print {
    @page { size: landscape; margin: 10mm; }
    .original { display: none !important; }
    .print-split { display: block !important; }
    /* Branding footer small and right aligned */
    .print-brand { margin:8px 12px 0 12px; font-size:11px; text-align:right; color:#333; }
}
</style>
<!-- Print-only branding footer -->
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
</body>
</html>