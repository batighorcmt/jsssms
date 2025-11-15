<?php
require_once __DIR__ . '/../bootstrap.php';
api_require_auth(['teacher','super_admin']);

$date = $_GET['date'] ?? '';
$plan_id = (int)($_GET['plan_id'] ?? 0);
$room_id = (int)($_GET['room_id'] ?? 0);
if (!validate_date($date) || $plan_id<=0 || $room_id<=0) api_response(false,'Missing parameters',400);

// Helper: is current user an active exam controller?
function api_is_controller(mysqli $conn, $userId){
  $userId=(int)$userId; if ($userId<=0) return false;
  $q = $conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1');
  return ($q && $q->num_rows>0);
}

// Permission: teacher must be assigned to room on date, unless controller
if ($authUser['role']==='teacher' && !api_is_controller($conn, $authUser['id'])) {
  $chk = $conn->prepare('SELECT 1 FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? AND teacher_user_id=? LIMIT 1');
  $chk->bind_param('siii',$date,$plan_id,$room_id,$authUser['id']);
  $chk->execute(); $chk->store_result();
  if ($chk->num_rows===0) api_response(false,'Forbidden',403);
  $chk->close();
}

// Load seat allocations
$sql = 'SELECT a.student_id, a.col_no, a.bench_no, a.position FROM seat_plan_allocations a WHERE a.plan_id='.$plan_id.' AND a.room_id='.$room_id.' ORDER BY a.col_no ASC, a.bench_no ASC, a.position ASC';
$alloc = [];
$rs = $conn->query($sql);
if ($rs) while($r=$rs->fetch_assoc()) { $alloc[]=$r; }

// Attendance statuses
$attMap = [];
$ars = $conn->query('SELECT student_id, status FROM exam_room_attendance WHERE duty_date="'.$conn->real_escape_string($date).'" AND plan_id='.$plan_id.' AND room_id='.$room_id);
if ($ars) while($r=$ars->fetch_assoc()) { $attMap[(int)$r['student_id']]=$r['status']; }

// Student info
$studentsOut = [];
foreach ($alloc as $a) {
  $sid = (int)$a['student_id'];
  $info = ['student_id'=>$sid,'student_name'=>'','roll_no'=>'','class_name'=>'','gender'=>''];
  $q = $conn->prepare('SELECT s.student_name, s.roll_no, s.gender, c.class_name FROM students s LEFT JOIN classes c ON c.id=s.class_id WHERE s.student_id=? LIMIT 1');
  if ($q){ $q->bind_param('i',$sid); $q->execute(); $res=$q->get_result(); if($res && $res->num_rows){ $info=array_merge($info,$res->fetch_assoc()); } $q->close(); }
  $studentsOut[] = [
    'student_id'=>$sid,
    'student_name'=>$info['student_name'],
    'roll_no'=>$info['roll_no'],
    'class_name'=>$info['class_name'],
    'gender'=>$info['gender'] ?? '',
    'status'=>$attMap[$sid] ?? 'unknown',
    'seat'=>[
      'col_no'=>(int)$a['col_no'],
      'bench_no'=>(int)$a['bench_no'],
      'position'=>$a['position']
    ]
  ];
}

api_response(true,[
  'date'=>$date,
  'plan_id'=>$plan_id,
  'room_id'=>$room_id,
  'students'=>$studentsOut
]);
?>