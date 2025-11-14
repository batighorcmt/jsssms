<?php
require_once __DIR__ . '/../bootstrap.php';
require_method('POST');
api_require_auth(['teacher','super_admin']);

$body = read_json_body();
$date = $body['date'] ?? '';
$plan_id = (int)($body['plan_id'] ?? 0);
$room_id = (int)($body['room_id'] ?? 0);
$entries = $body['entries'] ?? [];
if (!validate_date($date) || $plan_id<=0 || $room_id<=0) api_response(false,'Invalid parameters',400);
if (!is_array($entries)) api_response(false,'entries must be array',400);

// Permission check (same as attendance_get)
if ($authUser['role']==='teacher') {
  $chk = $conn->prepare('SELECT 1 FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? AND teacher_user_id=? LIMIT 1');
  $chk->bind_param('siii',$date,$plan_id,$room_id,$authUser['id']);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows===0) api_response(false,'Forbidden',403);
  $chk->close();
}

$saved=0;$failed=0;
$stmt = $conn->prepare("INSERT INTO exam_room_attendance (duty_date, plan_id, room_id, student_id, status, marked_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), marked_by=VALUES(marked_by), marked_at=CURRENT_TIMESTAMP");
if (!$stmt) api_response(false,'Prepare failed',500);
foreach ($entries as $e){
  $sid = (int)($e['student_id'] ?? 0);
  $status = ($e['status'] ?? '')==='present' ? 'present' : (($e['status'] ?? '')==='absent' ? 'absent' : null);
  if ($sid<=0 || !$status){ $failed++; continue; }
  $stmt->bind_param('siiisi',$date,$plan_id,$room_id,$sid,$status,$authUser['id']);
  if ($stmt->execute()) $saved++; else $failed++;
}
api_response(true,['saved'=>$saved,'failed'=>$failed]);
?>