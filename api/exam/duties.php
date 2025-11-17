<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';
@include_once __DIR__ . '/../lib/fcm.php';

// Note: GET (read) is open; POST (write) requires auth. No redirects, JSON only.

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$duty_date = isset($_GET['date']) ? trim($_GET['date']) : '';

if ($plan_id <= 0 || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $duty_date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid Plan ID or Date']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Require API auth on write
    api_require_auth(['teacher','super_admin']);
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

    // Begin atomic operation
    $conn->begin_transaction();
    $success = true;

    // Ensure a teacher is assigned to only one room for this plan/date:
    // Delete any existing assignments for the submitted teachers on this (date, plan).
    $teacherIds = array_values(array_unique(array_map(fn($t)=> (int)$t, array_filter($map, fn($t)=> (int)$t > 0))));
    if (!empty($teacherIds)) {
        // Build placeholders for IN clause
        $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));
        $types = str_repeat('i', count($teacherIds));
        $sqlDel = "DELETE FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND teacher_user_id IN ($placeholders)";
        if ($stDel = $conn->prepare($sqlDel)) {
            $bindParams = array_merge([$duty_date, $plan_id], $teacherIds);
            // bind_param requires references
            $bindTypes = 'si' . $types;
            $refs = [];
            $refs[] = $bindTypes;
            // Use call_user_func_array trick for dynamic bind
            $stmtParams = [];
            $stmtParams[] = &$bindTypes;
            $tmp1 = $duty_date; $tmp2 = $plan_id; // preserve variables for reference
            $stmtParams[] = &$tmp1; $stmtParams[] = &$tmp2;
            foreach ($teacherIds as $k => $v) { $refs[$k] = $v; }
            // Build references for remaining
            for ($i=0;$i<count($teacherIds);$i++) { $stmtParams[] = &$teacherIds[$i]; }
            call_user_func_array([$stDel, 'bind_param'], $stmtParams);
            if (!$stDel->execute()) { $success = false; }
            $stDel->close();
        } else {
            $success = false;
        }
    }

    // Preload plan display (for notification body)
    $planName = '';
    $planShift = '';
    if ($rp = $conn->prepare('SELECT plan_name, shift FROM seat_plans WHERE id=? LIMIT 1')){
        $rp->bind_param('i', $plan_id);
        $rp->execute();
        $rpr = $rp->get_result();
        if ($rpr && $prow = $rpr->fetch_assoc()){
            $planName = (string)($prow['plan_name'] ?? '');
            $planShift = (string)($prow['shift'] ?? '');
        }
        $rp->close();
    }

    // Helper to get friendly room label once per room (for notification body)
    $roomLabelCache = [];
    $getRoomLabel = function(int $rid) use ($conn, &$roomLabelCache): string {
        if (isset($roomLabelCache[$rid])) return $roomLabelCache[$rid];
        $label = 'Room #' . $rid;
        if ($qr = $conn->prepare('SELECT room_no, title FROM seat_plan_rooms WHERE id=? LIMIT 1')){
            $qr->bind_param('i', $rid);
            $qr->execute();
            $res = $qr->get_result();
            if ($res && $row = $res->fetch_assoc()){
                $label = trim((string)($row['room_no'] ?? ''));
                if (!empty($row['title'])) { $label .= ' — ' . (string)$row['title']; }
                if ($label==='') $label = 'Room #' . $rid;
            }
            $qr->close();
        }
        $roomLabelCache[$rid] = $label;
        return $label;
    };

    // Upsert new assignments
    if ($success) {
        $stmt = $conn->prepare('INSERT INTO exam_room_invigilation (duty_date, plan_id, room_id, teacher_user_id, assigned_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE teacher_user_id=VALUES(teacher_user_id), assigned_by=VALUES(assigned_by), assigned_at=CURRENT_TIMESTAMP');
        if (!$stmt) {
            $success = false;
        } else {
            foreach ($map as $room_id => $teacher_user_id) {
                $room_id = (int)$room_id;
                $teacher_user_id = (int)$teacher_user_id;
                if ($room_id <= 0 || $teacher_user_id <= 0) continue;
                // Check existing assignment to avoid duplicate notifications
                $prev = null;
                if ($qprev = $conn->prepare('SELECT teacher_user_id FROM exam_room_invigilation WHERE duty_date=? AND plan_id=? AND room_id=? LIMIT 1')){
                    $qprev->bind_param('sii', $duty_date, $plan_id, $room_id);
                    $qprev->execute();
                    $resPrev = $qprev->get_result();
                    if ($resPrev && $rowPrev = $resPrev->fetch_assoc()) { $prev = (int)$rowPrev['teacher_user_id']; }
                    $qprev->close();
                }
                $stmt->bind_param('siiii', $duty_date, $plan_id, $room_id, $teacher_user_id, $assigned_by);
                if (!$stmt->execute()) { $success = false; break; }
                // Notify only when new or changed
                if ($success && ($prev === null || $prev !== $teacher_user_id)) {
                    $roomLabel = $getRoomLabel($room_id);
                    $dateDisp = $duty_date;
                    if (($t = strtotime($duty_date)) !== false) { $dateDisp = date('d M Y', $t); }
                    $title = 'Room Duty Assigned';
                    $body  = 'You are assigned to ' . $roomLabel . ' on ' . $dateDisp . ($planName ? (' — ' . $planName . ($planShift ? (' (' . $planShift . ')') : '')) : '');
                    try {
                        if (function_exists('fcm_send_to_user')) {
                            fcm_send_to_user($conn, (int)$teacher_user_id, $title, $body, [
                                'type' => 'duty_assignment',
                                'plan_id' => (string)$plan_id,
                                'room_id' => (string)$room_id,
                                'duty_date' => (string)$duty_date,
                            ]);
                        }
                    } catch (Throwable $e) {
                        if (function_exists('fcm_log')) { fcm_log('Notify error: '.$e->getMessage()); }
                    }
                }
            }
            $stmt->close();
        }
    }

    if ($success) { $conn->commit(); } else { $conn->rollback(); }

    if ($success) {
        // Return updated duties map so UI can refresh immediately
        $dutyMap = [];
        $stmt = $conn->prepare('SELECT room_id, teacher_user_id FROM exam_room_invigilation WHERE duty_date=? AND plan_id=?');
        if ($stmt) {
            $stmt->bind_param('si', $duty_date, $plan_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()){
                $rid = isset($row['room_id']) ? (int)$row['room_id'] : 0;
                $tid = isset($row['teacher_user_id']) ? (int)$row['teacher_user_id'] : 0;
                $dutyMap[(string)$rid] = (string)$tid;
            }
            $stmt->close();
        }
        // Notifications are sent inline during upsert per change

        echo json_encode(['success' => true, 'data' => ['duties' => (object)$dutyMap]]);
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
            // Use string keys so JSON encodes as an object, not a list
            $rid = isset($row['room_id']) ? (int)$row['room_id'] : 0;
            $tid = isset($row['teacher_user_id']) ? (int)$row['teacher_user_id'] : 0;
            $dutyMap[(string)$rid] = (string)$tid;
        }
        $stmt->close();
    }
    echo json_encode(['success' => true, 'data' => ['duties' => (object)$dutyMap]]);
}

$conn->close();
