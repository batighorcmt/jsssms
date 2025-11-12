<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit(); }
header('Content-Type: application/json');
include '../config/db.php';

$plan_id = (int)($_GET['plan_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
$q = trim($_GET['q'] ?? '');

$cond = [];
if ($class_id>0) $cond[] = 'class_id='.(int)$class_id;
if ($q !== ''){
    $qq = $conn->real_escape_string($q);
    // If query is all digits, match roll_no or student_id starting with the digits (prefix match)
    if (preg_match('/^\d+$/', $q)) {
        $cond[] = "(CAST(roll_no AS CHAR) LIKE '{$qq}%' OR CAST(student_id AS CHAR) LIKE '{$qq}%')";
    } else {
        // Otherwise, phrase match on name only
        $cond[] = "(student_name LIKE '%$qq%')";
    }
}
$where = empty($cond) ? '1=1' : implode(' AND ', $cond);

// Exclude already assigned in this plan
$exclude = [];
if ($plan_id>0) {
    $r = $conn->query('SELECT student_id FROM seat_plan_allocations WHERE plan_id='.$plan_id);
    if ($r){ while($x=$r->fetch_assoc()){ $exclude[] = (int)$x['student_id']; } }
}
$exSql = '';
if (!empty($exclude)){
    $exSql = ' AND (id NOT IN ('.implode(',',$exclude).') AND student_id NOT IN ('.implode(',',$exclude).'))';
}

$sql = "SELECT id, student_id, student_name, roll_no, class_id, section_id, photo FROM students WHERE $where $exSql ORDER BY roll_no LIMIT 50";
$out = [];
$rs = @$conn->query($sql);
if ($rs){ while($row=$rs->fetch_assoc()){ $out[]=$row; } }

echo json_encode($out);
