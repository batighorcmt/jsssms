<?php
@include_once __DIR__ . '/../bootstrap.php';
api_require_auth(['super_admin']);

// total and per-class counts
$total = 0; $byClass = [];
if ($r = $conn->query("SELECT COUNT(*) AS c FROM students")) {
  $row = $r->fetch_assoc(); $total = (int)($row['c'] ?? 0);
}
$sql = "SELECT c.id AS class_id, c.class_name, COUNT(s.id) AS cnt
        FROM students s LEFT JOIN classes c ON s.class_id = c.id
        GROUP BY c.id, c.class_name ORDER BY c.class_name ASC";
if ($res = $conn->query($sql)) {
  while ($row = $res->fetch_assoc()) {
    $byClass[] = [
      'class_id' => (int)($row['class_id'] ?? 0),
      'class_name' => $row['class_name'] ?? '',
      'count' => (int)($row['cnt'] ?? 0)
    ];
  }
}
api_response(true, ['total' => $total, 'by_class' => $byClass]);
