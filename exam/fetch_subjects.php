<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../config/db.php';

if (isset($_POST['class_id'])) {
    $class_id = $_POST['class_id'];

    $stmt = $conn->prepare("SELECT id, subject_name FROM subjects WHERE class_id = ? AND status = 'Active'");

    if ($stmt === false) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    $stmt->bind_param("i", $class_id);
    $stmt->execute();

    // get_result() বাদ দিয়ে bind_result ব্যবহার করা হচ্ছে
    $stmt->bind_result($id, $subject_name);

    echo '<option value="">বিষয় নির্বাচন করুন</option>';
    while ($stmt->fetch()) {
        echo '<option value="' . $id . '">' . $subject_name . '</option>';
    }

    $stmt->close();
}
?>
