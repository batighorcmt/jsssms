<?php
@include_once __DIR__ . '/../config/config.php';
include '../auth/session.php';
$ALLOWED_ROLES = ['super_admin'];
include '../config/db.php';

// ============ CSV/XLSX Download Template ============
if (isset($_GET['download_template']) && $_GET['download_template'] === '1') {
    $headers = [
        'student_id','roll_no','student_name','class','section','year','father_name','mother_name','gender','mobile_no','religion','nationality','date_of_birth','blood_group','village','post_office','upazilla','district','status','group','photo','optional_subject_id'
    ];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=students_template.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    // example row
    fputcsv($out, ['2507001','1','John Doe','Seven','A','2025','Father Name','Mother Name','Male','017xxxxxxxx','Islam','Bangladeshi','01/01/2013','A+','Village','PO','Upazila','District','Active','None','','0']);
    fclose($out);
    exit;
}

// ================= File Upload Handler (CSV or XLSX) =================
$importSummaryHtml = '';
$importNotesHtml = '';
if (isset($_POST['action']) && $_POST['action'] === 'upload_csv' && isset($_FILES['csv_file'])) {
    // Enable robust logging in production to catch 500s
    $logFile = __DIR__ . '/../logs/import_errors.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
    if (!is_writable($logDir)) {
        $tmpFallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'jsssms_import_errors.log';
        $logFile = $tmpFallback;
    }
    if (!file_exists($logFile)) { @touch($logFile); }
    $__import_log = function($msg) use ($logFile){
        $ts = date('Y-m-d H:i:s');
        @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
    };
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('error_log', $logFile);
    $uploadErrors = [];
    $inserted = 0; $updated = 0; $skipped = 0;
    $hasHeader = isset($_POST['has_header']) ? true : false;

    // Minimal XLSX reader (first sheet) with proper column alignment
    $parseXlsx = function($filePath) use (&$uploadErrors) {
        $rows = [];
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) { $uploadErrors[] = 'Unable to open .xlsx file.'; return $rows; }
        // read shared strings (text pool)
        $shared = [];
        if (($entry = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            libxml_use_internal_errors(true);
            $xml = @simplexml_load_string($entry);
            if ($xml && isset($xml->si)) {
                foreach ($xml->si as $si) {
                    $t = '';
                    if (isset($si->t)) {
                        $t = (string)$si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) { $t .= (string)$r->t; }
                    }
                    $shared[] = $t;
                }
            }
        }
        // get first sheet
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) { $uploadErrors[] = 'First sheet not found in .xlsx'; $zip->close(); return $rows; }
    libxml_use_internal_errors(true);
    $sx = @simplexml_load_string($sheetXml);
        if (!$sx) { $uploadErrors[] = 'Invalid sheet XML'; $zip->close(); return $rows; }

        foreach ($sx->sheetData->row as $row) {
            $r = [];
            foreach ($row->c as $c) {
                $t = (string)$c['t'];
                $v = (string)$c->v;
                // determine column index by cell reference (e.g., A1, C3)
                $ref = isset($c['r']) ? (string)$c['r'] : '';
                $idx = null;
                if ($ref !== '') {
                    $refU = strtoupper($ref);
                    if (preg_match('~^([A-Z]+)\d+$~', $refU, $m)) {
                        $letters = $m[1];
                        $n = 0;
                        $len = strlen($letters);
                        for ($i = 0; $i < $len; $i++) {
                            $n = $n * 26 + (ord($letters[$i]) - 64); // A=1 ... Z=26
                        }
                        $idx = $n - 1; // 0-based
                    }
                }
                if ($idx === null) { // fallback: append
                    $idx = empty($r) ? 0 : (max(array_keys($r)) + 1);
                }
                // decode value
                if ($t === 's') { // shared string table
                    $sidx = is_numeric($v) ? (int)$v : -1;
                    $value = ($sidx >= 0 && isset($shared[$sidx])) ? $shared[$sidx] : '';
                } elseif ($t === 'inlineStr' && isset($c->is->t)) {
                    $value = (string)$c->is->t;
                } elseif ($t === 'b') { // boolean
                    $value = ($v === '1') ? 'TRUE' : 'FALSE';
                } else {
                    // numbers, dates (serials) and general types come as raw value; keep as-is
                    $value = $v;
                }
                $r[(int)$idx] = $value;
            }
            // ensure sparse array doesn't break index-based access
            if (!empty($r)) { ksort($r); }
            $rows[] = $r;
        }
        $zip->close();
        return $rows;
    };

    // Map PHP upload errors early
    $uploadErrCode = isset($_FILES['csv_file']['error']) ? (int)$_FILES['csv_file']['error'] : UPLOAD_ERR_OK;
    if ($uploadErrCode !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server limit (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the form limit (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on server.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
        ];
        $msg = isset($errMap[$uploadErrCode]) ? $errMap[$uploadErrCode] : ('Upload error code: ' . $uploadErrCode);
        $uploadErrors[] = $msg;
        $__import_log('Upload failed: ' . $msg);
    }

    if ($uploadErrCode !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES["csv_file"]["tmp_name"])) {
        if ($uploadErrCode === UPLOAD_ERR_OK) { $uploadErrors[] = 'No file received.'; $__import_log('No file received (is_uploaded_file failed).'); }
    } else {
        $tmp = $_FILES['csv_file']['tmp_name'];
        $name = $_FILES['csv_file']['name'];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = isset($_FILES['csv_file']['size']) ? (int)$_FILES['csv_file']['size'] : 0;

        $allRows = [];
        if ($ext === 'xlsx') {
            // Guard against very large XLSX on low-memory hosts
            $maxXlsxSize = 8 * 1024 * 1024; // 8 MB
            if ($size > $maxXlsxSize) {
                $uploadErrors[] = 'XLSX file is too large for this server. Please upload CSV instead.';
                $__import_log('XLSX rejected due to size: ' . $size . ' bytes.');
            } elseif (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
                $uploadErrors[] = 'XLSX support requires ZipArchive and SimpleXML PHP extensions. You can upload CSV instead.';
                $__import_log('Missing ZipArchive or SimpleXML on server.');
            } else {
                try {
                    $allRows = $parseXlsx($tmp);
                } catch (Exception $e) {
                    $uploadErrors[] = 'XLSX parse failed: ' . $e->getMessage();
                    $__import_log('XLSX parse exception: ' . $e->getMessage());
                }
            }
        } else { // default CSV
            $fh = fopen($tmp, 'r');
            if (!$fh) { $uploadErrors[] = 'Unable to open uploaded file.'; }
            else {
                while (($row = fgetcsv($fh)) !== false) { $allRows[] = $row; }
                fclose($fh);
            }
        }

        if (!empty($allRows)) {
            // Header handling
            $header = [];
            $startIndex = 0;
            if ($hasHeader) {
                $row = $allRows[0];
                if (isset($row[0])) { $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]); }
                foreach ($row as $i => $h) { $header[$i] = strtolower(trim((string)$h)); }
                $startIndex = 1;
            }

            $getCol = function(array $row, array $keys) use ($header) {
                foreach ($keys as $k) {
                    if (is_numeric($k) && isset($row[(int)$k])) return trim((string)$row[(int)$k]);
                    if (!empty($header)) {
                        foreach ($header as $idx => $name) {
                            if ($name === strtolower($k)) { return isset($row[$idx]) ? trim((string)$row[$idx]) : ''; }
                        }
                    }
                }
                return '';
            };

            // Prepare insert statement (be compatible if students.optional_subject_id is missing on live DB)
            $hasOptionalCol = false;
            if ($cc = $conn->query("SHOW COLUMNS FROM students LIKE 'optional_subject_id'")) {
                $hasOptionalCol = ($cc->num_rows > 0);
            }
            if (!$hasOptionalCol) {
                // Best-effort add column (may fail without privilege; fallback will still work without it)
                @mysqli_query($conn, "ALTER TABLE students ADD COLUMN optional_subject_id INT NULL");
                if ($cc2 = $conn->query("SHOW COLUMNS FROM students LIKE 'optional_subject_id'")) {
                    $hasOptionalCol = ($cc2->num_rows > 0);
                }
            }

            $withOptional = $hasOptionalCol;
            if ($withOptional) {
                $sql = "INSERT INTO students
                    (student_id, roll_no, student_name, father_name, mother_name, gender, mobile_no, religion, nationality,
                     date_of_birth, blood_group, village, post_office, upazilla, district, status, class_id, section_id,
                     student_group, year, photo, optional_subject_id)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      roll_no=VALUES(roll_no), student_name=VALUES(student_name), father_name=VALUES(father_name),
                      mother_name=VALUES(mother_name), gender=VALUES(gender), mobile_no=VALUES(mobile_no),
                      religion=VALUES(religion), nationality=VALUES(nationality), date_of_birth=VALUES(date_of_birth),
                      blood_group=VALUES(blood_group), village=VALUES(village), post_office=VALUES(post_office),
                      upazilla=VALUES(upazilla), district=VALUES(district), status=VALUES(status), class_id=VALUES(class_id),
                      section_id=VALUES(section_id), student_group=VALUES(student_group), year=VALUES(year), photo=VALUES(photo),
                      optional_subject_id=VALUES(optional_subject_id)";
            } else {
                $sql = "INSERT INTO students
                    (student_id, roll_no, student_name, father_name, mother_name, gender, mobile_no, religion, nationality,
                     date_of_birth, blood_group, village, post_office, upazilla, district, status, class_id, section_id,
                     student_group, year, photo)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      roll_no=VALUES(roll_no), student_name=VALUES(student_name), father_name=VALUES(father_name),
                      mother_name=VALUES(mother_name), gender=VALUES(gender), mobile_no=VALUES(mobile_no),
                      religion=VALUES(religion), nationality=VALUES(nationality), date_of_birth=VALUES(date_of_birth),
                      blood_group=VALUES(blood_group), village=VALUES(village), post_office=VALUES(post_office),
                      upazilla=VALUES(upazilla), district=VALUES(district), status=VALUES(status), class_id=VALUES(class_id),
                      section_id=VALUES(section_id), student_group=VALUES(student_group), year=VALUES(year), photo=VALUES(photo)";
            }
            $stmt = $conn->prepare($sql);
            if (!$stmt) { $uploadErrors[] = 'Prepare failed: ' . $conn->error; }
            else {
                $hasSectionsUpload = false; if ($chk = $conn->query("SHOW TABLES LIKE 'sections'")) { $hasSectionsUpload = ($chk->num_rows > 0); }
                $lineNo = $hasHeader ? 2 : 1;
                for ($i=$startIndex; $i<count($allRows); $i++) {
                    $row = $allRows[$i];
                    // Skip fully empty rows (all cells blank)
                    $allEmpty = true; foreach ($row as $cell) { if (trim((string)$cell) !== '') { $allEmpty = false; break; } }
                    if ($allEmpty) { $lineNo++; continue; }
                    // Map required
                    $student_name = $getCol($row, ['student_name','name','student','শিক্ষার্থী','শিক্ষার্থীর নাম']);
                    $class_name   = $getCol($row, ['class','class_name','শ্রেণি']);
                    $section_name = $getCol($row, ['section','section_name','শাখা']);
                    $year         = $getCol($row, ['year','session','শিক্ষাবর্ষ']);
                    if ($student_name === '' || $class_name === '' || ($hasSectionsUpload && $section_name === '') || $year === '') {
                        $skipped++; $uploadErrors[] = "Row $lineNo: missing required fields (name/class/section/year)."; $lineNo++; continue;
                    }
                    // Resolve class
                    $cid = 0; if ($pr = $conn->prepare("SELECT id FROM classes WHERE LOWER(class_name)=LOWER(?) LIMIT 1")) { $pr->bind_param('s', $class_name); $pr->execute(); $pr->bind_result($cid); $pr->fetch(); $pr->close(); }
                    if (!$cid) { $skipped++; $uploadErrors[] = "Row $lineNo: class '".htmlspecialchars($class_name)."' not found."; $lineNo++; continue; }
                    // Resolve section
                    $section_id = 0; if ($hasSectionsUpload) { if ($pr = $conn->prepare("SELECT id FROM sections WHERE LOWER(section_name)=LOWER(?) AND class_id=? LIMIT 1")) { $pr->bind_param('si', $section_name, $cid); $pr->execute(); $pr->bind_result($section_id); $pr->fetch(); $pr->close(); } if (!$section_id) { $skipped++; $uploadErrors[] = "Row $lineNo: section '".htmlspecialchars($section_name)."' (class: ".htmlspecialchars($class_name).") not found."; $lineNo++; continue; } }

                    // Optional
                    $student_id   = $getCol($row, ['student_id','sid','আইডি']);
                    $roll_no      = $getCol($row, ['roll','roll_no','রোল']);
                    $father_name  = $getCol($row, ['father_name','পিতার নাম']);
                    $mother_name  = $getCol($row, ['mother_name','মাতার নাম']);
                    $gender       = $getCol($row, ['gender','লিঙ্গ']);
                    $mobile_no    = $getCol($row, ['mobile','mobile_no','phone','মোবাইল']);
                    $religion     = $getCol($row, ['religion','ধর্ম']);
                    $nationality  = $getCol($row, ['nationality','জাতীয়তা']);
                    $dob          = $getCol($row, ['date_of_birth','dob','জন্ম তারিখ']);
                    // Normalize DOB for MySQL 8 strict: dd/mm/yyyy or yyyy-mm-dd; otherwise NULL
                    $dob = trim($dob);
                    if ($dob !== '') {
                        if (preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', $dob, $m)) {
                            $dob = $m[3].'-'.$m[2].'-'.$m[1];
                        } elseif (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $dob)) {
                            $dob = null;
                        }
                    } else { $dob = null; }
                    $blood_group  = $getCol($row, ['blood_group','রক্তের_গ্রুপ']);
                    $village      = $getCol($row, ['village','গ্রাম']);
                    $post_office  = $getCol($row, ['post_office','পোস্ট অফিস']);
                    $upazilla     = $getCol($row, ['upazilla','উপজেলা']);
                    $district     = $getCol($row, ['district','জেলা']);
                    $status       = $getCol($row, ['status','অবস্থা']); if ($status==='') $status='Active';
                    $student_group= $getCol($row, ['group','student_group','গ্রুপ']);
                    $student_group = trim((string)$student_group);
                    if ($student_group === '' || $student_group === '0' || strcasecmp($student_group,'none') === 0) { $student_group = 'None'; }
                    $photo        = $getCol($row, ['photo','ছবি']);
                    $optional_sub = $getCol($row, ['optional_subject_id','optional_subject','ঐচ্ছিক_বিষয়_আইডি']); $optional_sub = is_numeric($optional_sub) ? (int)$optional_sub : 0;

                    if ($withOptional) {
                        // 22 params (with optional_subject_id)
                        $stmt->bind_param('iissssssssssssssiiissi', $student_id, $roll_no, $student_name, $father_name, $mother_name, $gender, $mobile_no, $religion, $nationality, $dob, $blood_group, $village, $post_office, $upazilla, $district, $status, $cid, $section_id, $student_group, $year, $photo, $optional_sub);
                    } else {
                        // 21 params (without optional_subject_id)
                        $stmt->bind_param('iissssssssssssssiiiss', $student_id, $roll_no, $student_name, $father_name, $mother_name, $gender, $mobile_no, $religion, $nationality, $dob, $blood_group, $village, $post_office, $upazilla, $district, $status, $cid, $section_id, $student_group, $year, $photo);
                    }
                    if ($stmt->execute()) { if ($stmt->affected_rows === 1) $inserted++; else $updated++; }
                    else { $skipped++; $uploadErrors[] = "Row $lineNo: " . $stmt->error; }
                    $lineNo++;
                }
                $stmt->close();
            }
        } else {
            $uploadErrors[] = 'The uploaded file appears to be empty.';
        }
    }

    // Build alert HTML to show near the form
    $importSummaryHtml = '<div class="alert alert-info sticky-alert"><strong>Import Summary:</strong> Inserted: ' . $inserted . ', Updated: ' . $updated . ', Skipped: ' . $skipped . '.</div>';
    if (!empty($uploadErrors)) {
        $importNotesHtml = '<div class="alert alert-warning sticky-alert" style="max-height:220px; overflow:auto;"><strong>Notes:</strong><ul style="margin-bottom:0;">';
        foreach ($uploadErrors as $e) { $importNotesHtml .= '<li>' . htmlspecialchars($e) . '</li>'; }
        $importNotesHtml .= '</ul></div>';
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php';

// --- Pagination & Filters ---
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Student Management</h1>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
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
    .sticky-alert{position:sticky;top:8px;z-index:1050}
    @media (max-width:576px){ .card .card-header h3{font-size:1.05rem} }
</style>

<div class="row">
    <div class="col-12">
            <div class="card mb-3" id="importCard">
                <div class="card-header">
                    <h3 class="card-title mb-0">Import Students (CSV / Excel)</h3>
                    <div class="card-tools">
                        <a href="?download_template=1" class="btn btn-xs btn-outline-secondary"><i class="fas fa-download mr-1"></i>Sample CSV</a>
                        <button class="btn btn-xs btn-outline-primary" type="button" data-toggle="collapse" data-target="#importCollapse" aria-expanded="false" aria-controls="importCollapse">Toggle</button>
                        <button class="btn btn-xs btn-outline-secondary" type="button" data-toggle="collapse" data-target="#diagCollapse" aria-expanded="false" aria-controls="diagCollapse">Diagnostics</button>
                    </div>
                </div>
                <div class="card-body collapse" id="importCollapse">
                    <?php if ($importSummaryHtml) { echo $importSummaryHtml; } ?>
                    <?php if ($importNotesHtml) { echo $importNotesHtml; } ?>
                    <form method="post" enctype="multipart/form-data" class="form-inline">
                        <input type="hidden" name="action" value="upload_csv">
                        <div class="form-group mr-2 mb-2">
                            <label class="mr-2">File</label>
                            <input type="file" name="csv_file" accept=".csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="form-control-file" required>
                        </div>
                        <div class="form-group mr-2 mb-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="has_header" id="has_header" checked>
                                <label class="form-check-label" for="has_header">First row is header</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary mb-2">Upload File</button>
                    </form>
                    <div class="small text-muted mt-2">
                        Required columns: <strong>student_name, class, section, year</strong> (section required only if Sections table exists).<br>
                        Optional columns include: student_id, roll_no, father_name, mother_name, gender, mobile_no, religion, nationality, date_of_birth (dd/mm/yyyy or yyyy-mm-dd), blood_group, village, post_office, upazilla, district, status, group, photo, optional_subject_id.<br>
                        Tip: You can open the sample CSV in Excel, fill it in, and upload either the same CSV or save as <strong>.xlsx</strong> and upload.
                    </div>
                </div>
                <div class="card-body collapse" id="diagCollapse">
                    <?php
                        $diag_zip = class_exists('ZipArchive') ? 'Yes' : 'No';
                        $diag_xml = function_exists('simplexml_load_string') ? 'Yes' : 'No';
                        $diag_php = PHP_VERSION;
                        $diag_ums = ini_get('upload_max_filesize');
                        $diag_pms = ini_get('post_max_size');
                        $diag_mem = ini_get('memory_limit');
                        $diag_tmp = ini_get('upload_tmp_dir');
                        if (!$diag_tmp) { $diag_tmp = sys_get_temp_dir(); }
                        $logsDir = realpath(__DIR__ . '/../logs');
                        if ($logsDir === false) { $logsDir = __DIR__ . '/../logs'; }
                        $logsWritable = (is_dir($logsDir) && is_writable($logsDir)) ? 'Yes' : 'No';
                        $tmpWritable = is_writable($diag_tmp) ? 'Yes' : 'No';
                    ?>
                    <div class="small">
                        <strong>Server diagnostics</strong>
                        <ul class="mb-1">
                            <li>PHP version: <code><?= htmlspecialchars($diag_php) ?></code></li>
                            <li>ZipArchive: <strong><?= $diag_zip ?></strong>, SimpleXML: <strong><?= $diag_xml ?></strong></li>
                            <li>upload_max_filesize: <code><?= htmlspecialchars($diag_ums) ?></code>, post_max_size: <code><?= htmlspecialchars($diag_pms) ?></code>, memory_limit: <code><?= htmlspecialchars($diag_mem) ?></code></li>
                            <li>upload_tmp_dir: <code><?= htmlspecialchars($diag_tmp) ?></code> (writable: <?= $tmpWritable ?>)</li>
                            <li>logs dir: <code><?= htmlspecialchars($logsDir) ?></code> (writable: <?= $logsWritable ?>)</li>
                        </ul>
                        <div class="text-muted">If ZipArchive or SimpleXML is "No", upload CSV instead of .xlsx or enable those PHP extensions. If limits are small, increase them or use CSV.</div>
                    </div>
                </div>
            </div>
            <div class="card mb-3">
                <div class="card-header">
                    <h3 class="card-title mb-0">Student List – Filters</h3>
                    <div class="card-tools">
                        <button class="btn btn-xs btn-outline-primary mr-2" type="button" data-toggle="collapse" data-target="#filtersCollapse" aria-expanded="false" aria-controls="filtersCollapse">Toggle</button>
                        <a href="add_student.php" class="btn btn-sm btn-success"><i class="fas fa-user-plus mr-1"></i> Add Student</a>
                    </div>
                </div>
            <div class="card-body collapse" id="filtersCollapse">
                    <form method="get" id="filterForm">
                    <div class="form-row">
                        <div class="col-6 col-md-2 mb-2">
                            <label class="small mb-1">Per page</label>
                            <select name="per_page" class="form-control form-control-sm">
                                <?php foreach ([10,25,50,100] as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $per_page===$opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <label class="small mb-1">Class</label>
                            <select name="class_id" class="form-control form-control-sm">
                                <option value="0">All Classes</option>
                                <?php if($classesRes) while($c = $classesRes->fetch_assoc()): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo $filter_class===(int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['class_name']); ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 mb-2">
                            <label class="small mb-1">Group</label>
                            <select name="group" id="group" class="form-control form-control-sm">
                                <option value="">All Groups</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3 mb-2">
                            <label class="small mb-1">Subject</label>
                            <select name="subject_id" id="subject_id" class="form-control form-control-sm">
                                <option value="0">All Subjects</option>
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
                                <th>SL</th>
                                <th>ID</th>
                                <th>Name</th>
                                <th class="d-none d-sm-table-cell">Father Name</th>
                                <th class="d-none d-md-table-cell">Mother Name</th>
                                <th>Class</th>
                                <th class="d-none d-sm-table-cell">Section</th>
                                <th>Roll</th>
                                <th class="d-none d-md-table-cell">Group</th>
                                <th>Subject Codes</th>
                                <th class="d-none d-md-table-cell">Photo</th>
                                <th>Actions</th>
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
                groupSel.innerHTML = '<option value="">Loading...</option>';
                subjectSel.innerHTML = '<option value="0">All Subjects</option>';
                if(!cid){ groupSel.innerHTML = '<option value="">All Groups</option>'; return; }
                fetch('../ajax/get_groups.php?class_id=' + encodeURIComponent(cid))
                  .then(r=>r.json())
                  .then(list => {
                      var opts = '<option value="">All Groups</option>';
                      list.forEach(function(g){ opts += '<option value="'+g+'">'+g+'</option>'; });
                      groupSel.innerHTML = opts;
                      // restore selection
                      var url = new URL(window.location.href);
                      var gv = url.searchParams.get('group') || '';
                      if(gv && groupSel.querySelector('option[value="'+gv+'"]')) groupSel.value = gv;
                      loadSubjects();
                  })
                  .catch(()=>{ groupSel.innerHTML = '<option value="">All Groups</option>'; });
            }

            function loadSubjects(){
                var cid = classSel.value;
                if(!cid){ subjectSel.innerHTML = '<option value="0">All Subjects</option>'; return; }
                subjectSel.innerHTML = '<option value="0">Loading...</option>';
                fetch('../ajax/get_subjects_by_class.php?class_id=' + encodeURIComponent(cid))
                  .then(r=>r.json())
                  .then(list => {
                      var opts = '<option value="0">All Subjects</option>';
                      list.forEach(function(s){ opts += '<option value="'+s.id+'">'+s.name+'</option>'; });
                      subjectSel.innerHTML = opts;
                      var url = new URL(window.location.href);
                      var sv = url.searchParams.get('subject_id') || '0';
                      if(sv && subjectSel.querySelector('option[value="'+sv+'"]')) subjectSel.value = sv;
                  })
                  .catch(()=>{ subjectSel.innerHTML = '<option value="0">All Subjects</option>'; });
            }

            classSel.addEventListener('change', loadGroups);
            // initial load if page reloaded with class
            if(classSel.value){ loadGroups(); }
        })();

        // If import messages exist, scroll the import card into view
        (function(){
            var card = document.getElementById('importCard');
            if(!card) return;
            if(card.querySelector('.sticky-alert')){
                var coll = document.getElementById('importCollapse');
                if (coll && !coll.classList.contains('show')) { coll.classList.add('show'); }
                card.scrollIntoView({behavior:'smooth', block:'start'});
            }
        })();
    </script>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
                                

