<?php
// fetch_subjects.php
include '../config/db.php';

if (isset($_POST['class_id']) && !empty($_POST['class_id'])) {
    $class_id = intval($_POST['class_id']);

    // subjects টেবিল থেকে ঐ class_id এর Active subjects নিয়ে আসা
    $sql = "SELECT id, subject_name FROM subjects WHERE class_id = ? AND status = 'Active' ORDER BY subject_name";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<option value="">বিষয় নির্বাচন করুন</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="'.htmlspecialchars($row['id']).'">'.htmlspecialchars($row['subject_name']).'</option>';
    }

} else {
    // যদি class_id না পাওয়া যায় বা ফাঁকা হয়
    echo '<option value="">শ্রেণি নির্বাচন করুন আগে</option>';
}
?>
