<?php
// Open read-only endpoint: no auth required. If a valid auth token/session exists
// we will still compute teacher_assigned flags; otherwise those default to false.
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php'; // gives helpers (validate_date, api_response, etc.) but we intentionally skip api_require_auth

$date = $_GET['date'] ?? '';
if (!validate_date($date)) api_response(false, 'Invalid or missing date', 400);

// Find plans mapped to this exam date
$sqlPlans = "SELECT DISTINCT spe.plan_id, es.exam_id, es.exam_date
             FROM seat_plan_exams spe
             JOIN exam_subjects es ON es.exam_id = spe.exam_id
             WHERE es.exam_date = ?";
$plans = [];
if ($st = $conn->prepare($sqlPlans)) {
  $st->bind_param('s', $date);
  if (!$st->execute()) api_response(false, 'Plan query failed', 500);
  $res = $st->get_result();
  while ($r = $res->fetch_assoc()) { $plans[] = $r; }
  $st->close();
} else api_response(false, 'Prepare failed', 500);

if (empty($plans)) api_response(true, ['date'=>$date,'plans'=>[]]);

// For each plan collect rooms + allocation counts + whether current teacher has invigilation assignment
$outPlans = [];
foreach ($plans as $p) {
  $pid = (int)$p['plan_id'];
  $rooms = [];
  $rs = $conn->query("SELECT r.id, r.room_no, r.title, r.columns_count FROM seat_plan_rooms r WHERE r.plan_id=".$pid." ORDER BY r.room_no ASC");
  if ($rs) {
    while ($rm = $rs->fetch_assoc()) {
      $roomId = (int)$rm['id'];
      // allocation count
      $cnt = 0; $qCnt = $conn->query('SELECT COUNT(*) AS c FROM seat_plan_allocations WHERE plan_id='.$pid.' AND room_id='.$roomId); if ($qCnt && ($cr=$qCnt->fetch_assoc())) $cnt=(int)$cr['c'];
      // teacher assigned?
      $assigned = false;
      if (isset($authUser['role']) && $authUser['role']==='teacher' && isset($authUser['id'])) {
        if ($chk = $conn->prepare('SELECT 1 FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? AND teacher_user_id=? LIMIT 1')) {
          $chk->bind_param('siii', $date, $pid, $roomId, $authUser['id']);
          $chk->execute(); $chk->store_result(); $assigned = $chk->num_rows>0; $chk->close();
        }
      }
      $rooms[] = [
        'room_id'=>$roomId,
        'room_no'=>$rm['room_no'],
        'title'=>$rm['title'],
        'allocations'=>$cnt,
        'teacher_assigned'=>$assigned
      ];
    }
  }
  $outPlans[] = [
    'plan_id'=>$pid,
    'exam_id'=>(int)$p['exam_id'],
    'exam_date'=>$p['exam_date'],
    'rooms'=>$rooms
  ];
}

api_response(true, ['date'=>$date,'plans'=>$outPlans]);
?>