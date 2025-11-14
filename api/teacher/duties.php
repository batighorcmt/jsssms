<?php
require_once __DIR__ . '/../bootstrap.php';
api_require_auth(['teacher','super_admin']);

$days = isset($_GET['days']) ? (int)$_GET['days'] : 7;
if ($days < 1 || $days > 30) $days = 7;
$today = date('Y-m-d');
$endDate = date('Y-m-d', time() + ($days * 86400));

// Duties: exam_room_invigilation joined with seat_plan_rooms & seat_plan_exams & exam_subjects for date details
$sql = "SELECT eri.duty_date, eri.plan_id, eri.room_id, r.room_no, r.title,
        esub.exam_date, esub.exam_id
        FROM exam_room_invigilation eri
        JOIN seat_plan_rooms r ON eri.room_id = r.id
        JOIN seat_plan_exams spe ON eri.plan_id = spe.plan_id
        JOIN exam_subjects esub ON spe.exam_id = esub.exam_id AND esub.exam_date IS NOT NULL
        WHERE eri.teacher_user_id = ? AND eri.duty_date BETWEEN ? AND ?
        ORDER BY eri.duty_date ASC";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('iss', $authUser['id'], $today, $endDate);
    if (!$stmt->execute()) api_response(false, 'Query failed', 500);
    $res = $stmt->get_result();
    $rows = [];
    while ($r = $res->fetch_assoc()) {
        $rows[] = [
            'date' => $r['duty_date'],
            'room_id' => (int)$r['room_id'],
            'plan_id' => (int)$r['plan_id'],
            'room_no' => $r['room_no'],
            'room_title' => $r['title'],
            'exam_id' => (int)$r['exam_id'],
            'exam_date' => $r['exam_date']
        ];
    }
    api_response(true, ['range'=>['start'=>$today,'end'=>$endDate],'duties'=>$rows]);
} else api_response(false, 'Prepare failed', 500);
?>