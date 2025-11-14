<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../bootstrap.php';

// Super admin only
api_require_auth(['super_admin']);

$actions = [];
$errors = [];

function has_index(mysqli $conn, string $table, string $index): bool {
    $idx = $conn->query("SHOW INDEX FROM `$table` WHERE Key_name='".$conn->real_escape_string($index)."'");
    return $idx && $idx->num_rows > 0;
}

function has_column(mysqli $conn, string $table, string $column): bool {
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '".$conn->real_escape_string($column)."'");
    return $res && $res->num_rows > 0;
}

// 1) Ensure table exists
if (!$conn->query("CREATE TABLE IF NOT EXISTS exam_room_invigilation (
  id INT AUTO_INCREMENT PRIMARY KEY,
  duty_date DATE NOT NULL,
  plan_id INT NOT NULL,
  room_id INT NOT NULL,
  teacher_user_id INT NOT NULL,
  assigned_by INT NOT NULL DEFAULT 0,
  assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_invig_plan_date (plan_id, duty_date)
)")) {
    $errors[] = 'Failed to ensure table: '.$conn->error;
} else {
    $actions[] = 'Table ensured';
}

// 2) Ensure columns exist
if (!has_column($conn, 'exam_room_invigilation', 'assigned_at')) {
    if ($conn->query("ALTER TABLE exam_room_invigilation ADD COLUMN assigned_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP")) {
        $actions[] = 'Column assigned_at added';
    } else {
        $errors[] = 'Failed to add assigned_at: '.$conn->error;
    }
}

// 3) Remove duplicates (keep highest id) for (duty_date, plan_id, room_id)
if (has_column($conn, 'exam_room_invigilation', 'id')) {
    $sqlCleanupRoom = "DELETE t1 FROM exam_room_invigilation t1
      JOIN exam_room_invigilation t2
        ON t1.duty_date=t2.duty_date AND t1.plan_id=t2.plan_id AND t1.room_id=t2.room_id
       AND t1.id < t2.id";
    if ($conn->query($sqlCleanupRoom)) {
        $actions[] = 'Duplicate room assignments cleaned';
    } else {
        $errors[] = 'Failed to cleanup room duplicates: '.$conn->error;
    }

    // Cleanup duplicates for (duty_date, plan_id, teacher_user_id)
    $sqlCleanupTeacher = "DELETE t1 FROM exam_room_invigilation t1
      JOIN exam_room_invigilation t2
        ON t1.duty_date=t2.duty_date AND t1.plan_id=t2.plan_id AND t1.teacher_user_id=t2.teacher_user_id
       AND t1.id < t2.id";
    if ($conn->query($sqlCleanupTeacher)) {
        $actions[] = 'Duplicate teacher assignments cleaned';
    } else {
        $errors[] = 'Failed to cleanup teacher duplicates: '.$conn->error;
    }
}

// 4) Ensure unique indexes
if (!has_index($conn, 'exam_room_invigilation', 'uniq_invig_date_plan_room')) {
    if ($conn->query("ALTER TABLE exam_room_invigilation ADD UNIQUE KEY uniq_invig_date_plan_room (duty_date, plan_id, room_id)")) {
        $actions[] = 'Unique index (date,plan,room) added';
    } else {
        $errors[] = 'Failed to add unique index (date,plan,room): '.$conn->error;
    }
}

if (!has_index($conn, 'exam_room_invigilation', 'uniq_invig_date_plan_teacher')) {
    if ($conn->query("ALTER TABLE exam_room_invigilation ADD UNIQUE KEY uniq_invig_date_plan_teacher (duty_date, plan_id, teacher_user_id)")) {
        $actions[] = 'Unique index (date,plan,teacher) added';
    } else {
        $errors[] = 'Failed to add unique index (date,plan,teacher): '.$conn->error;
    }
}

$ok = empty($errors);
http_response_code($ok ? 200 : 500);

echo json_encode([
    'success' => $ok,
    'data' => [ 'actions' => $actions ],
    'errors' => $errors,
]);
