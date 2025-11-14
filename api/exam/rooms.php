<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$date    = isset($_GET['date']) ? trim($_GET['date']) : '';

if ($plan_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid Plan ID']);
    exit;
}
// Accept empty date (some UIs may not need date to list rooms)
if ($date !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date']);
    exit;
}

$rooms = [];
$sql = "SELECT id, room_no, title FROM seat_plan_rooms WHERE plan_id=? ORDER BY room_no ASC";
if ($st = $conn->prepare($sql)) {
    $st->bind_param('i', $plan_id);
    if ($st->execute() && ($res = $st->get_result())) {
        while ($r = $res->fetch_assoc()) {
            $rooms[] = [
                'id' => (int)$r['id'],
                'room_no' => (string)$r['room_no'],
                'title' => isset($r['title']) ? (string)$r['title'] : null,
            ];
        }
    }
    $st->close();
}

echo json_encode(['success' => true, 'data' => ['rooms' => $rooms]]);
$conn->close();
