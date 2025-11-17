<?php
@include_once __DIR__ . '/../bootstrap.php';
api_require_auth(['super_admin']);
require_method('POST');
$body = read_json_body();

$id = (int)($body['id'] ?? 0);
if ($id <= 0) api_response(false, 'id is required', 400);

$allowed = ['student_id','roll_no','student_name','class_id','section_id','year','father_name','mother_name','gender','mobile_no','religion','nationality','date_of_birth','blood_group','village','post_office','upazilla','district','status','student_group','optional_subject_id'];
$cols=[]; $vals=[]; $types='';
foreach ($allowed as $k) {
  if (array_key_exists($k, $body)) {
    $cols[] = "$k=?";
    $vals[] = $body[$k];
    $types .= 's';
  }
}
if (empty($cols)) api_response(false, 'No fields to update', 400);
$vals[] = $id; $types .= 'i';
$sql = "UPDATE students SET ".implode(',', $cols)." WHERE id=?";
$stmt = $conn->prepare($sql); if (!$stmt) api_response(false, 'Prepare failed', 500);
$stmt->bind_param($types, ...$vals);
if (!$stmt->execute()) api_response(false, 'Update failed: '.$stmt->error, 500);
api_response(true, ['updated'=> $stmt->affected_rows]);
