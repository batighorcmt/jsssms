<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}
include '../config/db.php';
include '../includes/header.php'; ?>
<div class="d-flex">
<?php include '../includes/sidebar.php';

// --- Pagination & Filters ---
// params
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($per_page <= 0) $per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$filter_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$filter_section = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';

// detect presence of sections table to avoid errors on DBs without it
$hasSections = false;
if ($chk = $conn->query("SHOW TABLES LIKE 'sections'")) {
    $hasSections = ($chk->num_rows > 0);
}

// build where clauses
$where = "WHERE 1";
if ($filter_class > 0) $where .= " AND s.class_id = " . $filter_class;
if ($hasSections && $filter_section > 0) $where .= " AND s.section_id = " . $filter_section;
if ($q !== '') {
    $esc = $conn->real_escape_string($q);
    $where .= " AND (s.student_name LIKE '%" . $esc . "%' OR s.student_id LIKE '%" . $esc . "%' OR s.father_name LIKE '%".$esc."%')";
}

// total count
$countSql = "SELECT COUNT(*) AS cnt FROM students s " . $where;
$countRes = $conn->query($countSql);
$totalRows = ($countRes && $cr = $countRes->fetch_assoc()) ? (int)$cr['cnt'] : 0;

// fetch page rows with joins (conditionally include sections)
if ($hasSections) {
    $dataSql = "SELECT s.*, c.class_name, sec.section_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                " . $where . "
                ORDER BY s.id DESC
                LIMIT " . (int)$offset . ", " . (int)$per_page;
} else {
    $dataSql = "SELECT s.*, c.class_name, '' AS section_name 
                FROM students s
                LEFT JOIN classes c ON s.class_id = c.id
                " . $where . "
                ORDER BY s.id DESC
                LIMIT " . (int)$offset . ", " . (int)$per_page;
}
$result = $conn->query($dataSql);

// load classes and sections for filters
$classesRes = $conn->query("SELECT * FROM classes ORDER BY class_name ASC");
// Prefer ordering by section_name if present; fallback to id
if ($hasSections) {
    $orderCol = 'section_name';
    $colCheck = $conn->query("SHOW COLUMNS FROM sections LIKE 'section_name'");
    if (!$colCheck || $colCheck->num_rows === 0) {
        $orderCol = 'id';
    }
    $sectionsRes = $conn->query("SELECT * FROM sections ORDER BY " . $orderCol . " ASC");
} else {
    $sectionsRes = null;
}
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4>ছাত্র/ছাত্রী তালিকা</h4>
        <a href="add_student.php" class="btn btn-success">+ Add Student</a>
    </div>

    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <label class="form-label">প্রদর্শন প্রতি পৃষ্ঠা</label>
            <select name="per_page" class="form-select">
                <?php foreach ([10,25,50,100] as $opt): ?>
                    <option value="<?php echo $opt; ?>" <?php echo $per_page===$opt ? 'selected' : ''; ?>><?php echo $opt; ?> per page</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">শ্রেণি</label>
            <select name="class_id" class="form-select">
                <option value="0">সব শ্রেণি</option>
                <?php if($classesRes) while($c = $classesRes->fetch_assoc()): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $filter_class===(int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">শাখা</label>
            <select name="section_id" class="form-select">
                <option value="0">সব শাখা</option>
                <?php if($sectionsRes) { $sectionsRes->data_seek(0); while($s = $sectionsRes->fetch_assoc()): ?>
                    <option value="<?php echo $s['id']; ?>" <?php echo $filter_section===(int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['section_name'] ?? $s['name'] ?? ''); ?></option>
                <?php endwhile; } ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label">Search</label>
            <input type="search" name="q" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or Student ID">
        </div>
        <div class="col-auto align-self-end">
            <button class="btn btn-primary">Filter</button>
            <a href="manage_students.php" class="btn btn-outline-secondary">Reset</a>
        </div>
    </form>

    <table class="table table-bordered table-hover">
        <thead class="table-dark">
            <tr>
                <th>ক্রমিক</th>
                <th>আইডি</th>
                <th>নাম</th>
                <th>পিতার নাম</th>
                <th>মাতার নাম</th>
                <th>শ্রেণি</th>
                <th>শাখা</th>
                <th>রোল</th>
                <th>গ্রুপ</th>
                <th>বিষয় কোড</th>
                <th>ছবি</th>
                <th>অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $sl = $offset + 1;
            while ($row = $result->fetch_assoc()):
                // শিক্ষার্থীর বিষয়ের কোড + টাইপ (Compulsory/Optional) প্রস্তুত করুন
                $student_id = $row['student_id'];
                $student_group = trim((string)($row['student_group'] ?? ''));
                $class_id_row = (int)$row['class_id'];
                $chosen_optional_id = isset($row['optional_subject_id']) ? (int)$row['optional_subject_id'] : 0; // may not exist; handled below

                $subs = [];
                $stmt = $conn->prepare("SELECT s.id, s.subject_code,
                        CASE
                          WHEN SUM(CASE WHEN gm.group_name = ? AND gm.type = 'Optional' THEN 1 ELSE 0 END) > 0 THEN 'Optional'
                          WHEN SUM(CASE WHEN UPPER(gm.group_name) = 'NONE' AND gm.type = 'Optional' THEN 1 ELSE 0 END) > 0 THEN 'Optional'
                          ELSE 'Compulsory'
                        END AS effective_type
                      FROM student_subjects ss
                      JOIN subjects s ON ss.subject_id = s.id
                      JOIN subject_group_map gm ON gm.subject_id = s.id AND gm.class_id = ?
                      WHERE ss.student_id = ?
                      GROUP BY s.id, s.subject_code");
                if ($stmt) {
                    $stmt->bind_param('sis', $student_group, $class_id_row, $student_id);
                    $stmt->execute();
                    $rs = $stmt->get_result();
                    while ($sr = $rs->fetch_assoc()) {
                        $subs[] = [
                            'id' => (int)$sr['id'],
                            'code' => (string)$sr['subject_code'],
                            'type' => (string)$sr['effective_type'],
                        ];
                    }
                    $stmt->close();
                }

                // যদি explicit optional_subject_id না থাকে, তাহলে একটিকে Optional হিসেবে বেছে নিন (effective_type Optional গুলোর মধ্যে থেকে)
                if (empty($chosen_optional_id)) {
                    $optionalCandidates = [];
                    foreach ($subs as $s) if (strcasecmp($s['type'], 'Optional') === 0) $optionalCandidates[] = $s;
                    if (count($optionalCandidates) === 1) {
                        $chosen_optional_id = $optionalCandidates[0]['id'];
                    } elseif (count($optionalCandidates) > 1) {
                        // deterministic: highest numeric subject code
                        usort($optionalCandidates, function($a,$b){
                            $na = is_numeric($a['code']) ? (int)$a['code'] : -1;
                            $nb = is_numeric($b['code']) ? (int)$b['code'] : -1;
                            return $nb <=> $na; // desc
                        });
                        $chosen_optional_id = $optionalCandidates[0]['id'];
                    }
                }

                // সাজান: আগে সব Compulsory (সবুজ), শেষে 1টি Optional (নীল)
                $compulsory = [];
                $optionalLast = null;
                foreach ($subs as $s) {
                    if (!empty($chosen_optional_id) && $s['id'] === $chosen_optional_id) {
                        $optionalLast = $s;
                    } else {
                        $compulsory[] = $s;
                    }
                }
                // Compulsory গুলো subject_code ASC
                usort($compulsory, function($a,$b){
                    $na = is_numeric($a['code']) ? (int)$a['code'] : PHP_INT_MAX;
                    $nb = is_numeric($b['code']) ? (int)$b['code'] : PHP_INT_MAX;
                    return $na <=> $nb;
                });
            ?>
            <tr>
                <td><?= $sl++; ?></td>
                <td><?= htmlspecialchars($row['student_id']); ?></td>
                <td><?= htmlspecialchars($row['student_name']); ?></td>
                <td><?= htmlspecialchars($row['father_name']); ?></td>
                <td><?= htmlspecialchars($row['mother_name']); ?></td>
                <td><?= htmlspecialchars($row['class_name']); ?></td>
                <td><?= htmlspecialchars($row['section_name']); ?></td>
                <td><?= htmlspecialchars($row['roll_no']); ?></td>
                <td><?= htmlspecialchars($row['student_group'] ?? '') ?></td>
                <td>
                  <?php
                    if (!empty($subs)) {
                        // Compulsory first (green)
                        foreach ($compulsory as $s) {
                            echo '<span class="badge bg-success me-1">' . htmlspecialchars($s['code']) . '</span>';
                        }
                        // Optional last (blue)
                        if ($optionalLast) {
                            echo '<span class="badge bg-primary me-1">' . htmlspecialchars($optionalLast['code']) . '</span>';
                        }
                    } else {
                        echo 'N/A';
                    }
                  ?>
              
              </td>
                <td>
                    <?php if ($row['photo']): ?>
                        <img src="../uploads/students/<?= htmlspecialchars($row['photo']); ?>" width="50" height="50">
                    <?php else: ?>
                        <span>No Photo</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle btn-sm" type="button" data-bs-toggle="dropdown">
                            Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="edit_student.php?student_id=<?= $row['student_id']; ?>">Edit</a></li>
                            <li><a class="dropdown-item" href="add_subjects.php?student_id=<?= $row['student_id']; ?>">Select Subject</a></li>
                            <li><a class="dropdown-item text-danger" href="delete_student.php?id=<?= $row['id']; ?>" onclick="return confirm('Are you sure?');">Delete</a></li>
                        </ul>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <!-- footer: showing X of Y and pagination -->
    <div class="d-flex justify-content-between align-items-center">
        <div class="small text-muted">
            <?php
            $start = ($totalRows>0) ? ($offset+1) : 0;
            $end = min($offset + $per_page, $totalRows);
            echo "Showing <strong>{$start}</strong> to <strong>{$end}</strong> of <strong>{$totalRows}</strong> students";
            ?>
        </div>
        <div>
            <?php
            $totalPages = ($per_page>0) ? (int)ceil($totalRows / $per_page) : 1;
            if ($totalPages > 1):
                $baseParams = [];
                if ($filter_class) $baseParams['class_id'] = $filter_class;
                if ($filter_section) $baseParams['section_id'] = $filter_section;
                if ($q !== '') $baseParams['q'] = $q;
                $baseParams['per_page'] = $per_page;
                $buildUrl = function($p) use ($baseParams) {
                    $base = 'manage_students.php?';
                    $params = $baseParams;
                    $params['page'] = $p;
                    return $base . http_build_query($params);
                };
            ?>
            <nav aria-label="Page navigation">
                <ul class="pagination mb-0">
                    <?php if ($page>1): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $buildUrl($page-1); ?>">&laquo;</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">&laquo;</span></li>
                    <?php endif; ?>

                    <?php
                    $startPage = max(1, $page - 3);
                    $endPage = min($totalPages, $page + 3);
                    if ($startPage > 1) {
                        echo '<li class="page-item"><a class="page-link" href="' . $buildUrl(1) . '">1</a></li>';
                        if ($startPage > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    for ($p = $startPage; $p <= $endPage; $p++):
                        if ($p == $page) {
                            echo '<li class="page-item active"><span class="page-link">' . $p . '</span></li>';
                        } else {
                            echo '<li class="page-item"><a class="page-link" href="' . $buildUrl($p) . '">' . $p . '</a></li>';
                        }
                    endfor;
                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="' . $buildUrl($totalPages) . '">' . $totalPages . '</a></li>';
                    }
                    ?>

                    <?php if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link" href="<?php echo $buildUrl($page+1); ?>">&raquo;</a></li>
                    <?php else: ?>
                        <li class="page-item disabled"><span class="page-link">&raquo;</span></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>

</div>


                                