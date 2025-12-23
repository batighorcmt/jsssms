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
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='".$conn->real_escape_string($exam_id)."'"));
$exam = $examRow['exam_name'] ?? '';
$subjects_without_fourth = isset($examRow['total_subjects_without_fourth']) ? (int)$examRow['total_subjects_without_fourth'] : 0;
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='".$conn->real_escape_string($class_id)."'"))['class_name'];

// Load subjects for this class & exam (no merging)
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
    // Effective parts: only include parts with full marks > 0 in exam config
    $row['has_creative'] = (!empty($row['has_creative']) && intval($row['creative_full'] ?? 0) > 0) ? 1 : 0;
    $row['has_objective'] = (!empty($row['has_objective']) && intval($row['objective_full'] ?? 0) > 0) ? 1 : 0;
    $row['has_practical'] = (!empty($row['has_practical']) && intval($row['practical_full'] ?? 0) > 0) ? 1 : 0;
    $subjects[] = array_merge($row, ['is_merged' => false, 'type' => ($row['subject_type'] ?? 'Compulsory')]);
}
// Deduplicate by subject_id preferring Compulsory type
$dedup = [];
foreach ($subjects as $s) { $sid = $s['subject_id']; if (!isset($dedup[$sid])) { $dedup[$sid] = $s; } else { if (($dedup[$sid]['subject_type'] ?? '') !== 'Compulsory' && ($s['subject_type'] ?? '') === 'Compulsory') { $dedup[$sid]['subject_type'] = 'Compulsory'; } } }
$display_subjects = array_values($dedup);

// Ensure Optional subjects appear after Compulsory
$compulsory_list = []; $optional_list = [];
foreach ($display_subjects as $ds) { $t = $ds['type'] ?? 'Compulsory'; if ($t === 'Optional') $optional_list[] = $ds; else $compulsory_list[] = $ds; }
// Sort compulsory by group order then code/name
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

// Assigned subjects (ids and codes) per student
$assignedByStudent = []; $assignedCodesByStudent = []; $subjectCodeById = [];
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

// Optional mapping
$optionalIdByStudent = [];
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $optIdRes = mysqli_query($conn, "SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND optional_subject_id IS NOT NULL");
    if ($optIdRes) {
        while ($row = mysqli_fetch_assoc($optIdRes)) {
            $optionalIdByStudent[$row['student_id']] = (int)$row['optional_subject_id'];
        }
    }
}
$effectiveTypeByStudent = []; $optionalCodeByStudent = [];
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
// Determine each student's optional subject code ONLY from assigned subjects and effective type
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
?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>Tabulation Sheet (Unmerged)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <style>
        th, td { vertical-align: middle !important; font-size: 13px; border-spacing: 0px; padding: 2px; }
        @media print {
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
            #tabulationTable thead th { position: static !important; top: auto !important; }
            .container, .header-brand, .brand-text { background: #fff !important; }
        }
        .fail-mark { color: red; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        .text-center { text-align: center; }
        .table-bordered th, .table-bordered td { border: 1px solid #dee2e6; }
        .table-primary { background-color: #cfe2ff; }

        #tabulationTable { --head1-h: 36px; }
        #tabulationTable thead tr:first-child th { position: sticky; top: 0; z-index: 5; background: #cfe2ff; }
        #tabulationTable thead tr:nth-child(2) th { position: sticky; top: var(--head1-h); z-index: 4; background: #e7f1ff; }

        .header-brand { display: flex; align-items: center; justify-content: center; gap: 12px; }
        .header-brand img.logo { width: 56px; height: 56px; object-fit: contain; }
        .btn-print-floating { position: fixed; top: 12px; right: 16px; z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,.2); }
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
                <h5>Tabulation Sheet (Unmerged) — <?= htmlspecialchars($exam) ?> | Class: <?= htmlspecialchars($class) ?> | Year: <?= htmlspecialchars($year) ?></h5>
            </div>
        </div>
        <button id="btnPrint" type="button" class="btn btn-primary no-print btn-print-floating">প্রিন্ট করুন</button>
    </div>
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
$query = "SELECT * FROM students WHERE class_id = '".$conn->real_escape_string($class_id)."' AND year = '".$conn->real_escape_string($year)."' ORDER BY roll_no";
$students_q = mysqli_query($conn, $query);
if (!$students_q) {
    echo "<tr><td colspan='100%' class='text-danger'>Error fetching students: " . mysqli_error($conn) . "</td></tr>";
    exit;
}

while ($stu = mysqli_fetch_assoc($students_q)) {
    $total_marks = 0;
    $fail_count = 0;
    $row_data = "";
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

        // Non-merged: check if this subject_id is assigned to this student
        $sidSub = (int)($sub['subject_id'] ?? 0);
        $skipSubject = empty($assignedByStudent[$stu['student_id']][$sidSub]);
        if (!$skipSubject) {
            $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'creative') : 0;
            $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'objective') : 0;
            $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sidSub, $exam_id, 'practical') : 0;
        }

        if ($skipSubject) {
            if (!empty($sub['has_creative'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            if (!empty($sub['has_objective'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            if (!empty($sub['has_practical'])) $row_data .= "<td class='subgrp-{$__si}'></td>";
            $row_data .= "<td class='subgrp-{$__si}'></td>"; // Total
            $row_data .= "<td class='subgrp-{$__si}'></td>"; // GPA
            $__si++;
            continue;
        }

        $sub_total = $c + $o + $p;

        // Show marks
        if ($sub['has_creative']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($c, $sub['creative_pass']) ? "<span class='fail-mark'>$c</span>" : $c) . "</td>";
        if ($sub['has_objective']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($o, $sub['objective_pass']) ? "<span class='fail-mark'>$o</span>" : $o) . "</td>";
        if ($sub['has_practical']) $row_data .= "<td class='subgrp-{$__si}'>" . (isFail($p, $sub['practical_pass']) ? "<span class='fail-mark'>$p</span>" : $p) . "</td>";

        // pass_type resolve
        $pass_type_raw = isset($sub['pass_type']) ? trim((string)$sub['pass_type']) : '';
        $pass_type_sub = isset($sub['subject_pass_type']) ? trim((string)$sub['subject_pass_type']) : '';
        $pass_type = strtolower($pass_type_raw !== '' ? $pass_type_raw : ($pass_type_sub !== '' ? $pass_type_sub : 'total'));

        // Determine compulsory vs optional for this student
        $is_compulsory = true;
        if (!empty($student_optional_id) && isset($sidSub) && $sidSub === $student_optional_id) {
            $is_compulsory = false;
        } elseif (!empty($student_optional_code) && (string)$subject_code === (string)$student_optional_code) {
            $is_compulsory = false;
        }

        // Evaluate pass/fail
        $pm = []; $mk = [];
        if (!empty($sub['has_creative'])) { $pm['creative'] = (int)($sub['creative_pass'] ?? 0); $mk['creative'] = $c; }
        if (!empty($sub['has_objective'])) { $pm['objective'] = (int)($sub['objective_pass'] ?? 0); $mk['objective'] = $o; }
        if (!empty($sub['has_practical'])) { $pm['practical'] = (int)($sub['practical_pass'] ?? 0); $mk['practical'] = $p; }
        $isPass = getPassStatus($class_id, $mk, $pm, $pass_type);
        if (!$isPass && $is_compulsory) { $fail_count++; $fail = true; }

        // Total cell (red if fail)
        $row_data .= "<td class='subgrp-{$__si}'>" . ($fail ? "<span class='fail-mark'>$sub_total</span>" : $sub_total) . "</td>";
        // GPA cell
        $gpa = $fail ? 0.00 : max(1.00, subjectGPA($sub_total, $sub['max_marks']));
        $row_data .= "<td class='subgrp-{$__si}'>" . number_format($gpa, 2) . "</td>";

        if ($is_compulsory) { $compulsory_gpa_total += $gpa; $compulsory_gpa_subjects++; } else { $optional_gpas[] = $gpa; }
        $total_marks += $sub_total;
        $__si++;
    }

    // Optional bonus
    $optional_bonus = 0.00;
    if (!empty($optional_gpas)) {
        $max_optional = max($optional_gpas);
        if ($max_optional > 2.00) {
            $optional_bonus = $max_optional - 2.00;
        }
    }

    // Final GPA
    $final_gpa = 0.00;
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
        'row' => "<tr><td></td><td>".htmlspecialchars($stu['student_name'])."</td><td>".htmlspecialchars($stu['roll_no'])."</td><td class='merit'></td><td>" . (!empty($stu['student_group']) ? htmlspecialchars($stu['student_group']) : '') . "</td>$row_data<td>" . htmlspecialchars($student_optional_code) . "</td><td><strong>$total_marks</strong></td><td><strong>$final_gpa</strong></td><td>$status</td><td>$fail_display</td></tr>"
    ];
}

// Ranking
$ranked_students = $all_students;
usort($ranked_students, function ($a, $b) {
    return $a['fail'] <=> $b['fail']
        ?: $b['total'] <=> $a['total']
        ?: $a['roll'] <=> $b['roll'];
});

// Render rows
foreach ($ranked_students as $i => $stu) {
    $serial = $i + 1;
    $row = preg_replace('/<td><\/td>/', "<td>$serial</td>", $stu['row'], 1);
    $merit_pos = $i + 1;
    $row = preg_replace('/<td class=\'merit\'><\/td>/', "<td>$merit_pos</td>", $row, 1);
    echo $row;
}
?>
</tbody>
     </table>
</div>
<script>
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
    toggleSortOn('thRoll', 2);
    toggleSortOn('thMerit', 3);
})();

// Print handler
(function(){
    var btn = document.getElementById('btnPrint');
    if (!btn) return;
    btn.addEventListener('click', function(e){
        e.preventDefault();
        try { window.print(); } catch (err) { console && console.error && console.error(err); }
    });
})();
</script>
</body>
</html>
