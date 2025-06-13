<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php'; // Ensure path is correct

if (isset($_POST['class_id'])) {
    $class_id = $_POST['class_id'];

    $stmt = $conn->prepare("SELECT subject_id, subject_name FROM subjects WHERE class_id = ? AND status = 'Active'");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    echo '<option value="">বিষয় নির্বাচন করুন</option>';
    while ($row = $result->fetch_assoc()) {
        echo '<option value="' . $row['subject_id'] . '">' . $row['subject_name'] . '</option>';
    }
    $stmt->close();
}
?>
 