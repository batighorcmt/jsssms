<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

include '../config/db.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $student_name = $_POST['student_name'];
    $father_name = $_POST['father_name'];
    $mother_name = $_POST['mother_name'];
    $date_of_birth = $_POST['date_of_birth'];
    $gender = $_POST['gender'];
    $religion = $_POST['religion'];
    $blood_group = $_POST['blood_group'];
    $mobile_no = $_POST['mobile_no'];
    $village = $_POST['village'];
    $post_office = $_POST['post_office'];
    $upazilla = $_POST['upazilla'];
    $district = $_POST['district'];
    $class_id = $_POST['class_id'];
    $section_id = $_POST['section_id'];
    $student_group = $_POST['student_group'];
    $roll_no = $_POST['roll_no'];
    $year = $_POST['year'];

    // Student ID Generation
    $prefix = date('y') . str_pad($class_id, 2, '0', STR_PAD_LEFT);
    $query = "SELECT COUNT(*) AS total FROM students WHERE class_id = $class_id AND year = '$year'";
    $result = $conn->query($query);
    $data = $result->fetch_assoc();
    $serial = str_pad($data['total'] + 1, 3, '0', STR_PAD_LEFT);
    $student_id = $prefix . $serial;

    // Photo upload
    $photo_name = null;
    if (!empty($_FILES['student_photo']['name'])) {
        $target_dir = "../uploads/students/";
        if (!file_exists($target_dir)) {
            mkdir($target_dir, 0777, true);
        }

        $photo_name = uniqid() . "_" . basename($_FILES["student_photo"]["name"]);
        $target_file = $target_dir . $photo_name;
        move_uploaded_file($_FILES["student_photo"]["tmp_name"], $target_file);
    }

    // Insert query
    $stmt = $conn->prepare("INSERT INTO students (student_id, student_name, father_name, mother_name, date_of_birth, gender, religion, blood_group, mobile_no, village, post_office, upazilla, district, class_id, section_id, student_group, roll_no, year, photo, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("sssssssssssssssssss", $student_id, $student_name, $father_name, $mother_name, $date_of_birth, $gender, $religion, $blood_group, $mobile_no, $village, $post_office, $upazilla, $district, $class_id, $section_id, $student_group, $roll_no, $year, $photo_name);

    if ($stmt->execute()) {
        // ✅ STUDENT ADD SUCCESSFUL, now send SMS
        $api_key = "W6uYRXsPj2nLHfPJ3YCC";
        $type = "text";
        $senderid = "8809617625226";

        $message = "প্রিয় {$student_name},\nআপনার ভর্তি সম্পন্ন হয়েছে। Student ID: {$student_id}";
        $message = urlencode($message);
        $number = $mobile_no;

        $sms_url = "http://bulksmsbd.net/api/smsapi?api_key=$api_key&type=$type&number=$number&senderid=$senderid&message=$message";

        // Try sending SMS
        $response = @file_get_contents($sms_url); // Avoid warnings with @

        // Optional: Log the SMS response
        file_put_contents("../logs/sms_log.txt", date("Y-m-d H:i:s") . " | $number | $response\n", FILE_APPEND);

        // Redirect after success
        header("Location: add_subjects.php?student_id=" . $student_id);
        exit;
    } else {
        echo "Error inserting student: " . $stmt->error;
    }
}
?>
