<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

$student_id = $_GET['student_id'] ?? '';

$student = null;
$subjects = [];
$assigned_subjects = [];

if ($student_id) {
    $stmt = $conn->prepare("SELECT s.*, c.class_name FROM students s JOIN classes c ON s.class_id = c.id WHERE s.student_id = ?");
    $stmt->bind_param("s", $student_id);
    $stmt->execute();
    $student_result = $stmt->get_result();
    if ($student_result->num_rows > 0) {
        $student = $student_result->fetch_assoc();
        $class_id = $student['class_id'];
        $group = $student['student_group'];

$stmt = $conn->prepare("
    SELECT s.*, gm.type, gm.class_id, c.class_name
    FROM subjects s
    JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.group_name = ?
    JOIN classes c ON gm.class_id = c.id
    WHERE gm.class_id = ? AND s.status = 'Active'
    ORDER BY s.subject_code ASC
");
$stmt->bind_param("si", $group, $class_id);


        $stmt->execute();
        $subjects_result = $stmt->get_result();
        while ($row = $subjects_result->fetch_assoc()) {
            // mark source as group-specific
            $row['source'] = 'group';
            $subjects[] = $row;
        }

        // Also include common subjects where group_name = 'NONE' for this class
        $stmt_common = $conn->prepare("
            SELECT s.id, s.subject_name, s.subject_code, gm.type
            FROM subjects s
            JOIN subject_group_map gm ON gm.subject_id = s.id AND UPPER(gm.group_name) = 'NONE'
            JOIN classes c ON gm.class_id = c.id
            WHERE gm.class_id = ? AND s.status = 'Active'
            ORDER BY s.subject_code ASC
        ");
        $stmt_common->bind_param("i", $class_id);
        $stmt_common->execute();
        $common_result = $stmt_common->get_result();

        // Also collect common subjects as separate entries; we'll dedup per (id,type), preferring group-specific over common
        while ($crow = $common_result->fetch_assoc()) {
            $subjects[] = [
                'id' => $crow['id'],
                'subject_name' => $crow['subject_name'],
                'subject_code' => $crow['subject_code'],
                'type' => $crow['type'],
                'source' => 'common',
            ];
        }

        $stmt = $conn->prepare("SELECT subject_id FROM student_subjects WHERE student_id = ?");
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $assigned_result = $stmt->get_result();
        while ($row = $assigned_result->fetch_assoc()) {
            $assigned_subjects[] = $row['subject_id'];
        }

        // Deduplicate subjects by (ID, Type): show both Compulsory and Optional if available, but
        // prefer group-specific mapping over common when both sources exist for the same (id,type).
        // Then sort by subject_code (numeric), and for same code show Compulsory before Optional.
        if (!empty($subjects)) {
            $dedup = [];
            foreach ($subjects as $row) {
                $sid = $row['id'] ?? null;
                $type = $row['type'] ?? '';
                if ($sid === null || $type === '') continue;
                $key = $sid . '|' . strtolower($type);
                if (!isset($dedup[$key])) {
                    $dedup[$key] = $row;
                } else {
                    // prefer group source over common
                    $existingSrc = $dedup[$key]['source'] ?? '';
                    $newSrc = $row['source'] ?? '';
                    if ($existingSrc !== 'group' && $newSrc === 'group') {
                        $dedup[$key] = $row;
                    }
                }
            }
            $subjects = array_values($dedup);
            usort($subjects, function($a, $b){
                $ca = $a['subject_code'] ?? '';
                $cb = $b['subject_code'] ?? '';
                $na = is_numeric($ca) ? (int)$ca : PHP_INT_MAX;
                $nb = is_numeric($cb) ? (int)$cb : PHP_INT_MAX;
                if ($na !== $nb) return $na <=> $nb;
                // same code: show Compulsory before Optional
                $ta = strtolower($a['type'] ?? '');
                $tb = strtolower($b['type'] ?? '');
                if ($ta !== $tb) {
                    if ($ta === 'compulsory') return -1;
                    if ($tb === 'compulsory') return 1;
                }
                return strcmp((string)$ca, (string)$cb);
            });
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'];
    $selected_subjects = $_POST['subjects'] ?? [];
    $selected_types = $_POST['selected_types'] ?? null; // [subject_id => 'Compulsory'|'Optional']

    // Server-side rule: allow only ONE Optional subject overall
    // Fetch student's class and group
    $stuInfo = null;
    $ps = $conn->prepare("SELECT class_id, student_group FROM students WHERE student_id = ? LIMIT 1");
    $ps->bind_param("s", $student_id);
    $ps->execute();
    $resStu = $ps->get_result();
    if ($resStu && $resStu->num_rows > 0) {
        $stuInfo = $resStu->fetch_assoc();
    }

    if ($stuInfo && !empty($selected_subjects)) {
        // If client submitted chosen types, count those directly for single-optional rule
        if (is_array($selected_types)) {
            $optionalCount = 0;
            foreach ($selected_subjects as $sid) {
                $sid = (int)$sid;
                $t = $selected_types[$sid] ?? '';
                if (strcasecmp($t, 'Optional') === 0) $optionalCount++;
            }
            if ($optionalCount > 1) {
                $_SESSION['error'] = 'শুধুমাত্র একটি অপশনাল বিষয় নির্বাচন করতে পারবেন।';
                header("Location: add_subjects.php?student_id=" . urlencode($student_id));
                exit();
            }
        } else {
        $class_id = (int)$stuInfo['class_id'];
        $group = $stuInfo['student_group'];
        // sanitize ids
        $ids = array_values(array_unique(array_map('intval', $selected_subjects)));
        $in = implode(',', array_fill(0, count($ids), '?'));
        // Build dynamic types query with prepared IN
                        $typesSql = "
                                SELECT s.id,
                                             CASE
                                                 WHEN SUM(CASE WHEN gm.group_name = ? THEN 1 ELSE 0 END) > 0
                                                     THEN CASE
                                                                    WHEN SUM(CASE WHEN gm.group_name = ? AND gm.type = 'Optional' THEN 1 ELSE 0 END) > 0
                                                                        THEN 'Optional'
                                                                    ELSE 'Compulsory'
                                                                END
                                                     ELSE CASE
                                                                    WHEN SUM(CASE WHEN UPPER(gm.group_name) = 'NONE' AND gm.type = 'Optional' THEN 1 ELSE 0 END) > 0
                                                                        THEN 'Optional'
                                                                    ELSE 'Compulsory'
                                                                END
                                             END AS effective_type
                                FROM subjects s
                                JOIN subject_group_map gm ON gm.subject_id = s.id
                                WHERE gm.class_id = ?
                                    AND s.status = 'Active'
                                    AND (gm.group_name = ? OR UPPER(gm.group_name) = 'NONE')
                                    AND s.id IN ($in)
                                GROUP BY s.id";
                        // Prepare dynamic statement
                        $stmtTypes = $conn->prepare($typesSql);
                        // Build bind parameters in order: group (s), group (s), class_id (i), group (s), then ids (i...)
                        $types = 'ssis' . str_repeat('i', count($ids));
                        $params = array_merge([$types, $group, $group, $class_id, $group], $ids);
        // Workaround for mysqli bind_param with dynamic params
        $tmp = [];
        foreach ($params as $key => $value) {
            $tmp[$key] = &$params[$key];
        }
        call_user_func_array([$stmtTypes, 'bind_param'], $tmp);
        $stmtTypes->execute();
        $r = $stmtTypes->get_result();
        $optionalCount = 0;
        while ($row = $r->fetch_assoc()) {
            if (($row['effective_type'] ?? '') === 'Optional') $optionalCount++;
        }
        if ($optionalCount > 1) {
            $_SESSION['error'] = 'শুধুমাত্র একটি অপশনাল বিষয় নির্বাচন করতে পারবেন।';
            header("Location: add_subjects.php?student_id=" . urlencode($student_id));
            exit();
        }
        }
    }

    $del = $conn->prepare("DELETE FROM student_subjects WHERE student_id = ?");
    $del->bind_param("s", $student_id);
    $del->execute();

    $stmt = $conn->prepare("INSERT INTO student_subjects (student_id, subject_id) VALUES (?, ?)");
    foreach ($selected_subjects as $sub_id) {
        $stmt->bind_param("si", $student_id, $sub_id);
        $stmt->execute();
    }

    // Persist the student's chosen Optional subject into students.optional_subject_id (if the column exists)
    $chosenOptionalSubjectId = null;
    if (is_array($selected_types)) {
        foreach ($selected_types as $sid => $t) {
            if (strcasecmp((string)$t, 'Optional') === 0) {
                $chosenOptionalSubjectId = (int)$sid;
                break; // only one allowed by validation
            }
        }
    }
    $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
    // Auto-migrate: add column if missing (safe, nullable)
    if ($colCheck && mysqli_num_rows($colCheck) === 0) {
        @mysqli_query($conn, "ALTER TABLE students ADD COLUMN optional_subject_id INT NULL");
        // refresh check
        $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
    }
    if ($colCheck && mysqli_num_rows($colCheck) > 0) {
        if (!empty($chosenOptionalSubjectId)) {
            $upd = $conn->prepare("UPDATE students SET optional_subject_id = ? WHERE student_id = ?");
            $upd->bind_param('is', $chosenOptionalSubjectId, $student_id);
            $upd->execute();
            $upd->close();
        } else {
            // If none selected, clear it
            $upd = $conn->prepare("UPDATE students SET optional_subject_id = NULL WHERE student_id = ?");
            $upd->bind_param('s', $student_id);
            $upd->execute();
            $upd->close();
        }
    }

    // Remember the user's chosen type per subject for this student to reflect correctly on reload
    if (is_array($selected_types)) {
        $_SESSION['selected_types_memory'][$student_id] = $selected_types;
    } else {
        unset($_SESSION['selected_types_memory'][$student_id]);
    }

    header("Location: add_subjects.php?student_id=$student_id&success=1");
    exit();
}
?>

<?php include '../includes/header.php'; ?>
<div class="d-flex"><?php include '../includes/sidebar.php'; ?>
<div class="container mt-4">
    <h4>বিষয় নির্বাচন</h4>

    <form method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" name="student_id" class="form-control" placeholder="Student ID" value="<?= htmlspecialchars($student_id) ?>" required>
            <button type="submit" class="btn btn-primary">Search</button>
        </div>
    </form>

    <?php if ($student): ?>
            <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">বিষয়সমূহ সফলভাবে সংরক্ষিত হয়েছে।</div>
        <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

        <div class="card p-3 mb-4">
            <h5>শিক্ষার্থীর তথ্য</h5>
            <p><strong>নাম:</strong> <?= $student['student_name'] ?></p>
            <p><strong>শ্রেণি:</strong> <?= $student['class_name'] ?></p>
            <p><strong>গ্রুপ:</strong> <?= $student['student_group'] ?></p>
        </div>

    <form method="POST" id="subject-form">
            <input type="hidden" name="student_id" value="<?= $student_id ?>">
            <div class="card p-3">
                <h5>বিষয় তালিকা</h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>ক্রমিক</th>
                            <th>বিষয় কোড</th>
                            <th>বিষয়ের নাম</th>
                            <th>ধরণ</th>
                            <th>নির্বাচন</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $i = 1;
                        // Track pre-checked by subject id to avoid checking both Compulsory and Optional rows for same subject
                        $precheckedById = [];
                        // Load user's last chosen types (from session) to reflect Optional vs Compulsory correctly after save
                        $preferred_types = isset($_SESSION['selected_types_memory'][$student_id]) && is_array($_SESSION['selected_types_memory'][$student_id])
                            ? $_SESSION['selected_types_memory'][$student_id]
                            : [];
                        foreach ($subjects as $sub):
                            $sid = $sub['id'];
                            $isAssigned = in_array($sid, $assigned_subjects);
                            $alreadyChecked = isset($precheckedById[$sid]);
                            $prefType = $preferred_types[$sid] ?? '';
                            $prefType = is_string($prefType) ? strtolower($prefType) : '';
                            $rowType = strtolower($sub['type'] ?? '');
                            // If we have a preferred type for this subject, only check the matching row; else check the first occurrence (sorted: Compulsory first)
                            if ($isAssigned && !$alreadyChecked) {
                                if ($prefType !== '') {
                                    $is_checked = ($rowType === $prefType);
                                } else {
                                    $is_checked = true;
                                }
                            } else {
                                $is_checked = false;
                            }
                            if ($is_checked) { $precheckedById[$sid] = true; }
                            $is_compulsory = ($sub['type'] === 'Compulsory');
                    ?>
                        <tr>
                            <td><?= $i++ ?></td>
                            <td><?= htmlspecialchars($sub['subject_code']) ?></td>
                            <td><?= htmlspecialchars($sub['subject_name']) ?></td>
                            <td><?= $sub['type'] ?></td>
                            <td>
                    <input class="form-check-input subject-checkbox"
                                       type="checkbox"
                                       name="subjects[]"
                                       value="<?= $sub['id'] ?>"
                                       id="subject_<?= $sub['id'] ?>"
                                       data-subject-code="<?= $sub['subject_code'] ?>"
                                       data-type="<?= $sub['type'] ?>"
                                       <?= $is_checked ? 'checked' : '' ?>>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="submit" class="btn btn-success mt-3">সংরক্ষণ করুন</button>
            </div>
        </form>
    <?php elseif ($student_id): ?>
        <div class="alert alert-danger">কোনো শিক্ষার্থী খুঁজে পাওয়া যায়নি!</div>
    <?php endif; ?>
</div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const checkboxes = document.querySelectorAll(".subject-checkbox");
    const form = document.getElementById('subject-form');

    function enforceSingleOptional() {
        const optionals = Array.from(document.querySelectorAll('.subject-checkbox[data-type="Optional"]:checked'));
        if (optionals.length > 1) {
            // Keep the first; uncheck others
            optionals.slice(1).forEach(cb => { cb.checked = false; });
        }
    }

    function uncheckSameCode(activeCb) {
        const code = activeCb.dataset.subjectCode;
        checkboxes.forEach(other => {
            if (other !== activeCb && other.dataset.subjectCode === code) {
                other.checked = false;
            }
        });
    }

    function uncheckOtherOptionals(activeCb) {
        if (activeCb.dataset.type === 'Optional') {
            checkboxes.forEach(other => {
                if (other !== activeCb && other.dataset.type === 'Optional') {
                    other.checked = false;
                }
            });
        }
    }

    checkboxes.forEach(cb => {
        cb.addEventListener("change", function () {
            if (cb.checked) {
                uncheckSameCode(cb);
                uncheckOtherOptionals(cb);
            }
            // Always ensure only one optional remains selected
            enforceSingleOptional();
        });
    });

    // Normalize initial state just in case
    enforceSingleOptional();

    // On submit, attach hidden inputs to send the chosen type per selected subject
    if (form) {
        form.addEventListener('submit', function () {
            // remove any previously added hidden inputs
            const olds = form.querySelectorAll('input[name^="selected_types["]');
            olds.forEach(el => el.remove());
            // add type for each checked checkbox
            document.querySelectorAll('.subject-checkbox:checked').forEach(cb => {
                const sid = cb.value;
                const t = cb.dataset.type || '';
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = `selected_types[${sid}]`;
                hidden.value = t;
                form.appendChild(hidden);
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>
