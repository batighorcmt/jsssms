<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
header('Content-Type: application/json');
@include_once __DIR__ . '/../config/config.php';
include '../config/db.php';

// Ensure tables exist (idempotent)
$conn->query("CREATE TABLE IF NOT EXISTS exam_room_attendance (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  plan_id INT NOT NULL,
  room_id INT NOT NULL,
  student_id INT NOT NULL,
  status ENUM('present','absent') NOT NULL,
  marked_by INT NOT NULL,
  marked_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_att (duty_date, plan_id, room_id, student_id),
  INDEX idx_room_date (duty_date, plan_id, room_id)
)");

function is_controller(mysqli $conn, $userId){
  $userId = (int)$userId; if ($userId<=0) return false;
  $q = $conn->query('SELECT 1 FROM exam_controllers WHERE active=1 AND user_id='.$userId.' LIMIT 1');
  return ($q && $q->num_rows>0);
}
function can_mark(mysqli $conn, $userId, $role, $date, $plan_id, $room_id){
  if ($role==='super_admin' || is_controller($conn, $userId)) return true;
  // teachers must be assigned to the room for the date
  $st = $conn->prepare('SELECT 1 FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? AND teacher_user_id=? LIMIT 1');
  $st->bind_param('siii', $date, $plan_id, $room_id, $userId);
  $st->execute(); $st->store_result();
  return ($st->num_rows>0);
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$date = $_POST['date'] ?? $_GET['date'] ?? '';
$plan_id = (int)($_POST['plan_id'] ?? $_GET['plan_id'] ?? 0);
$room_id = (int)($_POST['room_id'] ?? $_GET['room_id'] ?? 0);
$uid = (int)($_SESSION['id'] ?? 0);
$role = $_SESSION['role'] ?? '';

if (!preg_match('~^\d{4}-\d{2}-\d{2}$~',$date) || $plan_id<=0 || $room_id<=0){ echo json_encode(['success'=>false,'message'=>'invalid parameters']); exit(); }
if (!can_mark($conn, $uid, $role, $date, $plan_id, $room_id)) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'forbidden']); exit(); }

if ($action==='mark'){
  $student_id = (int)($_POST['student_id'] ?? 0);
  $status = ($_POST['status'] ?? '')==='present' ? 'present' : 'absent';
  if ($student_id<=0) { echo json_encode(['success'=>false,'message'=>'missing student']); exit(); }
  $stmt = $conn->prepare("INSERT INTO exam_room_attendance (duty_date, plan_id, room_id, student_id, status, marked_by) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), marked_by=VALUES(marked_by), marked_at=CURRENT_TIMESTAMP");
  $stmt->bind_param('siiisi', $date, $plan_id, $room_id, $student_id, $status, $uid);
  if (@$stmt->execute()){ echo json_encode(['success'=>true]); } else { echo json_encode(['success'=>false,'message'=>'save failed']); }
  exit();
}

if ($action==='mark_all_present'){
  // Load all students in this plan+room
  $rs = $conn->query('SELECT student_id FROM seat_plan_allocations WHERE plan_id='.(int)$plan_id.' AND room_id='.(int)$room_id);
  if (!$rs){ echo json_encode(['success'=>false,'message'=>'load failed']); exit(); }
  $ok=0; while($r=$rs->fetch_assoc()){
    $sid=(int)$r['student_id']; if ($sid<=0) continue;
    $stmt = $conn->prepare("INSERT INTO exam_room_attendance (duty_date, plan_id, room_id, student_id, status, marked_by) VALUES (?,?,?,?, 'present', ?) ON DUPLICATE KEY UPDATE status='present', marked_by=VALUES(marked_by), marked_at=CURRENT_TIMESTAMP");
    $stmt->bind_param('siiii', $date, $plan_id, $room_id, $sid, $uid);
    if (@$stmt->execute()) $ok++;
  }
  echo json_encode(['success'=>true,'updated'=>$ok]);
  exit();
}

if ($action==='mark_all_absent'){
  // Load all students in this plan+room
  $rs = $conn->query('SELECT student_id FROM seat_plan_allocations WHERE plan_id='.(int)$plan_id.' AND room_id='.(int)$room_id);
  if (!$rs){ echo json_encode(['success'=>false,'message'=>'load failed']); exit(); }
  $ok=0; while($r=$rs->fetch_assoc()){
    $sid=(int)$r['student_id']; if ($sid<=0) continue;
    $stmt = $conn->prepare("INSERT INTO exam_room_attendance (duty_date, plan_id, room_id, student_id, status, marked_by) VALUES (?,?,?,?, 'absent', ?) ON DUPLICATE KEY UPDATE status='absent', marked_by=VALUES(marked_by), marked_at=CURRENT_TIMESTAMP");
    $stmt->bind_param('siiii', $date, $plan_id, $room_id, $sid, $uid);
    if (@$stmt->execute()) $ok++;
  }
  echo json_encode(['success'=>true,'updated'=>$ok]);
  exit();
}

echo json_encode(['success'=>false,'message'=>'unknown action']);
