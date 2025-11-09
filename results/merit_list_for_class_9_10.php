<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// প্রতিষ্ঠানের তথ্য
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";

// পরীক্ষার নাম ও সেটিংস
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='".$conn->real_escape_string($exam_id)."'"));
$exam = $examRow['exam_name'] ?? '';
$subjects_without_fourth = isset($examRow['total_subjects_without_fourth']) ? (int)$examRow['total_subjects_without_fourth'] : 0;
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='".$conn->real_escape_string($class_id)."'"))['class_name'] ?? '';

// === Custom Subject Merging ===
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

$merged_marks = [];
$individual_subjects = [];
$excluded_subject_codes = ['101', '102', '107', '108'];

// Load individual subject marks for merged pairs
foreach ($merged_subjects as $group_name => $sub_codes) {
    $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));
    $sql = "SELECT m.student_id, m.subject_id, s.subject_code,
                   m.creative_marks, m.objective_marks, m.practical_marks
            FROM marks m
            JOIN subjects s ON m.subject_id = s.id
            JOIN students stu ON m.student_id = stu.student_id
            WHERE m.exam_id = ? AND stu.class_id = ? AND stu.year = ?
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
        $individual_subjects[$sid][$sub_code]['creative'] += (float)$row['creative_marks'];
        $individual_subjects[$sid][$sub_code]['objective'] += (float)$row['objective_marks'];
        $individual_subjects[$sid][$sub_code]['practical'] += (float)$row['practical_marks'];

        if (!isset($merged_marks[$sid][$group_name])) {
            $merged_marks[$sid][$group_name] = ['creative' => 0, 'objective' => 0, 'practical' => 0];
        }
        $merged_marks[$sid][$group_name]['creative'] += (float)$row['creative_marks'];
        $merged_marks[$sid][$group_name]['objective'] += (float)$row['objective_marks'];
        $merged_marks[$sid][$group_name]['practical'] += (float)$row['practical_marks'];
    }
}

// Load exam subjects for this class (adapt to optional subjects.pass_type column)
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
while ($row = mysqli_fetch_assoc($subjects_q)) { $subjects[] = $row; }

// Deduplicate same subject; prefer Compulsory
$dedup = [];
foreach ($subjects as $s) {
    $sid = $s['subject_id'];
    if (!isset($dedup[$sid])) { $dedup[$sid] = $s; }
    else {
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
$insert_after_codes = ['Bangla' => '102', 'English' => '108'];

foreach ($subjects as $sub) {
    $code = $sub['subject_code'];
    $display_subjects[] = array_merge($sub, ['is_merged' => false, 'type' => ($sub['subject_type'] ?? 'Compulsory')]);
    foreach ($merged_subjects as $group_name => $sub_codes) {
        if (in_array($code, $sub_codes) && $code === $insert_after_codes[$group_name] && !in_array($group_name, $merged_added)) {
            if (!isset($merged_pass_marks[$group_name])) {
                $creative_pass = 0; $objective_pass = 0; $practical_pass = 0; $max_marks = 0; $type = 'Compulsory';
                foreach ($subjects as $s) {
                    if (in_array($s['subject_code'], $merged_subjects[$group_name])) {
                        $creative_pass += (int)($s['creative_pass'] ?? 0);
                        $objective_pass += (int)($s['objective_pass'] ?? 0);
                        $practical_pass += (int)($s['practical_pass'] ?? 0);
                        $max_marks += (int)($s['max_marks'] ?? 0);
                        if (isset($s['subject_type'])) $type = $s['subject_type'];
                    }
                }
                $merged_pass_marks[$group_name] = [
                    'creative_pass' => $creative_pass,
                    'objective_pass' => $objective_pass,
                    'practical_pass' => $practical_pass,
                    'max_marks' => $max_marks,
                    'type' => $type
                ];
            }
            $display_subjects[] = [
                'subject_name' => $group_name,
                'subject_code' => $group_name,
                'has_creative' => 1,
                'has_objective' => 1,
                'has_practical' => 0,
                'creative_pass' => $merged_pass_marks[$group_name]['creative_pass'],
                'objective_pass' => $merged_pass_marks[$group_name]['objective_pass'],
                'practical_pass' => $merged_pass_marks[$group_name]['practical_pass'],
                'max_marks' => $merged_pass_marks[$group_name]['max_marks'],
                'type' => $merged_pass_marks[$group_name]['type'],
                'is_merged' => true
            ];
            $merged_added[] = $group_name;
        }
    }
}

// Order: compulsory first, optional later
$compulsory_list = [];$optional_list = [];
foreach ($display_subjects as $ds) { $t = $ds['type'] ?? 'Compulsory'; if ($t === 'Optional') $optional_list[] = $ds; else $compulsory_list[] = $ds; }
$display_subjects = array_merge($compulsory_list, $optional_list);

// Preload assigned subjects (ids and codes)
$assignedByStudent = [];$assignedCodesByStudent = [];$subjectCodeById = [];
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

// Optional selection detection
$optionalIdByStudent = [];
$colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if ($colCheck && mysqli_num_rows($colCheck) > 0) {
    $optIdRes = mysqli_query($conn, "SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND optional_subject_id IS NOT NULL");
    if ($optIdRes) { while ($row = mysqli_fetch_assoc($optIdRes)) { $optionalIdByStudent[$row['student_id']] = (int)$row['optional_subject_id']; } }
}
$effectiveTypeByStudent = [];$optionalCodeByStudent = [];
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
        JOIN students st ON st.student_id = ss.student_id AND st.class_id = ? AND st.year = ?
        JOIN subjects s ON s.id = ss.subject_id
        JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = st.class_id
        GROUP BY st.student_id, s.id, s.subject_code";
$optStmt = $conn->prepare($optSql);
$optStmt->bind_param('ss', $class_id, $year);
$optStmt->execute();
$optRes = $optStmt->get_result();
while ($r = $optRes->fetch_assoc()) {
    $effectiveTypeByStudent[$r['student_id']][(int)$r['subject_id']] = $r['effective_type'];
}
foreach ($effectiveTypeByStudent as $stuId => $typeMap) {
    $optionalCandidates = [];
    foreach ($typeMap as $subId => $etype) {
        if (strcasecmp((string)$etype, 'Optional') === 0) {
            $code = $subjectCodeById[$subId] ?? '';
            if ($code !== '') $optionalCandidates[$subId] = (string)$code;
        }
    }
    if (count($optionalCandidates) === 1) {
        $optionalCodeByStudent[$stuId] = (string)array_values($optionalCandidates)[0];
    } elseif (count($optionalCandidates) > 1) {
        $chosenCode = '';$maxNum = -1;
        foreach ($optionalCandidates as $code) { $num = is_numeric($code) ? (int)$code : -1; if ($num > $maxNum) { $maxNum = $num; $chosenCode = (string)$code; } }
        if ($chosenCode !== '') $optionalCodeByStudent[$stuId] = $chosenCode;
    }
}
if (!empty($optionalIdByStudent)) {
    foreach ($optionalIdByStudent as $stuId => $optSubId) {
        if (!empty($subjectCodeById[$optSubId])) $optionalCodeByStudent[$stuId] = (string)$subjectCodeById[$optSubId];
    }
}

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
function isFail($marks, $pass_mark) { return $marks < $pass_mark || $marks == 0; }
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

?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>Merit List</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
th, td { vertical-align: middle !important; font-size: 13px; }
@media print { .no-print { display:none; } }
</style>
</head>
<body>
<div class="container my-4">
  <div class="text-center mb-3">
    <h5><?= htmlspecialchars($institute_name) ?></h5>
    <p><?= htmlspecialchars($institute_address) ?></p>
    <h4><?= htmlspecialchars($exam) ?> - <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($year) ?>)</h4>
    <button onclick="window.print()" class="btn btn-primary no-print">প্রিন্ট করুন</button>
  </div>
  <table class="table table-bordered table-hover text-center align-middle">
    <thead class="table-dark">
      <tr>
        <th>#</th>
        <th>Student Name</th>
        <th>Roll</th>
        <th>Merit Position</th>
        <th>Total Obtained Marks</th>
        <th>GPA</th>
        <th>Status</th>
        <th>Fail Count</th>
        <th>Failed Subjects</th>
      </tr>
    </thead>
    <tbody>
<?php
$all_students = [];
$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id = ".$conn->real_escape_string($class_id)." AND year = ".$conn->real_escape_string($year)." ORDER BY roll_no");
if (!$students_q) {
    echo "<tr><td colspan='9' class='text-danger'>Error fetching students: ".mysqli_error($conn)."</td></tr>";
} else {
    $excluded_subject_codes_numeric = [101,102,107,108];
    while ($stu = mysqli_fetch_assoc($students_q)) {
        $total_marks = 0; $fail_count = 0; $failed_subject_codes = [];
        $student_optional_id = 0; $student_optional_code = '';
        $sidKey = $stu['student_id'];
        if (isset($optionalIdByStudent[$sidKey])) $student_optional_id = (int)$optionalIdByStudent[$sidKey];
        if (isset($optionalCodeByStudent[$sidKey])) $student_optional_code = (string)$optionalCodeByStudent[$sidKey];

        $compulsory_gpa_total = 0; $compulsory_gpa_subjects = 0; $optional_gpas = [];
        foreach ($display_subjects as $sub) {
            $subject_code = $sub['subject_code'] ?? null; if (!$subject_code) continue;
            $c = $o = $p = 0; $skipSubject = false;
            if (!empty($sub['is_merged'])) {
                $group = $sub['subject_name']; $codes = $merged_subjects[$group] ?? [];
                $hasAll = true; foreach ($codes as $codeReq) { if (empty($assignedCodesByStudent[$sidKey][(string)$codeReq])) { $hasAll = false; break; } }
                if (!$hasAll) { $skipSubject = true; }
                else {
                    $c = $merged_marks[$sidKey][$group]['creative'] ?? 0;
                    $o = $merged_marks[$sidKey][$group]['objective'] ?? 0;
                    $p = $merged_marks[$sidKey][$group]['practical'] ?? 0;
                }
            } else {
                $sidSub = (int)($sub['subject_id'] ?? 0);
                if (empty($assignedByStudent[$sidKey][$sidSub])) { $skipSubject = true; }
                else {
                    $c = $sub['has_creative'] ? getMarks($sidKey, $sidSub, $exam_id, 'creative') : 0;
                    $o = $sub['has_objective'] ? getMarks($sidKey, $sidSub, $exam_id, 'objective') : 0;
                    $p = $sub['has_practical'] ? getMarks($sidKey, $sidSub, $exam_id, 'practical') : 0;
                }
            }
            if ($skipSubject) continue;
            $sub_total = $c + $o + $p;

            // Classification: optional vs compulsory
            $is_compulsory = true;
            if (empty($sub['is_merged'])) {
                $sidSub = (int)($sub['subject_id'] ?? 0);
                if (!empty($student_optional_id) && $sidSub === $student_optional_id) $is_compulsory = false;
                elseif (!empty($student_optional_code) && (string)$subject_code === (string)$student_optional_code) $is_compulsory = false;
            }

            if (!in_array($subject_code, $excluded_subject_codes)) {
                // pass_type: prefer exam_subjects.pass_type, else subjects.pass_type, else 'total'
                $pass_type_raw = isset($sub['pass_type']) ? trim((string)$sub['pass_type']) : '';
                $pass_type_sub = isset($sub['subject_pass_type']) ? trim((string)$sub['subject_pass_type']) : '';
                $pass_type = $pass_type_raw !== '' ? $pass_type_raw : ($pass_type_sub !== '' ? $pass_type_sub : 'total');
                $failedThis = false;
                if ($pass_type === 'total') {
                    $required_total = ($sub['creative_pass'] ?? 0) + ($sub['objective_pass'] ?? 0) + ($sub['practical_pass'] ?? 0);
                    if ($sub_total < $required_total) { if ($is_compulsory) $fail_count++; $failedThis = true; }
                } else {
                    if (($sub['has_creative'] && $c < ($sub['creative_pass'] ?? 0)) ||
                        ($sub['has_objective'] && $o < ($sub['objective_pass'] ?? 0)) ||
                        ($sub['has_practical'] && $p < ($sub['practical_pass'] ?? 0))) {
                        if ($is_compulsory) $fail_count++; $failedThis = true;
                    }
                }
                // Track failed subject codes (only those counted as fail i.e., compulsory)
                if ($failedThis && $is_compulsory) {
                    if (!empty($sub['is_merged'])) {
                        // Push both component codes for merged subjects
                        $codes = $merged_subjects[$sub['subject_name']] ?? [];
                        foreach ($codes as $cc) { $failed_subject_codes[] = (string)$cc; }
                    } else {
                        $failed_subject_codes[] = (string)$subject_code;
                    }
                }

                $gpa = subjectGPA($sub_total, $sub['max_marks']);
                if ($is_compulsory) { $compulsory_gpa_total += $gpa; $compulsory_gpa_subjects++; }
                else { $optional_gpas[] = $gpa; }

                $total_marks += $sub_total;
            }
        }

        $optional_bonus = 0.00;
        if (!empty($optional_gpas)) { $max_optional = max($optional_gpas); if ($max_optional > 2.00) $optional_bonus = $max_optional - 2.00; }
        $final_gpa = 0.00;
        $divisor = ($subjects_without_fourth > 0) ? min($subjects_without_fourth, $compulsory_gpa_subjects) : $compulsory_gpa_subjects;
        if ($divisor > 0) { $final_gpa = ($compulsory_gpa_total + $optional_bonus) / $divisor; }
        if ($fail_count > 0) $final_gpa = 0.00;
        $final_gpa = number_format(min($final_gpa, 5.00), 2);
        $fail_display = $fail_count > 0 ? $fail_count : '';
        $status = ($fail_count == 0) ? '<span class="text-success">Passed</span>' : '<span class="text-danger">Failed</span>';

        $all_students[] = [
            'fail' => $fail_count,
            'total' => $total_marks,
            'roll' => isset($stu['roll_no']) ? (int)$stu['roll_no'] : 0,
            'row' => [
                'name' => $stu['student_name'] ?? '',
                'roll' => $stu['roll_no'] ?? '',
                'total' => $total_marks,
                'gpa' => $final_gpa,
                'status' => $status,
                'fail_count' => $fail_display,
                'failed_codes' => implode(', ', array_unique($failed_subject_codes))
            ]
        ];
    }
}

// Ranking: same as tabulation
$ranked_students = $all_students;
usort($ranked_students, function ($a, $b) {
    return $a['fail'] <=> $b['fail'] ?: $b['total'] <=> $a['total'] ?: $a['roll'] <=> $b['roll'];
});

// Render rows
foreach ($ranked_students as $i => $stu) {
    $serial = $i + 1; $merit_pos = $i + 1; $r = $stu['row'];
    echo '<tr>';
    echo '<td>'.($serial).'</td>';
    echo '<td>'.htmlspecialchars($r['name']).'</td>';
    echo '<td>'.htmlspecialchars($r['roll']).'</td>';
    echo '<td>'.($merit_pos).'</td>';
    echo '<td>'.htmlspecialchars((string)$r['total']).'</td>';
    echo '<td>'.htmlspecialchars((string)$r['gpa']).'</td>';
    echo '<td>'.$r['status'].'</td>';
    echo '<td>'.htmlspecialchars((string)$r['fail_count']).'</td>';
    echo '<td>'.htmlspecialchars($r['failed_codes']).'</td>';
    echo '</tr>';
}
?>
    </tbody>
  </table>
</div>
</body>
</html>
