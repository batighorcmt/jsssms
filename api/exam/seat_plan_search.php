<?php
// Open search endpoint: find seat by roll or name within a plan
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

$plan_id = isset($_GET['plan_id']) ? (int)$_GET['plan_id'] : 0;
$q = isset($_GET['find']) ? trim($_GET['find']) : '';
if ($plan_id <= 0 || $q === '') {
    api_response(false, 'plan_id and find are required', 400);
}

// Prepare search pattern
$like = '%' . $conn->real_escape_string($q) . '%';

$sql = "SELECT a.student_id, s.student_name, s.roll_no, c.class_name,
               r.room_no, a.col_no, a.bench_no, a.position
        FROM seat_plan_allocations a
        JOIN students s ON s.student_id = a.student_id
        LEFT JOIN classes c ON c.id = s.class_id
        JOIN seat_plan_rooms r ON r.id = a.room_id
        WHERE a.plan_id = ?
          AND (s.roll_no LIKE ? OR s.student_name LIKE ?)
        ORDER BY s.roll_no ASC, s.student_name ASC
        LIMIT 100";

$results = [];
if ($st = $conn->prepare($sql)) {
    $st->bind_param('iss', $plan_id, $like, $like);
    if ($st->execute()) {
        $res = $st->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[] = [
                'student_id'   => (int)$row['student_id'],
                'student_name' => (string)$row['student_name'],
                'roll_no'      => (string)$row['roll_no'],
                'class_name'   => (string)($row['class_name'] ?? ''),
                'room_no'      => (string)$row['room_no'],
                'col_no'       => (int)$row['col_no'],
                'bench_no'     => (int)$row['bench_no'],
                'position'     => (string)$row['position'],
            ];
        }
    }
    $st->close();
}

api_response(true, ['results' => $results]);
