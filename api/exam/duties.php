<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

// API auth: require token/session but never redirect
api_require_auth(['teacher','super_admin']);

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$duty_date = isset($_GET['date']) ? trim($_GET['date']) : '';

if ($plan_id <= 0 || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $duty_date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid Plan ID or Date']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save duties
    $data = json_decode(file_get_contents('php://input'), true);
    $map = $data['duties'] ?? []; // Expects a map of {room_id: teacher_user_id}
    // Use authenticated API user id
    global $authUser;
    $assigned_by = isset($authUser['id']) ? (int)$authUser['id'] : 0;

    if (empty($map)) {
        echo json_encode(['success' => false, 'error' => 'No duties data provided']);
        exit;
    }

    // Basic validation: ensure a teacher is not assigned to multiple rooms
    $teacherCounts = array_count_values(array_filter($map, fn($tid) => $tid > 0));
    foreach ($teacherCounts as $tid => $count) {
        if ($count > 1) {
            echo json_encode(['success' => false, 'error' => "Teacher ID $tid assigned to multiple rooms."]);
            exit;
        }
    }

    $stmt = $conn->prepare('INSERT INTO exam_room_invigilation (duty_date, plan_id, room_id, teacher_user_id, assigned_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE teacher_user_id=VALUES(teacher_user_id), assigned_by=VALUES(assigned_by), assigned_at=CURRENT_TIMESTAMP');
    
    if (!$stmt) {
        echo json_encode(['success' => false, 'error' => 'DB prepare failed: ' . $conn->error]);
        exit;
    }

    $success = true;
    foreach ($map as $room_id => $teacher_user_id) {
        $room_id = (int)$room_id;
        $teacher_user_id = (int)$teacher_user_id;
        if ($room_id <= 0 || $teacher_user_id <= 0) continue;

        $stmt->bind_param('siiii', $duty_date, $plan_id, $room_id, $teacher_user_id, $assigned_by);
        if (!$stmt->execute()) {
            $success = false;
            // Log error if possible, but don't stop for one failure
        }
    }
    $stmt->close();

    if ($success) {
        echo json_encode(['success' => true, 'message' => 'Duties saved successfully']);
    } else {
        echo json_encode(['success' => false, 'error' => 'One or more duty assignments failed to save.']);
    }

} else {
    // Get existing duties
    $dutyMap = [];
    $stmt = $conn->prepare('SELECT room_id, teacher_user_id FROM exam_room_invigilation WHERE duty_date=? AND plan_id=?');
    if ($stmt) {
        $stmt->bind_param('si', $duty_date, $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()){
            $dutyMap[$row['room_id']] = $row['teacher_user_id'];
        }
        $stmt->close();
    }
    echo json_encode(['success' => true, 'data' => ['duties' => $dutyMap]]);
}

$conn->close();
