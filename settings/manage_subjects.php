<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}
include '../config/db.php';

// Detect whether subjects.pass_type column exists
$hasPassType = false;
if ($chk = $conn->query("SHOW COLUMNS FROM subjects LIKE 'pass_type'")) {
    $hasPassType = ($chk->num_rows > 0);
}

// If missing, add the column so Pass Type can be saved and displayed
if (!$hasPassType) {
    try {
        $conn->query("ALTER TABLE subjects ADD COLUMN pass_type ENUM('total','individual') NOT NULL DEFAULT 'total' AFTER has_practical");
        // Re-check after attempting migration
        if ($chk2 = $conn->query("SHOW COLUMNS FROM subjects LIKE 'pass_type'")) {
            $hasPassType = ($chk2->num_rows > 0);
        }
    } catch (Throwable $e) {
        // Ignore migration failure; UI will fallback to default display
    }
}

// Add or Update Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject_name = $_POST['subject_name'];
    $subject_code = $_POST['subject_code'];
    $default_marks = $_POST['default_marks'];
    $has_creative = $_POST['has_creative'];
    $has_objective = $_POST['has_objective'];
    $has_practical = $_POST['has_practical'];
    $pass_type = $_POST['pass_type'] ?? 'total';
    $status = $_POST['status'];

    if ($_POST['action'] === 'add') {
        if ($hasPassType) {
            $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, default_marks, has_creative, has_objective, has_practical, pass_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiisss", $subject_name, $subject_code, $default_marks, $has_creative, $has_objective, $has_practical, $pass_type, $status);
        } else {
            $stmt = $conn->prepare("INSERT INTO subjects (subject_name, subject_code, default_marks, has_creative, has_objective, has_practical, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssiiiss", $subject_name, $subject_code, $default_marks, $has_creative, $has_objective, $has_practical, $status);
        }
        $stmt->execute();
        $subject_id = $stmt->insert_id;
    } elseif ($_POST['action'] === 'edit') {
        $subject_id = $_POST['id'];
        if ($hasPassType) {
            $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, subject_code=?, default_marks=?, has_creative=?, has_objective=?, has_practical=?, pass_type=?, status=? WHERE id=?");
            $stmt->bind_param("ssiiiissi", $subject_name, $subject_code, $default_marks, $has_creative, $has_objective, $has_practical, $pass_type, $status, $subject_id);
        } else {
            $stmt = $conn->prepare("UPDATE subjects SET subject_name=?, subject_code=?, default_marks=?, has_creative=?, has_objective=?, has_practical=?, status=? WHERE id=?");
            $stmt->bind_param("ssiiiisi", $subject_name, $subject_code, $default_marks, $has_creative, $has_objective, $has_practical, $status, $subject_id);
        }
        $stmt->execute();
        $conn->query("DELETE FROM subject_group_map WHERE subject_id = $subject_id");
    }

    // Insert mappings
    if (!empty($_POST['group_map'])) {
        foreach ($_POST['group_map'] as $map) {
            list($class_id, $group_name, $type) = explode('|', $map);
            $stmt = $conn->prepare("INSERT INTO subject_group_map (subject_id, class_id, group_name, type) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("iiss", $subject_id, $class_id, $group_name, $type);
            $stmt->execute();
        }
    }
    header("Location: manage_subjects.php");
    exit();
}

// Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $conn->query("DELETE FROM subjects WHERE id=$id");
    $conn->query("DELETE FROM subject_group_map WHERE subject_id=$id");
    header("Location: manage_subjects.php");
    exit();
}
if (isset($_GET['edit'])) {
    $id = $_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM subjects WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $subject = $stmt->get_result()->fetch_assoc();
    if ($subject) {
        $_POST = $subject; // Pre-fill the form with subject data
        $_POST['action'] = 'edit';
        $_POST['id'] = $id;
    }
} else {
    $_POST = [
        'action' => 'add',
        'subject_name' => '',
        'subject_code' => '',
        'default_marks' => 100,
        'has_creative' => 1,
        'has_objective' => 1,
        'has_practical' => 1,
        'pass_type' => 'total',
        'status' => 'Active',
    ];
}
// Fetch classes and subjects (with filters + pagination)
$classes = $conn->query("SELECT id, class_name FROM classes ORDER BY id ASC");

// Filters from GET
$f_class_id = isset($_GET['f_class_id']) && ctype_digit($_GET['f_class_id']) ? (int)$_GET['f_class_id'] : '';
$allowedGroups = ['none','Science','Humanities','Business Studies'];
$f_group = isset($_GET['f_group']) && in_array($_GET['f_group'], $allowedGroups, true) ? $_GET['f_group'] : '';
$allowedTypes = ['Compulsory','Optional'];
$f_type = isset($_GET['f_type']) && in_array($_GET['f_type'], $allowedTypes, true) ? $_GET['f_type'] : '';
$allowedStatus = ['Active','Inactive'];
$f_status = isset($_GET['f_status']) && in_array($_GET['f_status'], $allowedStatus, true) ? $_GET['f_status'] : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

$where = "WHERE 1=1";
if ($f_class_id !== '') { $where .= " AND gm.class_id = ".$f_class_id; }
if ($f_group !== '') { $where .= " AND gm.group_name = '".$conn->real_escape_string($f_group)."'"; }
if ($f_type !== '') { $where .= " AND gm.type = '".$conn->real_escape_string($f_type)."'"; }
if ($f_status !== '') { $where .= " AND s.status = '".$conn->real_escape_string($f_status)."'"; }
if ($q !== '') {
    $qEsc = $conn->real_escape_string($q);
    $where .= " AND (s.subject_name LIKE '%$qEsc%' OR s.subject_code LIKE '%$qEsc%')";
}

// Pagination
$perPage = 20;
$page = isset($_GET['page']) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

// Count
$countSql = "SELECT COUNT(*) AS cnt
FROM subjects s
JOIN subject_group_map gm ON s.id = gm.subject_id
JOIN classes c ON gm.class_id = c.id
$where";
$countRes = $conn->query($countSql);
$totalRows = 0; if ($countRes) { $rowCnt = $countRes->fetch_assoc(); $totalRows = (int)$rowCnt['cnt']; }
$totalPages = ($perPage > 0) ? (int)ceil($totalRows / $perPage) : 1;

// Main list
$subjectsSql = <<<SQL
SELECT 
    s.*, 
    c.class_name, 
    gm.type,
    gm.group_name,
    (
        SELECT CONCAT('[', GROUP_CONCAT(
            CONCAT('{"class_id":', gm2.class_id,
                   ',"group_name":"', COALESCE(gm2.group_name,''), '"',
                   ',"type":"', COALESCE(gm2.type,''), '"',
                   '}'
            )
        ), ']')
        FROM subject_group_map gm2 
        WHERE gm2.subject_id = s.id
    ) AS group_mappings
FROM subjects s
JOIN subject_group_map gm ON s.id = gm.subject_id
JOIN classes c ON gm.class_id = c.id
$where
ORDER BY s.id DESC
LIMIT $perPage OFFSET $offset
SQL;
$subjects = $conn->query($subjectsSql);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Manage Subjects</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body>
<?php include '../includes/header.php'; ?>
<div class="d-flex">
    <?php include '../includes/sidebar.php'; ?>
    <div class="container mt-4">
        <h4>বিষয় ব্যবস্থাপনা</h4>
        <form method="POST" action="" class="card p-3 mb-4">
            <input type="hidden" name="action" value="add" id="form_action">
            <input type="hidden" name="id" id="subject_id">
            <div class="row">
                <div class="col-md-4">
                    <label>বিষয়ের নাম</label>
                    <input type="text" name="subject_name" id="subject_name" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label>কোড</label>
                    <input type="text" name="subject_code" id="subject_code" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label>পূর্ণমান</label>
                    <input type="number" name="default_marks" id="default_marks" class="form-control" value="100" required>
                </div>
            </div>

            <div class="row mt-2">
                <div class="col-md-3">
                    <label>সৃজনশীল</label>
                    <select name="has_creative" id="has_creative" class="form-select">
                        <option value="1">হ্যাঁ</option>
                        <option value="0">না</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>নৈর্ব্যক্তিক</label>
                    <select name="has_objective" id="has_objective" class="form-select">
                        <option value="1">হ্যাঁ</option>
                        <option value="0">না</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>ব্যবহারিক</label>
                    <select name="has_practical" id="has_practical" class="form-select">
                        <option value="1">হ্যাঁ</option>
                        <option value="0">না</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label>পাস টাইপ</label>
                    <select name="pass_type" id="pass_type" class="form-select">
                        <option value="total">মোট নাম্বার</option>
                        <option value="individual">আলাদা আলাদা</option>
                    </select>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4">
                    <label>স্ট্যাটাস</label>
                    <select name="status" id="status" class="form-select">
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label>গ্রুপ-শ্রেণি ও ধরন (একাধিক সিলেক্ট করুন)</label>
<select name="group_map[]" class="form-select" multiple size="8">
    <?php
    $group_names = [
        "none" => "গ্রুপ নেই",
        "Science" => "বিজ্ঞান",
        "Humanities" => "মানবিক",
        "Business Studies" => "ব্যবসায়"
    ];

    $types = ["Compulsory" => "Compulsory", "Optional" => "Optional"];

    foreach ($classes as $c) {
        foreach ($group_names as $key => $group) {
            // ✅ এখানে ব্লক {} ব্যবহার করা হয়েছে
            if (in_array($c['id'], [1, 2, 3]) && $key !== 'none') {
                continue;
            }

            foreach ($types as $type) {
                echo '<option value="' . $c['id'] . '|' . $key . '|' . $type . '">'
                    . $c['class_name'] . ' - ' . $group . ' - ' . $type .
                    '</option>';
            }
        }
    }
    ?>
</select>


                </div>
            </div>
            <div class="mt-3 text-end">
                <button type="submit" class="btn btn-primary" id="submit_btn">Save Subject</button>
            </div>
        </form>

        <!-- Filters (table-filter) -->
        <form method="GET" action="" class="card p-3 mb-3">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">শ্রেণি</label>
                    <?php $classesFilter = $conn->query("SELECT id, class_name FROM classes ORDER BY id ASC"); ?>
                    <select name="f_class_id" class="form-select">
                        <option value="">সব</option>
                        <?php while($cl = $classesFilter->fetch_assoc()): ?>
                            <option value="<?= (int)$cl['id'] ?>" <?= ($f_class_id!=='' && (int)$f_class_id===(int)$cl['id'])?'selected':'' ?>><?= htmlspecialchars($cl['class_name']) ?></option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">গ্রুপ</label>
                    <select name="f_group" class="form-select">
                        <option value="">সব</option>
                        <option value="none" <?= ($f_group==='none'?'selected':'') ?>>গ্রুপ নেই</option>
                        <option value="Science" <?= ($f_group==='Science'?'selected':'') ?>>বিজ্ঞান</option>
                        <option value="Humanities" <?= ($f_group==='Humanities'?'selected':'') ?>>মানবিক</option>
                        <option value="Business Studies" <?= ($f_group==='Business Studies'?'selected':'') ?>>ব্যবসায়</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">ধরণ</label>
                    <select name="f_type" class="form-select">
                        <option value="">সব</option>
                        <option value="Compulsory" <?= ($f_type==='Compulsory'?'selected':'') ?>>Compulsory</option>
                        <option value="Optional" <?= ($f_type==='Optional'?'selected':'') ?>>Optional</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">স্ট্যাটাস</label>
                    <select name="f_status" class="form-select">
                        <option value="">সব</option>
                        <option value="Active" <?= ($f_status==='Active'?'selected':'') ?>>Active</option>
                        <option value="Inactive" <?= ($f_status==='Inactive'?'selected':'') ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">সার্চ (নাম/কোড)</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" class="form-control" placeholder="উদাহরণ: বাংলা বা 101">
                </div>
            </div>
            <div class="mt-2 text-end">
                <button type="submit" class="btn btn-primary">ফিল্টার</button>
                <a href="manage_subjects.php" class="btn btn-secondary">রিসেট</a>
            </div>
        </form>

        <table class="table table-bordered">
            <thead>
            <tr>
                <th>#</th>
                <th>শ্রেণি</th>
                <th>বিষয়</th>
                <th>গ্রুপ</th>
                <th>কোড</th>
                <th>ধরণ</th>
                <th>পূর্ণমান</th>
                <th>সৃজনশীল</th>
                <th>নৈর্ব্যক্তিক</th>
                <th>ব্যবহারিক</th>
                <th>পাস টাইপ</th>
                <th>স্ট্যাটাস</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php
             $i=1; while($row = $subjects->fetch_assoc()): ?>
                <tr>
                    <td><?= $i++ ?></td>
                    <td><?= $row['class_name'] ?></td>
                    <td><?= $row['subject_name'] ?></td>
                    <td><?php 
                        $g = isset($row['group_name']) ? $row['group_name'] : '';
                        $gLabel = ($g === null || $g === '' || strtolower($g) === 'none') ? 'গ্রুপ নেই' : $g;
                        echo htmlspecialchars($gLabel);
                    ?></td>
                    <td><?= $row['subject_code'] ?></td>
                    <td><?= $row['type'] ?></td>
                    <td><?= $row['default_marks'] ?></td>
                    <td><?= $row['has_creative'] ? '✔' : '✘' ?></td>
                    <td><?= $row['has_objective'] ? '✔' : '✘' ?></td>
                    <td><?= $row['has_practical'] ? '✔' : '✘' ?></td>
                    <td>
                        <?php
                        // Show meaningful default even if column was missing prior to migration
                        if (!$hasPassType || !array_key_exists('pass_type', $row)) {
                            echo 'মোট নাম্বার';
                        } else {
                            $pt = $row['pass_type'];
                            if ($pt === null || $pt === '') { $pt = 'total'; }
                            echo ($pt === 'individual') ? 'আলাদা আলাদা' : 'মোট নাম্বার';
                        }
                        ?>
                    </td>
                    <td><?= $row['status'] ?></td>
                    <td> <button class="btn btn-sm btn-info" onclick='editSubject(<?= json_encode($row) ?>)'>Edit</button>
                        <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure?')">Delete</a>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination">
                <?php
                // Build base query string without page
                $params = $_GET; unset($params['page']);
                $base = 'manage_subjects.php';
                $qs = http_build_query($params);
                function pageLink($p, $base, $qs){
                    $sep = ($qs!=='') ? '&' : '';
                    return $base.'?'.($qs).$sep.'page='.$p;
                }
                $prevDisabled = ($page<=1)?' disabled':'';
                $nextDisabled = ($page>=$totalPages)?' disabled':'';
                ?>
                <li class="page-item<?= $prevDisabled ?>">
                    <a class="page-link" href="<?= ($page>1)?pageLink($page-1,$base,$qs):'#' ?>">Prev</a>
                </li>
                <?php
                $start = max(1, $page-2); $end = min($totalPages, $page+2);
                for($p=$start;$p<=$end;$p++):
                    $active = ($p===$page)?' active':'';
                ?>
                <li class="page-item<?= $active ?>"><a class="page-link" href="<?= pageLink($p,$base,$qs) ?>"><?= $p ?></a></li>
                <?php endfor; ?>
                <li class="page-item<?= $nextDisabled ?>">
                    <a class="page-link" href="<?= ($page<$totalPages)?pageLink($page+1,$base,$qs):'#' ?>">Next</a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<script>
function editSubject(subject) {
    document.getElementById('form_action').value = 'edit';
    document.getElementById('subject_id').value = subject.id;
    document.getElementById('subject_name').value = subject.subject_name;
    document.getElementById('subject_code').value = subject.subject_code;
    document.getElementById('default_marks').value = subject.default_marks;
    document.getElementById('has_creative').value = subject.has_creative ? 1 : 0;
    document.getElementById('has_objective').value = subject.has_objective ? 1 : 0;
    document.getElementById('has_practical').value = subject.has_practical ? 1 : 0;
    document.getElementById('pass_type').value = subject.pass_type || 'total';
    document.getElementById('status').value = subject.status;

    // Clear previous selections
    const groupMapSelect = document.querySelector('select[name="group_map[]"]');
    groupMapSelect.selectedIndex = -1;

    // Set selected options based on the subject's group mappings
    const groupMappings = JSON.parse(subject.group_mappings || '[]');
    groupMappings.forEach(mapping => {
        const optionValue = `${mapping.class_id}|${mapping.group_name}|${mapping.type}`;
        const option = Array.from(groupMapSelect.options).find(opt => opt.value === optionValue);
        if (option) {
            option.selected = true;
        }
    });
}   
</script>
