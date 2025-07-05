<?php
include '../config/db.php';

$class_id = $_GET['class_id'] ?? 0;

$subjects = [];

// মূল SQL - subject_id একাধিকবার আসলেও handle করা হবে
$sql = "
    SELECT 
        s.id,
        s.subject_name,
        s.subject_code,
        s.group,
        s.has_creative,
        s.has_objective,
        s.has_practical,
        gm.type
    FROM subject_group_map gm
    JOIN subjects s ON gm.subject_id = s.id
    WHERE gm.class_id = ? AND s.status = 'Active'
    ORDER BY s.subject_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $class_id);
$stmt->execute();
$result = $stmt->get_result();

// ডুপ্লিকেট সাবজেক্ট রিমুভ ও টাইপ গুলো গ্রুপিং করা
$subject_map = [];

while ($row = $result->fetch_assoc()) {
    $id = $row['id'];
    
    if (!isset($subject_map[$id])) {
        $subject_map[$id] = [
            'id' => $row['id'],
            'subject_name' => $row['subject_name'],
            'subject_code' => $row['subject_code'],
            'group' => $row['group'],
            'has_creative' => $row['has_creative'],
            'has_objective' => $row['has_objective'],
            'has_practical' => $row['has_practical'],
            'types' => [$row['type']]
        ];
    } else {
        // type আগে না থাকলে যোগ করো
        if (!in_array($row['type'], $subject_map[$id]['types'])) {
            $subject_map[$id]['types'][] = $row['type'];
        }
    }
}

$subjects = array_values($subject_map);

echo json_encode($subjects);
