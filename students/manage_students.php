<?php
include '../auth/session.php';
$ALLOWED_ROLES = ['super_admin'];
include '../config/db.php';
include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php';

// --- Pagination & Filters ---
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="bn">শিক্ষার্থী ব্যবস্থাপনা</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="/jsssms/dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Students</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
<?php
// params
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if ($per_page <= 0) $per_page = 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

$filter_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$filter_section = isset($_GET['section_id']) ? (int)$_GET['section_id'] : 0;
$filter_group = isset($_GET['group']) ? trim($_GET['group']) : '';
$filter_subject = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
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
if ($filter_group !== '') {
    $escGroup = $conn->real_escape_string(strtolower($filter_group));
    $where .= " AND LOWER(s.student_group) = '" . $escGroup . "'";
}
if ($q !== '') {
    $esc = $conn->real_escape_string($q);
    $where .= " AND (s.student_name LIKE '%" . $esc . "%' OR s.student_id LIKE '%" . $esc . "%' OR s.father_name LIKE '%".$esc."%')";
}

// optional subject join if filtering by subject
$subjectJoin = '';
if ($filter_subject > 0) {
    $subjectJoin = " JOIN student_subjects ssf ON ssf.student_id = s.student_id AND ssf.subject_id = " . (int)$filter_subject . " ";
}

// total count
$countSql = "SELECT COUNT(DISTINCT s.id) AS cnt FROM students s " . $subjectJoin . $where;
$countRes = $conn->query($countSql);
$totalRows = ($countRes && $cr = $countRes->fetch_assoc()) ? (int)$cr['cnt'] : 0;

// fetch page rows with joins (conditionally include sections)
if ($hasSections) {
    $dataSql = "SELECT s.*, c.class_name, sec.section_name 
                FROM students s
                " . $subjectJoin . "
                LEFT JOIN classes c ON s.class_id = c.id
                LEFT JOIN sections sec ON s.section_id = sec.id
                " . $where . "
                ORDER BY s.id DESC
                LIMIT " . (int)$offset . ", " . (int)$per_page;
} else {
    $dataSql = "SELECT s.*, c.class_name, '' AS section_name 
                FROM students s
                " . $subjectJoin . "
                LEFT JOIN classes c ON s.class_id = c.id
                " . $where . "
                ORDER BY s.id DESC
                LIMIT " . (int)$offset . ", " . (int)$per_page;
}
$result = $conn->query($dataSql);

// load classes (sections will be loaded dynamically after class selection)
$classesRes = $conn->query("SELECT * FROM classes ORDER BY class_name ASC");
$sectionsRes = null; // removed preloading sections to avoid initial stale options
?>

<style>
    .truncate{max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    @media (max-width:576px){ .card .card-header h3{font-size:1.05rem} }
</style>

<div class="row">
    <div class="col-12">
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title mb-0 bn">ছাত্র/ছাত্রী তালিকা</h3>
                    <div class="card-tools">
                        <a href="add_student.php" class="btn btn-sm btn-success"><i class="fas fa-user-plus mr-1"></i> Add Student</a>
                    </div>
                </div>
            <div class="card-body">
                    <form method="get" id="filterForm">
                    <div class="form-row">
                        <div class="col-6 col-md-2 mb-2">
                            <label class="small mb-1">প্রতি পৃষ্ঠা</label>
                            <select name="per_page" class="form-control form-control-sm">
                                <?php foreach ([10,25,50,100] as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $per_page===$opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <label class="small mb-1">শ্রেণি</label>
                            <select name="class_id" class="form-control form-control-sm">
                                <option value="0">সব শ্রেণি</option>
                                <?php if($classesRes) while($c = $classesRes->fetch_assoc()): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filter_class===(int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 mb-2">
                            <label class="small mb-1">গ্রুপ</label>
                            <select name="group" id="group" class="form-control form-control-sm">
                                <option value="">সব গ্রুপ</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <label class="small mb-1">বিষয়</label>
                            <select name="subject_id" id="subject_id" class="form-control form-control-sm">
                                <option value="0">সব বিষয়</option>
                            </select>
                        </div>
                                    <div class="col-12 col-md-3 mb-2">
                            <label class="small mb-1">Search</label>
                            <div class="input-group input-group-sm">
                                            <input type="search" name="q" id="searchInput" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="Name or Student ID" autocomplete="off">
                                <div class="input-group-append">
                                    <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-md-1 mb-2">
                            <label class="d-none d-md-block small mb-1">&nbsp;</label>
                            <a href="manage_students.php" class="btn btn-outline-secondary btn-sm btn-block"><i class="fas fa-undo"></i> Reset</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm mb-0">
                        <thead class="thead-dark">
                            <tr>
                                <th>ক্রমিক</th>
                                <th>আইডি</th>
                                <th>নাম</th>
                                <th class="d-none d-sm-table-cell">পিতার নাম</th>
                                <th class="d-none d-md-table-cell">মাতার নাম</th>
                                <th>শ্রেণি</th>
                                <th class="d-none d-sm-table-cell">শাখা</th>
                                <th>রোল</th>
                                <th class="d-none d-md-table-cell">গ্রুপ</th>
                                <th>বিষয় কোড</th>
                                <th class="d-none d-md-table-cell">ছবি</th>
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
                <td class="truncate" title="<?= htmlspecialchars($row['student_name']); ?>"><?= htmlspecialchars($row['student_name']); ?></td>
                <td class="d-none d-sm-table-cell truncate" title="<?= htmlspecialchars($row['father_name']); ?>"><?= htmlspecialchars($row['father_name']); ?></td>
                <td class="d-none d-md-table-cell truncate" title="<?= htmlspecialchars($row['mother_name']); ?>"><?= htmlspecialchars($row['mother_name']); ?></td>
                <td><?= htmlspecialchars($row['class_name']); ?></td>
                <td class="d-none d-sm-table-cell"><?= htmlspecialchars($row['section_name']); ?></td>
                <td><?= htmlspecialchars($row['roll_no']); ?></td>
                <td class="d-none d-md-table-cell"><?= htmlspecialchars($row['student_group'] ?? '') ?></td>
                <td>
                  <?php
                    if (!empty($subs)) {
                        // Compulsory first (green)
                        foreach ($compulsory as $s) {
                            echo '<span class="badge badge-success mr-1">' . htmlspecialchars($s['code']) . '</span>';
                        }
                        // Optional last (blue)
                        if ($optionalLast) {
                            echo '<span class="badge badge-primary mr-1">' . htmlspecialchars($optionalLast['code']) . '</span>';
                        }
                    } else {
                        echo 'N/A';
                    }
                  ?>
              
              </td>
                <td>
                    <?php if ($row['photo']): ?>
                        <img class="d-none d-md-inline-block" src="../uploads/students/<?= htmlspecialchars($row['photo']); ?>" width="40" height="40" alt="Photo">
                        <span class="d-inline d-md-none">Yes</span>
                    <?php else: ?>
                        <span class="text-muted">No</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle btn-sm" type="button" data-toggle="dropdown">
                            Action
                        </button>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="edit_student.php?student_id=<?= $row['student_id']; ?>" target="_blank">Edit</a></li>
                            <li><a class="dropdown-item" href="add_subjects.php?student_id=<?= $row['student_id']; ?>" target="_blank">Select Subject</a></li>
                            <li><a class="dropdown-item text-danger" href="delete_student.php?id=<?= $row['id']; ?>" onclick="return confirm('Are you sure?');">Delete</a></li>
                        </ul>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
            <div class="card-footer d-flex align-items-center">
        <div class="small text-muted">
          <?php
          $start = ($totalRows>0) ? ($offset+1) : 0;
          $end = min($offset + $per_page, $totalRows);
          echo "Showing <strong>{$start}</strong> to <strong>{$end}</strong> of <strong>{$totalRows}</strong> students";
          ?>
        </div>
                <div class="ml-auto">
          <?php
          $totalPages = ($per_page>0) ? (int)ceil($totalRows / $per_page) : 1;
          if ($totalPages > 1):
              $baseParams = [];
              if ($filter_class) $baseParams['class_id'] = $filter_class;
              if ($filter_section) $baseParams['section_id'] = $filter_section;
              if ($q !== '') $baseParams['q'] = $q;
              if ($filter_group !== '') $baseParams['group'] = $filter_group;
              if ($filter_subject > 0) $baseParams['subject_id'] = $filter_subject;
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
  </div>
</div>

    <script>
        // Debounced auto-submit on typing in search box
        (function(){
            var form = document.getElementById('filterForm');
            var input = document.getElementById('searchInput');
            if(!form || !input) return;
            var timer = null;
            input.addEventListener('input', function(){
                if (timer) clearTimeout(timer);
                timer = setTimeout(function(){ form.submit(); }, 500); // 500ms debounce
            });
        })();

        // Load groups & subjects after class selection
        (function(){
            var classSel = document.querySelector('select[name="class_id"]');
            var groupSel = document.getElementById('group');
            var subjectSel = document.getElementById('subject_id');
            if(!classSel || !groupSel || !subjectSel) return;

            function loadGroups(){
                var cid = classSel.value;
                groupSel.innerHTML = '<option value="">লোড হচ্ছে...</option>';
                subjectSel.innerHTML = '<option value="0">সব বিষয়</option>';
                if(!cid){ groupSel.innerHTML = '<option value="">সব গ্রুপ</option>'; return; }
                fetch('../ajax/get_groups.php?class_id=' + encodeURIComponent(cid))
                  .then(r=>r.json())
                  .then(list => {
                      var opts = '<option value="">সব গ্রুপ</option>';
                      list.forEach(function(g){ opts += '<option value="'+g+'">'+g+'</option>'; });
                      groupSel.innerHTML = opts;
                      // restore selection
                      var url = new URL(window.location.href);
                      var gv = url.searchParams.get('group') || '';
                      if(gv && groupSel.querySelector('option[value="'+gv+'"]')) groupSel.value = gv;
                      loadSubjects();
                  })
                  .catch(()=>{ groupSel.innerHTML = '<option value="">সব গ্রুপ</option>'; });
            }

            function loadSubjects(){
                var cid = classSel.value;
                if(!cid){ subjectSel.innerHTML = '<option value="0">সব বিষয়</option>'; return; }
                subjectSel.innerHTML = '<option value="0">লোড হচ্ছে...</option>';
                fetch('../ajax/get_subjects_by_class.php?class_id=' + encodeURIComponent(cid))
                  .then(r=>r.json())
                  .then(list => {
                      var opts = '<option value="0">সব বিষয়</option>';
                      list.forEach(function(s){ opts += '<option value="'+s.id+'">'+s.name+'</option>'; });
                      subjectSel.innerHTML = opts;
                      var url = new URL(window.location.href);
                      var sv = url.searchParams.get('subject_id') || '0';
                      if(sv && subjectSel.querySelector('option[value="'+sv+'"]')) subjectSel.value = sv;
                  })
                  .catch(()=>{ subjectSel.innerHTML = '<option value="0">সব বিষয়</option>'; });
            }

            classSel.addEventListener('change', loadGroups);
            // initial load if page reloaded with class
            if(classSel.value){ loadGroups(); }
        })();
    </script>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
                                

