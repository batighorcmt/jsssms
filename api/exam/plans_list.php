<?php
require_once __DIR__ . '/../../config/db.php';
// এখানে bootstrap/ api_require_auth কিছুই নেই

header('Content-Type: application/json; charset=utf-8');

$plans = [];
$sql = "SELECT id, plan_name, shift, status
        FROM seat_plans
        WHERE status='active'
        ORDER BY id DESC";

if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
        $plans[] = [
            'id'        => (int)$row['id'],
            'plan_name' => (string)$row['plan_name'],
            'shift'     => (string)$row['shift'],
            'status'    => (string)$row['status'],
        ];
    }
}

echo json_encode([
    'success' => true,
    'data'    => ['plans' => $plans],
]);