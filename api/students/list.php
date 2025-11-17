<?php
@include_once __DIR__ . '/../bootstrap.php';
api_require_auth(['super_admin']);

$perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$classId = (int)($_GET['class_id'] ?? 0);
$sectionId = (int)($_GET['section_id'] ?? 0);
$group = trim(strtolower($_GET['group'] ?? ''));
$q = trim($_GET['q'] ?? '');

$where = 'WHERE 1';
if ($classId > 0) $where .= ' AND s.class_id=' . $classId;
if ($sectionId > 0) $where .= ' AND s.section_id=' . $sectionId;
if ($group !== '') {
  $esc = $conn->real_escape_string($group);
  $where .= " AND LOWER(COALESCE(s.student_group,''))='".$esc."'";
}
if ($q !== '') {
  $esc = $conn->real_escape_string($q);
  $where .= " AND (s.student_name LIKE '%$esc%' OR s.student_id LIKE '%$esc%' OR s.father_name LIKE '%$esc%')";
}

$countSql = "SELECT COUNT(*) AS c FROM students s $where";
$total = 0; if ($r = $conn->query($countSql)) { $row=$r->fetch_assoc(); $total=(int)($row['c']??0);} 

$sql = "SELECT s.*, c.class_name, sec.section_name
        FROM students s
        LEFT JOIN classes c ON s.class_id=c.id
        LEFT JOIN sections sec ON s.section_id=sec.id
        $where
        ORDER BY s.class_id ASC, s.section_id ASC, s.roll_no ASC, s.student_name ASC
        LIMIT $offset,$perPage";

$scriptBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$photoBase = '/uploads/students/';
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$list = [];
if ($res = $conn->query($sql)) {
  while ($s = $res->fetch_assoc()) {
    $photo = trim((string)($s['photo'] ?? ''));
    $photo_url = $photo ? ($origin . $photoBase . $photo) : '';
    $list[] = [
      'id' => (int)$s['id'],
      'student_id' => $s['student_id'],
      'student_name' => $s['student_name'],
      'class_id' => (int)($s['class_id'] ?? 0),
      'class_name' => $s['class_name'] ?? '',
      'section_id' => (int)($s['section_id'] ?? 0),
      'section_name' => $s['section_name'] ?? '',
      'roll_no' => $s['roll_no'] ?? '',
      'gender' => $s['gender'] ?? '',
      'student_group' => $s['student_group'] ?? '',
      'photo' => $photo,
      'photo_url' => $photo_url,
    ];
  }
}
api_response(true, ['total'=>$total,'page'=>$page,'per_page'=>$perPage,'items'=>$list]);
