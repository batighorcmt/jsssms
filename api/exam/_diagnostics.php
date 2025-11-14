<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../bootstrap.php';

// Super admin only for diagnostics
api_require_auth(['super_admin']);

$date = $_GET['date'] ?? '';
$target_plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : null;

if (!validate_date($date)) {
    api_response(false, 'Invalid or missing date. Use YYYY-MM-DD format.', 400);
}

$output = [
    'input' => [
        'date' => $date,
        'target_plan_id' => $target_plan_id,
    ],
    'trace' => [],
];

// Step 1: Find exams scheduled for this date
$step1_exams = [];
$sql_step1 = "SELECT DISTINCT exam_id FROM exam_subjects WHERE exam_date = ?";
if ($st1 = $conn->prepare($sql_step1)) {
    $st1->bind_param('s', $date);
    $st1->execute();
    $res1 = $st1->get_result();
    while ($row = $res1->fetch_assoc()) {
        $step1_exams[] = (int)$row['exam_id'];
    }
    $st1->close();
}
$output['trace'][] = [
    'step' => 1,
    'title' => 'Find Exam IDs for Date',
    'query' => $sql_step1,
    'params' => [$date],
    'result_count' => count($step1_exams),
    'exam_ids' => $step1_exams,
    'notes' => empty($step1_exams) ? 'No exams found for this date. This is the root cause.' : 'Exams found.'
];

if (empty($step1_exams)) {
    api_response(true, $output);
    exit;
}

// Step 2: Find seat plans linked to these exams
$step2_plans = [];
$exam_id_placeholders = implode(',', array_fill(0, count($step1_exams), '?'));
$sql_step2 = "SELECT DISTINCT plan_id FROM seat_plan_exams WHERE exam_id IN ($exam_id_placeholders)";
if ($target_plan_id) {
    $sql_step2 .= " AND plan_id = ?";
}

if ($st2 = $conn->prepare($sql_step2)) {
    $types = str_repeat('i', count($step1_exams));
    $params = $step1_exams;
    if ($target_plan_id) {
        $types .= 'i';
        $params[] = $target_plan_id;
    }
    $st2->bind_param($types, ...$params);
    $st2->execute();
    $res2 = $st2->get_result();
    while ($row = $res2->fetch_assoc()) {
        $step2_plans[] = (int)$row['plan_id'];
    }
    $st2->close();
}
$output['trace'][] = [
    'step' => 2,
    'title' => 'Find Seat Plan IDs for Exams',
    'query' => preg_replace('/\s+/', ' ', $sql_step2),
    'params' => $params,
    'result_count' => count($step2_plans),
    'plan_ids' => $step2_plans,
    'notes' => empty($step2_plans) ? 'No seat plans are linked to the exams on this date.' : 'Seat plans found.'
];

if (empty($step2_plans)) {
    api_response(true, $output);
    exit;
}

// Step 3: Get details and rooms for each plan
$step3_details = [];
foreach ($step2_plans as $plan_id) {
    $plan_info = null;
    $rooms = [];

    // Plan details
    $sql_plan_details = "SELECT plan_name, shift, status FROM seat_plans WHERE id = ?";
    if ($st_plan = $conn->prepare($sql_plan_details)) {
        $st_plan->bind_param('i', $plan_id);
        $st_plan->execute();
        $plan_info = $st_plan->get_result()->fetch_assoc();
        $st_plan->close();
    }

    // Rooms for this plan
    $sql_rooms = "SELECT id, room_no, title FROM seat_plan_rooms WHERE plan_id = ? ORDER BY room_no ASC";
    if ($st_rooms = $conn->prepare($sql_rooms)) {
        $st_rooms->bind_param('i', $plan_id);
        $st_rooms->execute();
        $res_rooms = $st_rooms->get_result();
        while ($room_row = $res_rooms->fetch_assoc()) {
            $rooms[] = $room_row;
        }
        $st_rooms->close();
    }
    
    $step3_details[] = [
        'plan_id' => $plan_id,
        'details' => $plan_info,
        'rooms_query' => $sql_rooms,
        'rooms_count' => count($rooms),
        'rooms' => $rooms,
        'notes' => empty($rooms) ? "This plan (ID: $plan_id) has NO rooms assigned in 'seat_plan_rooms' table." : "Found ".count($rooms)." room(s) for this plan."
    ];
}

$output['trace'][] = [
    'step' => 3,
    'title' => 'Get Plan Details and Rooms',
    'result' => $step3_details,
];

api_response(true, $output);
?>
