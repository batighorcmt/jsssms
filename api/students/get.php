<?php
@include_once __DIR__ . '/../bootstrap.php';
api_require_auth(['super_admin']);

$id = (int)($_GET['id'] ?? 0);
$studentId = trim($_GET['student_id'] ?? '');
if ($id <= 0 && $studentId === '') api_response(false, 'id or student_id required', 400);

$where = $id > 0 ? 'id='.(int)$id : ("student_id='".$conn->real_escape_string($studentId)."'");
$sql = "SELECT * FROM students WHERE $where LIMIT 1";
$res = $conn->query($sql);
if (!$res || !$res->num_rows) api_response(false, 'Student not found', 404);
$s = $res->fetch_assoc();
$scriptBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$photo = trim((string)($s['photo'] ?? ''));
$origin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
$s['photo_url'] = $photo ? ($origin . '/uploads/students/' . $photo) : '';
api_response(true, ['student'=>$s]);
