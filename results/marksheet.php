<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';
$search_roll = $_GET['search_roll'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// প্রতিষ্ঠানের তথ্য
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";
$institute_logo = "../assets/logo.png";


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

// Load individual subject marks for merged groups (avoid subject_group_map to prevent duplicates)
foreach ($merged_subjects as $group_name => $sub_codes) {
    $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));

    $sql = "SELECT m.student_id, m.subject_id, s.subject_code, 
             m.creative_marks, m.objective_marks, m.practical_marks
         FROM marks m
         JOIN subjects s ON m.subject_id = s.id
         JOIN students stu ON m.student_id = stu.student_id
         WHERE m.exam_id = ?
         AND stu.class_id = ?
         AND stu.year = ?
         AND stu.status = 'Active'
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

        if (!isset($individual_subjects[$sid][$sub_code])) {
            $individual_subjects[$sid][$sub_code] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $individual_subjects[$sid][$sub_code]['creative'] += $row['creative_marks'];
        $individual_subjects[$sid][$sub_code]['objective'] += $row['objective_marks'];
        $individual_subjects[$sid][$sub_code]['practical'] += $row['practical_marks'];

        if (!isset($merged_marks[$sid][$group_name])) {
            $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $merged_marks[$sid][$group_name]['creative'] += $row['creative_marks'];
        $merged_marks[$sid][$group_name]['objective'] += $row['objective_marks'];
        $merged_marks[$sid][$group_name]['practical'] += $row['practical_marks'];
    }
}

// Load remaining subjects (with exam full marks and per-part pass; also pass_type from exam/subject)
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
while ($row = mysqli_fetch_assoc($subjects_q)) { $subjects[] = $row; }

// Deduplicate same subject; prefer Compulsory mapping
$dedup = [];
foreach ($subjects as $s) {
    $sid = $s['subject_id'];
    if (!isset($dedup[$sid])) {
        $dedup[$sid] = $s;
    } else {
        $existingType = $dedup[$sid]['subject_type'] ?? '';
        $newType = $s['subject_type'] ?? '';
        if ($existingType !== 'Compulsory' && $newType === 'Compulsory') {
            $dedup[$sid]['subject_type'] = 'Compulsory';
        }
    }
}
$subjects = array_values($dedup);

// Map subject code to name (for optional subject name display)
$allSubjectNameByCode = [];
$__subMapRes = mysqli_query($conn, "SELECT subject_code, subject_name FROM subjects");
if ($__subMapRes) {
    while ($__r = mysqli_fetch_assoc($__subMapRes)) {
        $codeKey = (string)($__r['subject_code'] ?? '');
        if ($codeKey !== '') { $allSubjectNameByCode[$codeKey] = (string)($__r['subject_name'] ?? ''); }
    }
}

$display_subjects = [];
$merged_pass_marks = [];
$merged_added = [];
$insert_after_codes = [ 'Bangla' => '102', 'English' => '108' ];

foreach ($subjects as $sub) {
    $code = $sub['subject_code'];
    // Effective parts for this exam: subject supports the part AND exam set full marks > 0
    $eff_has_creative = (!empty($sub['has_creative']) && intval($sub['creative_full'] ?? 0) > 0) ? 1 : 0;
    $eff_has_objective = (!empty($sub['has_objective']) && intval($sub['objective_full'] ?? 0) > 0) ? 1 : 0;
    $eff_has_practical = (!empty($sub['has_practical']) && intval($sub['practical_full'] ?? 0) > 0) ? 1 : 0;

    $sub['has_creative'] = $eff_has_creative;
    $sub['has_objective'] = $eff_has_objective;
    $sub['has_practical'] = $eff_has_practical;

    $display_subjects[] = array_merge($sub, ['is_merged' => false, 'type' => ($sub['subject_type'] ?? 'Compulsory')]);

    foreach ($merged_subjects as $group_name => $sub_codes) {
        if (in_array($code, $sub_codes) && $code === $insert_after_codes[$group_name] && !in_array($group_name, $merged_added)) {
            if (!isset($merged_pass_marks[$group_name])) {
                $creative_pass = 0; $objective_pass = 0; $practical_pass = 0; $max_marks = 0; $type = 'Compulsory';
                $merged_pass_type = 'total';
                $mf_creative_full = 0; $mf_objective_full = 0; $mf_practical_full = 0;
                foreach ($subjects as $s) {
                    if (in_array($s['subject_code'], $merged_subjects[$group_name])) {
                        $creative_pass += $s['creative_pass'] ?? 0;
                        $objective_pass += $s['objective_pass'] ?? 0;
                        $practical_pass += $s['practical_pass'] ?? 0;
                        $max_marks += $s['max_marks'] ?? 0;
                        if (isset($s['subject_type'])) { $type = $s['subject_type']; }
                        $mf_creative_full += intval($s['creative_full'] ?? 0);
                        $mf_objective_full += intval($s['objective_full'] ?? 0);
                        $mf_practical_full += intval($s['practical_full'] ?? 0);
                        $cmp_pass_raw = isset($s['pass_type']) ? trim((string)$s['pass_type']) : '';
                        $cmp_pass_sub  = isset($s['subject_pass_type']) ? trim((string)$s['subject_pass_type']) : '';
                        $cmp_effective = $cmp_pass_raw !== '' ? strtolower($cmp_pass_raw) : ($cmp_pass_sub !== '' ? strtolower($cmp_pass_sub) : 'total');
                        if ($cmp_effective === 'individual') { $merged_pass_type = 'individual'; }
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
                'pass_type' => $merged_pass_marks[$group_name]['pass_type'],
                'is_merged' => true
            ];
            $merged_added[] = $group_name;
        }
    }
}

// Ensure Optional subjects appear after Compulsory
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
usort($compulsory_list, function($a,$b) use ($groupOrder){
    $oa = $groupOrder($a['group_name'] ?? ''); $ob = $groupOrder($b['group_name'] ?? ''); if ($oa !== $ob) return $oa <=> $ob;
    $ca = $a['subject_code'] ?? ''; $cb = $b['subject_code'] ?? '';
    $isMergedA = !empty($a['is_merged']); $isMergedB = !empty($b['is_merged']);
    if ($isMergedA) { $sa = strtolower($a['subject_name'] ?? ''); if ($sa==='bangla') { $na=102.1; } elseif($sa==='english'){ $na=108.1; } else { $na=PHP_INT_MAX-0.5; } }
    else { $na = is_numeric($ca)?(float)$ca:(float)PHP_INT_MAX; }
    if ($isMergedB) { $sb = strtolower($b['subject_name'] ?? ''); if ($sb==='bangla') { $nb=102.1; } elseif($sb==='english'){ $nb=108.1; } else { $nb=PHP_INT_MAX-0.5; } }
    else { $nb = is_numeric($cb)?(float)$cb:(float)PHP_INT_MAX; }
    if ($na !== $nb) return $na <=> $nb; return strcmp((string)($a['subject_name'] ?? ''),(string)($b['subject_name'] ?? ''));
});
$display_subjects = array_merge($compulsory_list, $optional_list);

// Preload assigned subjects and codes
$assignedByStudent = []; $assignedCodesByStudent = []; $subjectCodeById = [];
$assign_sql = "SELECT ss.student_id, ss.subject_id, sb.subject_code
               FROM student_subjects ss
               JOIN students st ON st.student_id = ss.student_id AND st.class_id = '".$conn->real_escape_string($class_id)."' AND st.status = 'Active'
               JOIN subjects sb ON sb.id = ss.subject_id";
$assign_res = mysqli_query($conn, $assign_sql);
if ($assign_res) {
    while ($ar = mysqli_fetch_assoc($assign_res)) {
        $sid = $ar['student_id']; if (!isset($assignedByStudent[$sid])) { $assignedByStudent[$sid]=[]; $assignedCodesByStudent[$sid]=[]; }
        $subId = (int)$ar['subject_id']; $subCode = (string)$ar['subject_code'];
        $assignedByStudent[$sid][$subId] = true; $assignedCodesByStudent[$sid][$subCode] = true; $subjectCodeById[$subId] = $subCode;
    }
}
// Optional by student
$optionalIdByStudent = [];
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $optIdRes = mysqli_query($conn, "SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND status='Active' AND optional_subject_id IS NOT NULL");
    if ($optIdRes) { while ($row = mysqli_fetch_assoc($optIdRes)) { $optionalIdByStudent[$row['student_id']] = (int)$row['optional_subject_id']; } }
}
// Effective type map per student/subject
$effectiveTypeByStudent = [];
$optionalCodeByStudent = [];
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
        JOIN students st ON st.student_id = ss.student_id AND st.class_id = ? AND st.year = ? AND st.status = 'Active'
        JOIN subjects s ON s.id = ss.subject_id
        JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = st.class_id
        GROUP BY st.student_id, s.id, s.subject_code";
$optStmt = $conn->prepare($optSql); $optStmt->bind_param('ss', $class_id, $year); $optStmt->execute(); $optRes = $optStmt->get_result();
while ($r = $optRes->fetch_assoc()) { $effectiveTypeByStudent[$r['student_id']][(int)$r['subject_id']] = $r['effective_type']; }
foreach ($effectiveTypeByStudent as $stuId => $typeMap) {
    $optionalCandidates = [];
    foreach ($typeMap as $subId => $etype) {
        if (strcasecmp((string)$etype, 'Optional') === 0) { $code = $subjectCodeById[$subId] ?? ''; if ($code !== '') { $optionalCandidates[$subId] = $code; } }
    }
    if (count($optionalCandidates) === 1) { $optionalCodeByStudent[$stuId] = (string)array_values($optionalCandidates)[0]; }
    elseif (count($optionalCandidates) > 1) { $chosenCode=''; $maxNum=-1; foreach ($optionalCandidates as $code) { $num = is_numeric($code)?(int)$code:-1; if ($num > $maxNum) { $maxNum=$num; $chosenCode=(string)$code; } } if ($chosenCode!==''){ $optionalCodeByStudent[$stuId]=$chosenCode; } }
}
if (!empty($optionalIdByStudent)) {
    foreach ($optionalIdByStudent as $stuId => $optSubId) { if (!empty($subjectCodeById[$optSubId])) { $optionalCodeByStudent[$stuId] = (string)$subjectCodeById[$optSubId]; } }
}

// Utilities
function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn; $stmt = $conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id = ? AND subject_id = ? AND exam_id = ?");
    $stmt->bind_param("sss", $student_id, $subject_id, $exam_id); $stmt->execute(); $marks = 0; $stmt->bind_result($marks);
    if ($stmt->fetch()) { $val = (float)$marks; $stmt->close(); return $val; } $stmt->close(); return 0;
}
function isFail($marks, $pass_mark) { return $marks < $pass_mark; }
function subjectGPA($marks, $full_marks) {
    $percentage = ($full_marks > 0) ? ($marks / $full_marks) * 100 : 0;
    if ($percentage >= 80) return 5.00; elseif ($percentage >= 70) return 4.00; elseif ($percentage >= 60) return 3.50; elseif ($percentage >= 50) return 3.00; elseif ($percentage >= 40) return 2.00; elseif ($percentage >= 33) return 1.00; else return 0.00;
}
function gradeLetter($gpa) {
    if ($gpa >= 5.00) return 'A+';
    if ($gpa >= 4.00) return 'A';
    if ($gpa >= 3.50) return 'A-';
    if ($gpa >= 3.00) return 'B';
    if ($gpa >= 2.00) return 'C';
    if ($gpa >= 1.00) return 'D';
    return 'F';
}
// Display helper: shorten some long subject names
function shortSubjectName($name) {
    $n = trim((string)$name);
    $lower = strtolower($n);
    // Exact matches first (case-insensitive via lowered)
    if ($lower === 'information and communication technology') return 'ICT';
    if ($lower === 'history of bangladesh and world civilization') return 'History';
    // Fallback contains-based heuristics
    if (strpos($lower, 'information') !== false && strpos($lower, 'communication') !== false && strpos($lower, 'technology') !== false) return 'ICT';
    if (strpos($lower, 'history of bangladesh') !== false && strpos($lower, 'world civilization') !== false) return 'History';
    return $n;
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

// Sections map (optional display)
$section_result = mysqli_query($conn, "SELECT id, section_name FROM sections"); $sections = [];
if ($section_result) { while ($row = mysqli_fetch_assoc($section_result)) { $sections[$row['id']] = $row['section_name']; } }

// Fetch students
$query = "SELECT * FROM students WHERE class_id = $class_id AND year = $year AND status = 'Active'";
if (!empty($search_roll)) { $query .= " AND roll_no = '".$conn->real_escape_string($search_roll)."'"; }
$query .= " ORDER BY roll_no";
$students_q = mysqli_query($conn, $query);
if (!$students_q) { echo "<div class='alert alert-danger container mt-4'>Error fetching students: " . mysqli_error($conn) . "</div>"; exit; }
$students = [];
while ($stu = mysqli_fetch_assoc($students_q)) { $students[] = $stu; }

// Pre-compute highest obtained marks per subject (including merged subjects)
$highestByCode = [];
foreach ($students as $stu) {
    $sid = $stu['student_id'];
    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null; if (!$subject_code) continue;
        if (!empty($sub['is_merged'])) {
            $group = $sub['subject_name'];
            $codes = $merged_subjects[$group] ?? [];
            $hasAll = true; foreach ($codes as $codeReq) { if (empty($assignedCodesByStudent[$sid][(string)$codeReq])) { $hasAll=false; break; } }
            if (!$hasAll) continue;
            $c = $merged_marks[$sid][$group]['creative'] ?? 0;
            $o = $merged_marks[$sid][$group]['objective'] ?? 0;
            $p = $merged_marks[$sid][$group]['practical'] ?? 0;
            $total = $c + $o + $p;
            $key = $group;
        } else {
            $sidSub = (int)($sub['subject_id'] ?? 0);
            if (empty($assignedByStudent[$sid][$sidSub])) continue;
            $c = !empty($sub['has_creative']) ? getMarks($sid, $sidSub, $exam_id, 'creative') : 0;
            $o = !empty($sub['has_objective']) ? getMarks($sid, $sidSub, $exam_id, 'objective') : 0;
            $p = !empty($sub['has_practical']) ? getMarks($sid, $sidSub, $exam_id, 'practical') : 0;
            $total = $c + $o + $p;
            $key = (string)$subject_code;
        }
        if (!isset($highestByCode[$key]) || $total > $highestByCode[$key]) { $highestByCode[$key] = $total; }
    }
}

// Build marksheet data per student
$all_students = [];
foreach ($students as $stu) {
    $student_id = $stu['student_id'];
    $total_marks = 0; $fail_count = 0;
    $compulsory_gpa_total = 0; $compulsory_gpa_subjects = 0; $optional_gpas = [];
    $student_subjects = [];

    $__si = 0;
    foreach ($display_subjects as $sub) {
        $subject_code = $sub['subject_code'] ?? null; if (!$subject_code) { $__si++; continue; }
        $c = $o = $p = 0; $sub_total = 0; $gpa = 0; $is_fail = false; $is_compulsory = true; $skipSubject = false;

        if (!empty($sub['is_merged'])) {
            $group = $sub['subject_name'];
            $codes = $merged_subjects[$group] ?? [];
            $hasAll = true; foreach ($codes as $codeReq) { if (empty($assignedCodesByStudent[$student_id][(string)$codeReq])) { $hasAll=false; break; } }
            if (!$hasAll) { $skipSubject = true; }
            else { $c = $merged_marks[$student_id][$group]['creative'] ?? 0; $o = $merged_marks[$student_id][$group]['objective'] ?? 0; $p = $merged_marks[$student_id][$group]['practical'] ?? 0; }
        } else {
            $sidSub = (int)($sub['subject_id'] ?? 0);
            if (empty($assignedByStudent[$student_id][$sidSub])) { $skipSubject = true; }
            else {
                $c = $sub['has_creative'] ? getMarks($student_id, $sidSub, $exam_id, 'creative') : 0;
                $o = $sub['has_objective'] ? getMarks($student_id, $sidSub, $exam_id, 'objective') : 0;
                $p = $sub['has_practical'] ? getMarks($student_id, $sidSub, $exam_id, 'practical') : 0;
            }
            // Determine student's optional by id/code
            if (!empty($optionalIdByStudent[$student_id]) && $sidSub === (int)$optionalIdByStudent[$student_id]) { $is_compulsory = false; }
            elseif (!empty($optionalCodeByStudent[$student_id]) && (string)$subject_code === (string)$optionalCodeByStudent[$student_id]) { $is_compulsory = false; }
        }
        if ($skipSubject) { $__si++; continue; }

        $sub_total = $c + $o + $p;
        // pass_type resolved
        $pass_type_raw = isset($sub['pass_type']) ? trim((string)$sub['pass_type']) : '';
        $pass_type_sub = isset($sub['subject_pass_type']) ? trim((string)$sub['subject_pass_type']) : '';
        $pass_type = $pass_type_raw !== '' ? $pass_type_raw : ($pass_type_sub !== '' ? $pass_type_sub : 'total');
        $pass_type = strtolower($pass_type);
        $pm = []; $mk = [];
        if (!empty($sub['has_creative'])) { $pm['creative'] = (int)($sub['creative_pass'] ?? 0); $mk['creative'] = $c; }
        if (!empty($sub['has_objective'])) { $pm['objective'] = (int)($sub['objective_pass'] ?? 0); $mk['objective'] = $o; }
        if (!empty($sub['has_practical'])) { $pm['practical'] = (int)($sub['practical_pass'] ?? 0); $mk['practical'] = $p; }
        $isPass = getPassStatus($class_id, $mk, $pm, $pass_type);

        // Exclude Bangla I/II and English I/II from fail count, GPA aggregation, and grand total
        $is_excluded_paper = in_array((string)$subject_code, $excluded_subject_codes, true);
        if (!$is_excluded_paper) {
            if (!$isPass) { if ($is_compulsory) { $fail_count++; } $is_fail = true; }
        } else {
            // Don't count paper-level fail; merged subject will carry pass/fail
            $is_fail = false;
        }

        // Compute GPA only for non-excluded subjects; excluded papers show '-' and don't affect aggregates
        $gpa = $is_fail ? 0.00 : subjectGPA($sub_total, $sub['max_marks']);
        if (!$is_excluded_paper) {
            if ($is_compulsory) { $compulsory_gpa_total += $gpa; $compulsory_gpa_subjects++; } else { $optional_gpas[] = $gpa; }
            $total_marks += $sub_total;
        } else {
            $gpa = 0.00; // force display '-'
        }

        // Highest and grade letter
        $highest_key = !empty($sub['is_merged']) ? ($sub['subject_name'] ?? $subject_code) : $subject_code;
        $highest_in_class = isset($highestByCode[$highest_key]) ? (int)$highestByCode[$highest_key] : 0;
        $grade_letter = $is_excluded_paper ? '-' : gradeLetter($gpa);

        $student_subjects[] = [
            'name' => shortSubjectName($sub['subject_name'] ?? $subject_code),
            'code' => $subject_code,
            'has_creative' => !empty($sub['has_creative']),
            'has_objective' => !empty($sub['has_objective']),
            'has_practical' => !empty($sub['has_practical']),
            'c_marks' => $c, 'o_marks' => $o, 'p_marks' => $p,
            'c_pass' => (int)($sub['creative_pass'] ?? 0),
            'o_pass' => (int)($sub['objective_pass'] ?? 0),
            'p_pass' => (int)($sub['practical_pass'] ?? 0),
            'total' => $sub_total,
            'gpa' => $gpa,
            'is_fail' => $is_fail,
            'is_merged' => !empty($sub['is_merged']),
            'pass_type' => $pass_type,
            'highest' => $highest_in_class,
            'grade' => $grade_letter
        ];
        $__si++;
    }

    // Optional bonus
    $optional_bonus = 0.00; if (!empty($optional_gpas)) { $max_optional = max($optional_gpas); if ($max_optional > 2.00) { $optional_bonus = $max_optional - 2.00; } }
    $final_gpa = 0.00; $divisor = ($subjects_without_fourth > 0) ? min($subjects_without_fourth, $compulsory_gpa_subjects) : $compulsory_gpa_subjects;
    if ($divisor > 0) { $final_gpa = ($compulsory_gpa_total + $optional_bonus) / $divisor; }
    if ($fail_count > 0) $final_gpa = 0.00;
    $final_gpa = number_format(min($final_gpa, 5.00), 2);

    $optional_code_val = $optionalCodeByStudent[$student_id] ?? '';
    $optional_name_val = ($optional_code_val !== '' && isset($allSubjectNameByCode[$optional_code_val])) ? $allSubjectNameByCode[$optional_code_val] : '';

    $all_students[] = [
        'id' => $student_id,
        'name' => $stu['student_name'] ?? '',
        'roll' => $stu['roll_no'] ?? '',
        'section_id' => $stu['section_id'] ?? null,
        'section_name' => $sections[$stu['section_id']] ?? 'N/A',
        'student_group' => $stu['student_group'] ?? '',
        'optional_code' => $optional_code_val,
        'optional_name' => $optional_name_val,
        'subjects' => $student_subjects,
        'total_marks' => $total_marks,
        'gpa' => $final_gpa,
        'status' => ($fail_count == 0) ? 'Passed' : 'Failed',
        'fail_count' => $fail_count
    ];
}

// Merit by fail then total then roll
$ranked = $all_students;
usort($ranked, function($a,$b){ return ($a['fail_count']<=>$b['fail_count']) ?: ($b['total_marks']<=>$a['total_marks']) ?: ((int)$a['roll']<=> (int)$b['roll']); });
$merit_map = []; foreach ($ranked as $i=>$s) { $merit_map[$s['id']] = $i+1; }
foreach ($all_students as &$st) { $st['merit'] = $merit_map[$st['id']] ?? ''; } unset($st);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>মার্কশিট (আপডেটেড) - <?= htmlspecialchars($institute_name) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.3.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bangla&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        html, body { font-family: 'Hind Siliguri', 'Noto Sans Bangla', Arial, sans-serif; }
        @media print {
            html, body, table, th, td, thead, tbody {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            @page { size: A4; margin: 10mm; }
            html, body { margin: 0 !important; padding: 0 !important; }
            .no-print { display: none !important; }
            /* Ensure each marksheet prints on its own page */
            .marksheet { page-break-after: always; break-after: page; break-inside: avoid; }
            .marksheet:last-child { page-break-after: auto; }
            /* When printing a single page, hide others */
            body.printing-one .marksheet { page-break-after: auto; break-after: auto; }
            body.printing-one .marksheet:not(.print-only) { display: none !important; }
            body.printing-one .marksheet.print-only { display: block !important; }
            /* Full-width printable area adjustments to avoid right-side clipping */
            .container { width: auto !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
            .marksheet { width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 10mm 10mm 16mm 10mm !important; box-sizing: border-box; }
        }
        .marksheet { position: relative; width: 210mm; min-height: 297mm; margin: 10px auto; padding: 12mm 12mm 18mm 12mm; background: #fff; border: 1px solid #3498db; border-radius: 8px; box-sizing: border-box; }
        .header-brand { display: flex; align-items: center; justify-content: center; gap: 12px; margin-bottom: 8px; }
        .header-brand img.logo { width: 56px; height: 56px; object-fit: contain; }
        .header-sep { border-top: 2px solid #3498db; margin: 8px 0 12px; }
        .info { background: #e3f2fd; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
        .subject-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .subject-table th { background: #3498db; color: #fff; text-align: center; padding: 6px; }
        .subject-table td { border: 1px solid #dee2e6; padding: 6px; text-align: center; }
        .fail-mark { color: #d10000; font-weight: 700; }
        .status-passed { background: #d5f5e3; color:#27ae60; padding:6px; border-radius: 4px; }
        .status-failed { background: #fadbd8; color:#c0392b; padding:6px; border-radius: 4px; }
    .text-fail { color:#c0392b !important; font-weight:700; }
        .btn-print-floating { position: fixed; top: 12px; right: 16px; z-index: 9999; box-shadow: 0 2px 6px rgba(0,0,0,.2); }
        .btn-print-single { position: absolute; top: 8px; right: 8px; }
        /* Header and merit styles to match legacy marksheet */
    .institute-header { margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #3498db; }
        .institute-header h4 { color: #2c3e50; font-weight: 700; margin: 0 0 4px; }
        .institute-header p { color: #7f8c8d; margin: 0 0 4px; }
    .institute-header img { max-height: 110px; width: auto; object-fit: contain; }
    .institute-header .header-grid { display: grid; grid-template-columns: 120px 1fr 120px; align-items: center; column-gap: 15px; }
    .institute-header .brand-center { text-align: center; }
        .merit-badge { 
            background: linear-gradient(135deg, #f39c12, #e67e22);
            color: #fff; 
            padding: 6px 14px; 
            border-radius: 999px; 
            font-size: 18px; 
            font-weight: 800; 
            display: inline-flex; 
            align-items: center; 
            gap: 10px; 
            border: 2px solid #d35400; 
            box-shadow: 0 2px 6px rgba(0,0,0,.2);
        }
        .merit-badge .rank-circle {
            width: 40px; height: 40px; 
            background: #fff; 
            color: #e67e22; 
            border-radius: 50%; 
            display: inline-flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 18px; 
            font-weight: 900;
            line-height: 1;
        }
        .merit-badge .label { font-size: 16px; font-weight: 800; }
        .footer-note { text-align: center; margin-top: 15px; padding-top: 8px; border-top: 1px dashed #95a5a6; color: #7f8c8d; font-size: 11px; }
    .branding-strip { position: absolute; left: 0; right: 0; bottom: 0; background: #3498db; color: #fff; text-align: center; padding: 6px 8px; font-weight: 700; border-top: 1px solid #2874b0; }
    .summary-card {
                    padding: 5px;
                    margin-bottom: 5px;
                }
                .gpa-display {
                    font-size: 16px;
                }
                .status-display {
                    font-size: 14px;
                    padding: 5px;
                    border-radius: 4px;
                    text-align: center;
                }
                .status-display {
            font-size: 18px;
            font-weight: bold;
            text-align: center;
            padding: 6px;
            border-radius: 5px;
        }
        .status-passed {
            background-color: #d5f5e3;
            color: #27ae60;
        }
        .status-failed {
            background-color: #fadbd8;
            color: #c0392b;
        }
    </style>
</head>
<body>
<div class="container my-4">
    <div class="no-print mb-3 text-center">
        <h4><?= htmlspecialchars($institute_name) ?> - Marksheet</h4>
        <div class="mb-2"><?= htmlspecialchars($exam) ?> - <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($year) ?>)</div>
        <form class="row gy-2 gx-2 justify-content-center" method="get">
            <input type="hidden" name="exam_id" value="<?= htmlspecialchars($exam_id) ?>">
            <input type="hidden" name="class_id" value="<?= htmlspecialchars($class_id) ?>">
            <input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
            <div class="col-auto"><input class="form-control" type="text" name="search_roll" placeholder="রোল নম্বর" value="<?= htmlspecialchars($search_roll) ?>"></div>
            <div class="col-auto"><button class="btn btn-primary" type="submit"><i class="bi bi-search"></i> অনুসন্ধান</button></div>
            <div class="col-12 small text-muted">রোল খালি রাখলে সকল শিক্ষার্থীর মার্কশিট দেখাবে</div>
        </form>
    <button id="btnPrintAll" class="btn btn-primary btn-print-floating"><i class="bi bi-printer"></i> সবগুলো প্রিন্ট</button>
    </div>

    <?php if (empty($all_students) && !empty($search_roll)): ?>
        <div class="alert alert-danger">এই রোল নম্বরে কোনো শিক্ষার্থী পাওয়া যায়নি: <?= htmlspecialchars($search_roll) ?></div>
    <?php endif; ?>

    <?php foreach ($all_students as $idx => $student): ?>
        <div class="marksheet">
            <div class="institute-header">
                <div class="header-grid">
                    <div class="brand-left">
                        <?php if(file_exists($institute_logo)): ?>
                            <img src="<?= htmlspecialchars($institute_logo) ?>" alt="Logo" />
                        <?php endif; ?>
                    </div>
                    <div class="brand-center">
                        <h4><?= htmlspecialchars($institute_name) ?></h4>
                        <p><?= htmlspecialchars($institute_address) ?></p>
                        <h4><?= htmlspecialchars($exam) ?></h4>
                    </div>
                    <div class="brand-right"><!-- spacer to keep center truly centered --></div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="merit-badge">
                    <span class="rank-circle"><?= htmlspecialchars($student['merit']) ?></span>
                    <span class="label"><i class="bi bi-trophy-fill"></i> মেরিট পজিশন</span>
                </div>
                <div class="text-end no-print">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="printThisMarksheet(this)">
                        <i class="bi bi-printer"></i> প্রিন্ট
                    </button>
                </div>
            </div>

            <div class="info">
                <table style="width: 100%;">
                    <tr>
                        <td colspan="2" style="text-align: left;">শিক্ষার্থীর নাম: <strong><?= $student['name'] ?></strong></td>
                        <td style="text-align: left;">শিক্ষার্থীর আইডি: <strong><?= $student['id'] ?></strong></td>
                    </tr>
                    <tr>
                        <td style="text-align: left;">শ্রেণি: <strong><?= $class ?></strong></td>
                        <td style="text-align: left;">শাখা: <strong><?= $student['section_name'] ?? 'N/A' ?></strong></td>
                        <td style="text-align: left;">রোল নং: <strong><?= $student['roll'] ?></strong></td>
                    </tr>
                    <tr>
                        <td style="text-align: left;">গ্রুপ: <strong><?= $student['student_group'] ?: 'N/A' ?></strong></td>
                        <td></td>
                        <td style="text-align: left;">ঐচ্ছিক বিষয়: <strong><?= !empty($student['optional_name']) ? htmlspecialchars($student['optional_name']) : 'N/A' ?></strong></td>
                    </tr>
                </table>
            </div>

            <table class="subject-table">
                <thead>
                    <tr>
                        <th>বিষয়</th>
                        <th>সর্বোাচ্চ প্রাপ্ত নম্বর</th>
                        <th>সৃজনশীল</th>
                        <th>বহুনির্বাচনী</th>
                        <th>ব্যবহারিক</th>
                        <th>মোট প্রাপ্ত নম্বর</th>
                        <th>গ্রেড পয়েন্ট</th>
                        <th>গ্রেড লেটার</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student['subjects'] as $sub): ?>
                        <tr>
                            <td class="text-start">
                                <?= htmlspecialchars(shortSubjectName($sub['name'])) ?>
                                <?php if ($sub['is_merged']): ?> <small class="text-muted">  (মার্জড)</small><?php endif; ?>
                            </td>
                            <td><strong><?= isset($sub['highest']) ? htmlspecialchars($sub['highest']) : '-' ?></strong></td>
                            <td>
                                <?php if ($sub['has_creative']): ?>
                                    <?= isFail($sub['c_marks'], $sub['c_pass']) && $sub['pass_type']==='individual' ? '<span class="fail-mark">'.htmlspecialchars($sub['c_marks']).'</span>' : htmlspecialchars($sub['c_marks']) ?>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['has_objective']): ?>
                                    <?= isFail($sub['o_marks'], $sub['o_pass']) && $sub['pass_type']==='individual' ? '<span class="fail-mark">'.htmlspecialchars($sub['o_marks']).'</span>' : htmlspecialchars($sub['o_marks']) ?>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['has_practical']): ?>
                                    <?= isFail($sub['p_marks'], $sub['p_pass']) && $sub['pass_type']==='individual' ? '<span class="fail-mark">'.htmlspecialchars($sub['p_marks']).'</span>' : htmlspecialchars($sub['p_marks']) ?>
                                <?php else: ?>-
                                <?php endif; ?>
                            </td>
                            <td><strong><?= htmlspecialchars($sub['total']) ?></strong></td>
                            <?php
                                $isExcluded = in_array((string)($sub['code'] ?? ''), $excluded_subject_codes, true);
                                $isFailSub = !empty($sub['is_fail']);
                                $gradeCls = ($isFailSub && !$isExcluded) ? 'text-fail' : '';
                            ?>
                            <td class="<?= $gradeCls ?>"><strong><?= !$isExcluded ? number_format((float)$sub['gpa'], 2) : '-' ?></strong></td>
                            <td class="<?= $gradeCls ?>"><strong><?= !$isExcluded ? htmlspecialchars($sub['grade']) : '-' ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="summary-card">
                <div class="row">
                    <div class="text-center">
                <table style="border: none; width: 100%;">
                    <tr>
                        <td class="mb-2"><strong>মোট প্রাপ্ত নম্বর:</strong></td>
                        <td class="mb-2"><strong>জিপিএ:</strong></td>
                        <td class="mb-2"><strong>লেটার গ্রেড:</strong></td>
                        <td class="mb-2"><strong>ফেল সাবজেক্ট:</strong></td>
                    </tr>
                    <tr>
                        <td class="fs-4"><strong><?= $student['total_marks'] ?></strong></td>
                        <td class="fs-4 <?= $student['status'] === 'Failed' ? 'text-fail' : '' ?>"><strong><?= $student['gpa'] ?></strong></td>
                        <td class="fs-4 <?= $student['status'] === 'Failed' ? 'text-fail' : '' ?>"><strong><?= ($student['gpa'] >= 5.00) ? 'A+' : (($student['gpa'] >= 4.00) ? 'A' : (($student['gpa'] >= 3.50) ? 'A-' : (($student['gpa'] >= 3.00) ? 'B' : (($student['gpa'] >= 2.00) ? 'C' : (($student['gpa'] >= 1.00) ? 'D' : 'F'))))) ?></strong></td>
                        <td class="fs-4 <?= $student['status'] === 'Failed' ? 'text-fail' : '' ?>"><strong><?= $student['fail_count'] > 0 ? $student['fail_count'] : '0' ?></strong></td>
                    </tr>
                    <tr>
                        
                        
                    </tr>
                </table>
                    </div>
    
                </div>
                
                <div class="mt-3">
                    <div class="status-display <?= $student['status'] === 'Passed' ? 'status-passed' : 'status-failed' ?>">
                        <?= $student['status'] === 'Passed' ? '<i class="bi bi-check-circle"></i> অভিনন্দন! তোমার পরিশ্রমের ফল এসেছে সাফল্যের রূপে।' : '<i class="bi bi-x-circle"></i> দুঃখীত! তুমি ফেল করেছো। ভুল থেকে শেখো, ভবিষ্যৎ তোমার হাতে' ?>
                    </div>
                </div>
            </div>

            <div class="footer-note">
                <table style="width: 100%;">
                    <tr>
                        <td style="text-align: left;"> প্রিন্টের তারিখ: <?= date('d/m/Y') ?> </td>
                        <td style="text-align: right;"></td>
                        <td style="text-align: center;">
                            <div><img src="../assets/hm_sign.jpg" alt="HM Signature" style="vertical-align: middle;"></div>
                            <div> প্রধান শিক্ষকের স্বাক্ষর </div>
                        </td>
                    </tr>
                    
                </table>
            </div>
            <div class="branding-strip">অটোমেশন ও ডিজাইন: বাতিঘর কম্পিউটার’স</div>
        </div>
    <?php endforeach; ?>
</div>
<script>
    document.getElementById('btnPrintAll')?.addEventListener('click', function(){ window.print(); });
    function printThisMarksheet(btn){
        try{
            const card = btn.closest('.marksheet');
            if(!card) return;
            card.classList.add('print-only');
            document.body.classList.add('printing-one');
            const cleanup = () => {
                card.classList.remove('print-only');
                document.body.classList.remove('printing-one');
                window.removeEventListener('afterprint', cleanup);
            };
            window.addEventListener('afterprint', cleanup);
            window.print();
            // Fallback cleanup in case afterprint doesn't fire
            setTimeout(cleanup, 2000);
        }catch(e){ console.error(e); }
    }
</script>
</body>
</html>
