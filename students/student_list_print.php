<?php
@ini_set('display_errors','0');
$ALLOWED_ROLES = ['super_admin','teacher'];
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
include '../config/db.php';

// Helpers: English digits and date formatting
if (!function_exists('bn_digits')) {
    // Keep the function name for compatibility but return input unchanged (English digits)
    function bn_digits($input): string {
        return (string)$input;
    }
}
if (!function_exists('bn_date')) {
    // Keep the function name for compatibility but format in English
    function bn_date(?string $dateStr): string {
        if (empty($dateStr)) return '';
        $ts = strtotime($dateStr);
        if ($ts === false) return '';
        $formatted = date('d/m/Y', $ts);
        return $formatted;
    }
}

// Load classes
$classes = [];
if ($res = $conn->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")) {
    while ($r = $res->fetch_assoc()) { $classes[] = $r; }
}
// Load distinct years from students
$years = [];
if ($resY = $conn->query("SELECT DISTINCT year FROM students WHERE year IS NOT NULL AND year<>'' ORDER BY year DESC")) {
    while ($r = $resY->fetch_assoc()) { $years[] = $r['year']; }
}

// Available columns (key => label in English)
$available_columns = [
    'serial' => 'Serial',
    'photo' => 'Photo',
    'student_id' => 'ID',
    'student_name' => 'Student Name',
    'father_name' => 'Father\'s Name',
    'mother_name' => 'Mother\'s Name',
    'roll_no' => 'Roll No',
    'class' => 'Class',
    'section' => 'Section',
    'dob' => 'Date of Birth',
    'gender' => 'Gender',
    'religion' => 'Religion',
    'status' => 'Status',
    'mobile_no' => 'Mobile',
    'address' => 'Address',
    'group' => 'Group',
    'year' => 'Year',
];
$selected_columns = array_keys($available_columns); // default: all
$column_orders = [];
foreach (array_keys($available_columns) as $idx => $k) { $column_orders[$k] = $idx + 1; }
$baseIndex = array_flip(array_keys($available_columns));

$filter_applied = false;
$students = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    $filter_applied = true;
    $class_id = isset($_POST['class_id']) ? (int)$_POST['class_id'] : 0;
    $section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
    $yearSel = isset($_POST['year']) ? trim((string)$_POST['year']) : '';
    $gender = isset($_POST['gender']) ? trim((string)$_POST['gender']) : '';
    $religion = isset($_POST['religion']) ? trim((string)$_POST['religion']) : '';
    $status = isset($_POST['status']) ? trim((string)$_POST['status']) : '';

    // Columns selection
    if (isset($_POST['columns']) && is_array($_POST['columns'])) {
        $selected_columns = array_values(array_intersect(array_keys($available_columns), array_map('strval', $_POST['columns'])));
        if (empty($selected_columns)) { $selected_columns = ['serial','student_id','student_name','roll_no','class','section','mobile_no']; }
    }
    // Column order posted
    $posted_orders = isset($_POST['order']) && is_array($_POST['order']) ? $_POST['order'] : [];
    foreach (array_keys($available_columns) as $k) {
        $column_orders[$k] = isset($posted_orders[$k]) ? max(1, (int)$posted_orders[$k]) : ($baseIndex[$k] + 1);
    }
    // Sort selected columns by posted order then base index
    usort($selected_columns, function($a,$b) use ($column_orders, $baseIndex){
        $oa = $column_orders[$a] ?? PHP_INT_MAX; $ob = $column_orders[$b] ?? PHP_INT_MAX;
        if ($oa === $ob) { return ($baseIndex[$a] ?? 0) <=> ($baseIndex[$b] ?? 0); }
        return $oa <=> $ob;
    });

    // Build SQL
    $where = [];
    if ($class_id > 0) { $where[] = "s.class_id = " . (int)$class_id; }
    if ($section_id > 0) { $where[] = "s.section_id = " . (int)$section_id; }
    if ($yearSel !== '') { $where[] = "s.year = '" . $conn->real_escape_string($yearSel) . "'"; }
    if ($gender !== '') { $where[] = "LOWER(s.gender) = '" . $conn->real_escape_string(strtolower($gender)) . "'"; }
    if ($religion !== '') { $where[] = "s.religion = '" . $conn->real_escape_string($religion) . "'"; }
    if ($status !== '') { $where[] = "s.status = '" . $conn->real_escape_string($status) . "'"; }

    $sql = "SELECT s.*, c.class_name, sec.section_name FROM students s
            LEFT JOIN classes c ON c.id = s.class_id
            LEFT JOIN sections sec ON sec.id = s.section_id";
    if (!empty($where)) { $sql .= " WHERE " . implode(' AND ', $where); }
    $sql .= " ORDER BY s.class_id ASC, s.section_id ASC, s.roll_no ASC, s.student_name ASC";
    $resS = $conn->query($sql);
    if ($resS) { while ($r = $resS->fetch_assoc()) { $students[] = $r; } }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <section class="content-header no-print">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">Student List</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item active">Student List</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <style>
                .no-print { display:block; }
                .print-only { display:none; }
                .stu-photo { width: 42px; height: 52px; object-fit: cover; border:1px solid #ddd; border-radius: 2px; }
                .table-sm td, .table-sm th { padding: .35rem; }
                .table thead th { position: sticky; top: 0; background:#f1f5f9; z-index:1; }
                .report-meta { font-size: 0.95rem; color:#374151; }
                /* Drag & drop */
                #columnList { display:flex; flex-direction:column; gap:6px; }
                .column-item { display:flex; align-items:center; padding:8px 10px; border:1px dashed #cbd5e0; border-radius:6px; background:#f8fafc; cursor:move; }
                .drag-handle { color:#6b7280; margin-right:6px; }
                @media print {
                    .no-print { display:none !important; }
                    .print-only { display:block !important; }
                    body { background-color:#fff; }
                    .main-header, .main-sidebar, .control-sidebar, .navbar, .sidebar, .breadcrumb, .main-footer { display:none !important; }
                    .content-wrapper { margin-left:0 !important; }
                    .card, .card-body { box-shadow:none !important; }
                    .table { font-size: 14px; }
                    .table th, .table td { padding: 6px !important; }
                    .table, .table th, .table td { color:#000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                    .table thead th { background:#e5e7eb !important; }
                    @page { margin: 0.5in; }
                }
            </style>

            <!-- Filter Form Card -->
            <div class="card shadow-sm no-print">
                <div class="card-header">
                    <h4 class="card-title mb-0">Select Filters</h4>
                </div>
                <div class="card-body">
                    <form action="" method="POST">
                        <input type="hidden" name="generate_report" value="1">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="class_id">Class</label>
                                    <select class="form-control" name="class_id" id="class_id">
                                        <option value="">Select</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= htmlspecialchars($class['id']) ?>" <?= (isset($class_id) && (int)$class_id === (int)$class['id']) ? 'selected' : '' ?>><?= htmlspecialchars($class['class_name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="section_id">Section (optional)</label>
                                    <select class="form-control" name="section_id" id="section_id">
                                        <option value="">All Sections</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="year">Academic Year</label>
                                    <select class="form-control" name="year" id="year">
                                        <option value="">All</option>
                                        <?php foreach ($years as $y): ?>
                                            <option value="<?= htmlspecialchars($y) ?>" <?= (isset($yearSel) && (string)$yearSel === (string)$y) ? 'selected' : '' ?>><?= htmlspecialchars($y) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="gender">Gender (optional)</label>
                                    <select class="form-control" name="gender" id="gender">
                                        <option value="">All</option>
                                        <option value="male" <?= (isset($gender) && strtolower($gender) === 'male') ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= (isset($gender) && strtolower($gender) === 'female') ? 'selected' : '' ?>>Female</option>
                                        <option value="other" <?= (isset($gender) && strtolower($gender) === 'other') ? 'selected' : '' ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="religion">Religion (optional)</label>
                                    <select class="form-control" name="religion" id="religion">
                                        <option value="">All</option>
                                        <option value="Islam" <?= (isset($religion) && $religion === 'Islam') ? 'selected' : '' ?>>Islam</option>
                                        <option value="Hinduism" <?= (isset($religion) && $religion === 'Hinduism') ? 'selected' : '' ?>>Hinduism</option>
                                        <option value="Christianity" <?= (isset($religion) && $religion === 'Christianity') ? 'selected' : '' ?>>Christianity</option>
                                        <option value="Buddhism" <?= (isset($religion) && $religion === 'Buddhism') ? 'selected' : '' ?>>Buddhism</option>
                                        <option value="Others" <?= (isset($religion) && $religion === 'Others') ? 'selected' : '' ?>>Others</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="status">Status (optional)</label>
                                    <select class="form-control" name="status" id="status">
                                        <option value="">All</option>
                                        <option value="Active" <?= (isset($status) && $status === 'Active') ? 'selected' : '' ?>>Active</option>
                                        <option value="Inactive" <?= (isset($status) && $status === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row no-print">
                            <div class="col-12">
                                <button class="btn btn-sm btn-outline-secondary mb-2" type="button" data-toggle="collapse" data-target="#columnsCollapse" aria-expanded="false" aria-controls="columnsCollapse">
                                    <i class="fas fa-sliders-h"></i> Configure Columns
                                </button>
                                <div id="columnsCollapse" class="collapse">
                                    <div id="columnList" class="d-block">
                                        <?php
                                        // Render items in current order for DnD UI
                                        $allKeys = array_keys($available_columns);
                                        usort($allKeys, function($a,$b) use ($column_orders, $baseIndex){
                                            $oa = $column_orders[$a] ?? ($baseIndex[$a] ?? 0);
                                            $ob = $column_orders[$b] ?? ($baseIndex[$b] ?? 0);
                                            if ($oa === $ob) { return ($baseIndex[$a] ?? 0) <=> ($baseIndex[$b] ?? 0); }
                                            return $oa <=> $ob;
                                        });
                                        foreach ($allKeys as $k):
                                            $checked = in_array($k, $selected_columns, true) ? 'checked' : '';
                                            $orderVal = (int)($column_orders[$k] ?? ($baseIndex[$k] + 1));
                                        ?>
                                            <div class="column-item" draggable="true" data-key="<?= htmlspecialchars($k) ?>">
                                                <span class="drag-handle"><i class="fas fa-grip-vertical"></i></span>
                                                <div class="form-check m-0">
                                                    <input class="form-check-input" type="checkbox" name="columns[]" value="<?= htmlspecialchars($k) ?>" id="col_<?= htmlspecialchars($k) ?>" <?= $checked ?>>
                                                    <label class="form-check-label" for="col_<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($available_columns[$k]) ?></label>
                                                </div>
                                                <input type="hidden" name="order[<?= htmlspecialchars($k) ?>]" value="<?= $orderVal ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row mt-3">
                            <div class="col-md-12">
                                <button type="submit" name="generate_report" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                                <button type="button" class="btn btn-success ml-2" onclick="window.print()"><i class="fas fa-print"></i> Print (Portrait)</button>
                                <button type="button" class="btn btn-secondary ml-2" onclick="printWithOrientation('landscape')"><i class="fas fa-print"></i> Print (Landscape)</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Student List -->
            <?php if ($filter_applied): ?>
                <div class="print-only my-2">
                    <div class="text-center">
                        <?php
                        // Prepare institute header data from config
                        $instName = isset($institute_name) ? trim($institute_name) : '';
                        $instAddr = isset($institute_address) ? trim($institute_address) : '';
                        ?>
                        <?php if ($instName !== ''): ?>
                            <h3 style="margin:0;"><?= htmlspecialchars($instName) ?></h3>
                        <?php endif; ?>
                        <?php if ($instAddr !== ''): ?>
                            <div style="margin:2px 0 6px 0; font-size: 0.95rem; color:#374151;">
                                <?= htmlspecialchars($instAddr) ?>
                            </div>
                        <?php endif; ?>
                        <h4>Student List</h4>
                        <p class="text-muted report-meta">
                            <?php
                            // Class label
                            $class_name = 'All';
                            if (!empty($class_id)) {
                                foreach ($classes as $c) { if ((int)$c['id'] === (int)$class_id) { $class_name = $c['class_name']; break; } }
                            }
                            // Section label: show actual selected section name if provided
                            $section_label = 'All';
                            if (!empty($section_id)) {
                                $section_label = '';
                                // Try to derive from loaded students first
                                if (!empty($students)) {
                                    foreach ($students as $s) {
                                        if ((int)($s['section_id'] ?? 0) === (int)$section_id && !empty($s['section_name'])) { $section_label = $s['section_name']; break; }
                                    }
                                }
                                // Fallback: fetch from DB
                                if ($section_label === '') {
                                    $secRes = $conn->query("SELECT section_name FROM sections WHERE id = ".(int)$section_id." LIMIT 1");
                                    if ($secRes && $row = $secRes->fetch_assoc()) { $section_label = $row['section_name']; }
                                }
                                // Final fallback: echo id if name not found
                                if ($section_label === '' || $section_label === null) { $section_label = (string)$section_id; }
                            }
                            $year_label = !empty($yearSel) ? $yearSel : 'All';
                            $gLabel = 'All';
                            if (!empty($gender)) {
                                $g = strtolower($gender);
                                if ($g === 'male') $gLabel = 'Male'; elseif ($g === 'female') $gLabel = 'Female'; else $gLabel = 'Other';
                            }
                            $religion_label = !empty($religion) ? $religion : 'All';
                            $status_label = !empty($status) ? $status : 'All';
                            echo 'Class: ' . htmlspecialchars($class_name) . ' | ';
                            echo 'Section: ' . htmlspecialchars($section_label) . ' | ';
                            echo 'Year: ' . htmlspecialchars($year_label) . ' | ';
                            echo 'Gender: ' . htmlspecialchars($gLabel) . ' | ';
                            echo 'Religion: ' . htmlspecialchars($religion_label) . ' | ';
                            echo 'Status: ' . htmlspecialchars($status_label);
                            ?>
                        </p>
                    </div>
                </div>

                <?php if (!empty($students)): ?>
                    <div class="card">
                        <div class="card-header no-print">
                            <h3 class="card-title">Results: Total <?= count($students) ?></h3>
                        </div>
                        <div class="card-body table-responsive p-0">
                            <table class="table table-bordered table-striped table-hover table-sm mb-0">
                                <thead>
                                <tr>
                                    <?php foreach ($selected_columns as $colKey): ?>
                                        <th><?= htmlspecialchars($available_columns[$colKey] ?? $colKey) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                                </thead>
                                <tbody>
                                <?php $i=1; foreach ($students as $s): ?>
                                    <tr>
                                        <?php foreach ($selected_columns as $colKey): ?>
                                            <?php
                                            switch ($colKey) {
                                                case 'serial':
                                                    echo '<td>' . $i . '</td>';
                                                    break;
                                                case 'photo':
                                                    $ph = trim((string)($s['photo'] ?? ''));
                                                    if ($ph !== '') {
                                                        $src = '../uploads/students/' . rawurlencode($ph);
                                                        echo '<td><img class="stu-photo" src="'. htmlspecialchars($src) .'" alt=""></td>';
                                                    } else { echo '<td></td>'; }
                                                    break;
                                                case 'student_id':
                                                    echo '<td>' . htmlspecialchars($s['student_id']) . '</td>';
                                                    break;
                                                case 'student_name':
                                                    echo '<td>' . htmlspecialchars($s['student_name']) . '</td>';
                                                    break;
                                                case 'father_name':
                                                    echo '<td>' . htmlspecialchars($s['father_name']) . '</td>';
                                                    break;
                                                case 'mother_name':
                                                    echo '<td>' . htmlspecialchars($s['mother_name']) . '</td>';
                                                    break;
                                                case 'roll_no':
                                                    echo '<td>' . htmlspecialchars($s['roll_no']) . '</td>';
                                                    break;
                                                case 'class':
                                                    echo '<td>' . htmlspecialchars($s['class_name'] ?? '') . '</td>';
                                                    break;
                                                case 'section':
                                                    echo '<td>' . htmlspecialchars($s['section_name'] ?? '') . '</td>';
                                                    break;
                                                case 'dob':
                                                    echo '<td>' . bn_date($s['date_of_birth'] ?? '') . '</td>';
                                                    break;
                                                case 'gender':
                                                    echo '<td>' . htmlspecialchars($s['gender'] ?? '') . '</td>';
                                                    break;
                                                case 'religion':
                                                    echo '<td>' . htmlspecialchars($s['religion'] ?? '') . '</td>';
                                                    break;
                                                case 'status':
                                                    echo '<td>' . htmlspecialchars($s['status'] ?? '') . '</td>';
                                                    break;
                                                case 'mobile_no':
                                                    echo '<td>' . htmlspecialchars($s['mobile_no'] ?? '') . '</td>';
                                                    break;
                                                case 'address':
                                                    $parts = [];
                                                    foreach (['village','post_office','upazilla','district'] as $f) {
                                                        if (!empty($s[$f])) { $parts[] = $s[$f]; }
                                                    }
                                                    echo '<td>' . htmlspecialchars(implode(', ', $parts)) . '</td>';
                                                    break;
                                                case 'group':
                                                    echo '<td>' . htmlspecialchars($s['student_group'] ?? '') . '</td>';
                                                    break;
                                                case 'year':
                                                    echo '<td>' . htmlspecialchars($s['year'] ?? '') . '</td>';
                                                    break;
                                                default:
                                                    echo '<td></td>';
                                            }
                                            ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php $i++; endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning text-center">No students found.</div>
                <?php endif; ?>
                <?php
                // Print footer with company/developer info from config
                $devName = isset($developer_name) ? trim($developer_name) : '';
                $devPhone = isset($developer_phone) ? trim($developer_phone) : '';
                $compName = isset($company_name) ? trim($company_name) : '';
                $compWeb  = isset($company_website) ? trim($company_website) : '';
                ?>
                <div class="print-only text-center" style="margin-top:10px; font-size:12px; color:#555;">
                    <?php if ($compName !== ''): ?>
                        <span>Developed by <?= htmlspecialchars($compName) ?></span>
                    <?php else: ?>
                        <span>Developed by</span>
                    <?php endif; ?>
                    <?php if ($compWeb !== ''): ?>
                        <span> | <?= htmlspecialchars($compWeb) ?></span>
                    <?php endif; ?>
                    <?php if ($devName !== '' || $devPhone !== ''): ?>
                        <span> | <?= htmlspecialchars($devName) ?><?= ($devPhone !== '' ? ' (' . htmlspecialchars($devPhone) . ')' : '') ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<script>
// Load sections on class change (JSON endpoint)
document.addEventListener('DOMContentLoaded', function(){
    var classSel = document.getElementById('class_id');
    var sectionSel = document.getElementById('section_id');
    function loadSections(cid, preselect){
        if (!sectionSel) return;
        sectionSel.innerHTML = '<option value="">Loading...</option>';
        if (!cid){ sectionSel.innerHTML = '<option value="">All Sections</option>'; return; }
        fetch('../ajax/get_sections.php?class_id=' + encodeURIComponent(cid))
            .then(res => res.json())
            .then(data => {
                sectionSel.innerHTML = '<option value="">All Sections</option>';
                data.forEach(function(row){
                    var opt = document.createElement('option');
                    opt.value = row.id; opt.textContent = row.section_name;
                    if (preselect && String(preselect) === String(row.id)) opt.selected = true;
                    sectionSel.appendChild(opt);
                });
            }).catch(()=>{
                sectionSel.innerHTML = '<option value="">All Sections</option>';
            });
    }
    if (classSel){
        classSel.addEventListener('change', function(){ loadSections(this.value, null); });
        // prepopulate if posted
        var preC = '<?= isset($class_id) ? (int)$class_id : 0 ?>';
        var preS = '<?= isset($section_id) ? (int)$section_id : 0 ?>';
        if (preC){ loadSections(preC, preS); }
    }

    // Drag & Drop ordering for columns
    (function(){
        var list = document.getElementById('columnList'); if (!list) return;
        var draggingEl = null;
        function updateOrderInputs(){
            var items = list.querySelectorAll('.column-item'); var i=1;
            items.forEach(function(item){
                var key = item.getAttribute('data-key');
                var input = item.querySelector('input[type="hidden"][name^="order["]');
                if (input) input.value = i++;
            });
        }
        function getDragAfterElement(container, y){
            var els = [].slice.call(container.querySelectorAll('.column-item:not(.dragging)'));
            return els.reduce(function(closest, child){
                var box = child.getBoundingClientRect();
                var offset = y - box.top - box.height/2;
                if (offset < 0 && offset > closest.offset){ return {offset: offset, element: child}; }
                else { return closest; }
            }, {offset: Number.NEGATIVE_INFINITY}).element;
        }
        list.addEventListener('dragstart', function(e){
            var target = e.target.closest('.column-item'); if (!target) return;
            draggingEl = target; target.classList.add('dragging');
            try { e.dataTransfer.setData('text/plain', target.getAttribute('data-key')||''); } catch(err){}
        });
        list.addEventListener('dragend', function(){ if(draggingEl){ draggingEl.classList.remove('dragging'); draggingEl=null; updateOrderInputs(); } });
        list.addEventListener('dragover', function(e){
            e.preventDefault(); if (!draggingEl) return;
            var after = getDragAfterElement(list, e.clientY);
            if (after == null) list.appendChild(draggingEl); else list.insertBefore(draggingEl, after);
        });
        list.addEventListener('drop', function(e){ e.preventDefault(); updateOrderInputs(); });
        updateOrderInputs();
        var form = list.closest('form'); if (form){ form.addEventListener('submit', updateOrderInputs); }
    })();
});

// Print orientation helper
function printWithOrientation(orientation){
    try {
        var css = '@media print{ @page { size: ' + (orientation === 'landscape' ? 'landscape' : 'portrait') + '; } }';
        var style = document.createElement('style');
        style.setAttribute('id', 'print-orientation-style'); style.type = 'text/css';
        style.appendChild(document.createTextNode(css)); document.head.appendChild(style);
        var cleanup = function(){ var el = document.getElementById('print-orientation-style'); if (el && el.parentNode) el.parentNode.removeChild(el); window.removeEventListener('afterprint', cleanup); };
        window.addEventListener('afterprint', cleanup);
    } catch(e) {} finally { window.print(); }
}
</script>

<?php include '../includes/footer.php'; ?>
