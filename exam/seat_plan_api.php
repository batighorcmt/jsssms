<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
header('Content-Type: application/json');
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'forbidden']); exit(); }
include '../config/db.php';

$action = $_POST['action'] ?? '';
$plan_id = (int)($_POST['plan_id'] ?? 0);
$room_id = (int)($_POST['room_id'] ?? 0);
$col_no = (int)($_POST['col_no'] ?? 0);
$bench_no = (int)($_POST['bench_no'] ?? 0);
$position = (($_POST['position'] ?? 'L') === 'R') ? 'R' : 'L';

// Validate room belongs to plan
if ($plan_id<=0 || $room_id<=0){ echo json_encode(['success'=>false,'message'=>'invalid identifiers']); exit(); }
$chk = $conn->query('SELECT 1 FROM seat_plan_rooms WHERE id='.(int)$room_id.' AND plan_id='.(int)$plan_id.' LIMIT 1');
if (!$chk || $chk->num_rows===0){ echo json_encode(['success'=>false,'message'=>'room not in plan']); exit(); }

if ($action==='assign'){
    $student_id = (int)($_POST['student_id'] ?? 0);
    if ($student_id<=0 || $col_no<=0 || $bench_no<=0){ echo json_encode(['success'=>false,'message'=>'missing fields']); exit(); }
    // Check already assigned in this plan
    $st = $conn->prepare('SELECT 1 FROM seat_plan_allocations WHERE plan_id=? AND student_id=? LIMIT 1');
    $st->bind_param('ii', $plan_id, $student_id);
    $st->execute(); $st->store_result();
    if ($st->num_rows>0){ echo json_encode(['success'=>false,'message'=>'Student already assigned in this plan']); exit(); }
    // Clear existing occupant for this seat, then insert
    $conn->query("DELETE FROM seat_plan_allocations WHERE room_id={$room_id} AND col_no={$col_no} AND bench_no={$bench_no} AND position='{$conn->real_escape_string($position)}'");
    $ins = $conn->prepare('INSERT INTO seat_plan_allocations (plan_id, room_id, student_id, col_no, bench_no, position) VALUES (?,?,?,?,?,?)');
    $ins->bind_param('iiiiss', $plan_id, $room_id, $student_id, $col_no, $bench_no, $position);
    if (!@$ins->execute()){
        echo json_encode(['success'=>false,'message'=>'assign failed']); exit();
    }
    // Return student info
    $q = $conn->query("SELECT COALESCE(s1.student_name, s2.student_name) AS student_name,
                              COALESCE(s1.roll_no, s2.roll_no) AS roll_no,
                              COALESCE(c1.class_name, c2.class_name) AS class_name
                       FROM students s1
                       LEFT JOIN classes c1 ON c1.id = s1.class_id
                       LEFT JOIN students s2 ON s2.id = {$student_id}
                       LEFT JOIN classes c2 ON c2.id = s2.class_id
                       WHERE s1.student_id = {$student_id} OR s2.id = {$student_id}
                       LIMIT 1");
    $info = ['student_id'=>$student_id,'student_name'=>'','roll_no'=>'','class_name'=>''];
    if ($q && $q->num_rows>0){ $info = array_merge($info, $q->fetch_assoc()); }
    echo json_encode(['success'=>true,'data'=>[
        'student_id'=>$student_id,
        'student_name'=>$info['student_name'] ?? '',
        'roll_no'=>$info['roll_no'] ?? '',
        'class_name'=>$info['class_name'] ?? '',
        'col_no'=>$col_no,
        'bench_no'=>$bench_no,
        'position'=>$position,
    ]]);
    exit();
}

if ($action==='unassign'){
    if ($col_no<=0 || $bench_no<=0){ echo json_encode(['success'=>false,'message'=>'missing fields']); exit(); }
    $conn->query("DELETE FROM seat_plan_allocations WHERE room_id={$room_id} AND col_no={$col_no} AND bench_no={$bench_no} AND position='{$conn->real_escape_string($position)}'");
    echo json_encode(['success'=>true]);
    exit();
}

echo json_encode(['success'=>false,'message'=>'unknown action']);
