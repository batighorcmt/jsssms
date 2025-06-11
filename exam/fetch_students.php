<?php
include '../config/db.php';

$class_id = $_POST['class_id'];
$section_id = $_POST['section_id'];
$year = $_POST['year'];

$sql = "SELECT id, student_name, roll_no FROM students 
        WHERE class = '$class_id' AND section = '$section_id' AND year = '$year' AND status = 'Active'";
$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
echo json_encode($data);
?>
