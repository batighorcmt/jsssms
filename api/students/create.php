<?php
@include_once __DIR__ . '/../bootstrap.php';
api_require_auth(['super_admin']);
require_method('POST');
$body = read_json_body();

$required = ['student_id','student_name','class_id'];
foreach ($required as $k) { if (empty($body[$k])) api_response(false, "Missing $k", 400); }

$allowed = ['student_id','roll_no','student_name','class_id','section_id','year','father_name','mother_name','gender','mobile_no','religion','nationality','date_of_birth','blood_group','village','post_office','upazilla','district','status','student_group','photo','optional_subject_id'];
$cols=[]; $place=[]; $vals=[]; $types='';
foreach ($allowed as $k) {
  if (array_key_exists($k, $body)) {
    $cols[] = $k; $place[]='?'; $vals[]=$body[$k]; $types.='s';
  }
}
if (empty($cols)) api_response(false, 'No fields provided', 400);
$sql = "INSERT INTO students (".implode(',',$cols).") VALUES (".implode(',',$place).")";
$stmt = $conn->prepare($sql); if (!$stmt) api_response(false, 'Prepare failed', 500);
$stmt->bind_param($types, ...$vals);
if (!$stmt->execute()) api_response(false, 'Insert failed: '.$stmt->error, 500);
$id = $stmt->insert_id;
api_response(true, ['id'=>$id]);
