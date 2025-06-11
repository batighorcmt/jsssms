<?php
include '/config/db.php';

if (isset($_GET['class_id'])) {
    $class_id = $_GET['class_id'];
    $stmt = $conn->prepare("SELECT id, section_name FROM sections WHERE class_id = ?");
    $stmt->bind_param("i", $class_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $sections = [];
    while ($row = $result->fetch_assoc()) {
        $sections[] = $row;
    }

    echo json_encode($sections);
}
?>
