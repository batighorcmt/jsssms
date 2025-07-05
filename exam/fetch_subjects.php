<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php';

if (isset($_POST['class_id']) && isset($_POST['group'])) {
    $class_id = $_POST['class_id'];
    $group = $_POST['group']; // যেমন: Science, Humanities, Business Studies

    $stmt = $conn->prepare("
        SELECT s.id, s.subject_name
        FROM subjects s
        JOIN subject_group_map gm ON gm.subject_id = s.id
        WHERE gm.class_id = ? 
          AND gm.group_name = ?
          AND s.status = 'Active'
    ");

    if ($stmt === false) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    $stmt->bind_param("is", $class_id, $group);
    $stmt->execute();

    $stmt->bind_result($id, $subject_name);

    echo '<option value="">বিষয় নির্বাচন করুন</option>';
    while ($stmt->fetch()) {
        echo '<option value="' . $id . '">' . $subject_name . '</option>';
    }

    $stmt->close();
}
?>
