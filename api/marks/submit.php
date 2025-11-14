<?php
require_once __DIR__ . '/../bootstrap.php';
require_method('POST');
api_require_auth(['teacher','super_admin']);

$body = read_json_body();
$exam_id = (int)($body['exam_id'] ?? 0);
$subject_id = (int)($body['subject_id'] ?? 0);
$marks = $body['marks'] ?? [];
if ($exam_id<=0 || $subject_id<=0) api_response(false,'exam_id and subject_id required',400);
if (!is_array($marks)) api_response(false,'marks must be array',400);

// Teacher permission: must be assigned in exam_subjects
if ($authUser['role']==='teacher') {
  $teacher_id=0; $t=$conn->prepare('SELECT id FROM teachers WHERE contact=? LIMIT 1'); if($t){ $t->bind_param('s',$authUser['username']); $t->execute(); $t->bind_result($teacher_id); $t->fetch(); $t->close(); }
  $hasTeacherCol=false; $cchk=$conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'teacher_id'"); if ($cchk && $cchk->num_rows>0) $hasTeacherCol=true;
  if ($hasTeacherCol && $teacher_id>0){
    $chk=$conn->prepare('SELECT 1 FROM exam_subjects WHERE exam_id=? AND subject_id=? AND teacher_id=? LIMIT 1');
    $chk->bind_param('iii',$exam_id,$subject_id,$teacher_id); $chk->execute(); $chk->store_result();
    if ($chk->num_rows===0) api_response(false,'Not assigned to this subject',403);
    $chk->close();
  }
}

$ins = $conn->prepare('INSERT INTO marks (exam_id, student_id, subject_id, creative_marks, objective_marks) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE creative_marks=VALUES(creative_marks), objective_marks=VALUES(objective_marks)');
if (!$ins) api_response(false,'Prepare failed',500);
$saved=0;$failed=0;
foreach ($marks as $m){
  $sid=(int)($m['student_id'] ?? 0);
  $creative = isset($m['creative']) && is_numeric($m['creative']) ? (float)$m['creative'] : null;
  $objective = isset($m['objective']) && is_numeric($m['objective']) ? (float)$m['objective'] : null;
  if ($sid<=0) { $failed++; continue; }
  // Null -> treat as 0 or keep previous? Use 0 default.
  if ($creative===null) $creative=0.0; if ($objective===null) $objective=0.0;
  $ins->bind_param('iiidd',$exam_id,$sid,$subject_id,$creative,$objective);
  if ($ins->execute()) $saved++; else $failed++;
}
api_response(true,['saved'=>$saved,'failed'=>$failed]);
?>