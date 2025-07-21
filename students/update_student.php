<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit;
}

include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_POST['student_id'] ?? '';
    
    if (!$student_id) {
        $_SESSION['error'] = "শিক্ষার্থী ID পাওয়া যায়নি";
        header("Location: student_list.php");
        exit;
    }

    // Collect form data
    $student_name = $_POST['student_name'] ?? '';
    $father_name = $_POST['father_name'] ?? '';
    $mother_name = $_POST['mother_name'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $blood_group = $_POST['blood_group'] ?? '';
    $mobile_no = $_POST['mobile_no'] ?? '';
    $village = $_POST['village'] ?? '';
    $post_office = $_POST['post_office'] ?? '';
    $upazilla = $_POST['upazilla'] ?? '';
    $district = $_POST['district'] ?? '';
    $class_id = $_POST['class_id'] ?? '';
    $section_id = $_POST['section_id'] ?? '';
    $student_group = $_POST['student_group'] ?? '';
    $roll_no = $_POST['roll_no'] ?? '';
    $year = $_POST['year'] ?? '';
    
    // Handle file upload
    $photo_name = $_POST['existing_photo'] ?? '';
    
    if (isset($_FILES['student_photo']) && $_FILES['student_photo']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/students/';
        $file_ext = pathinfo($_FILES['student_photo']['name'], PATHINFO_EXTENSION);
        $photo_name = 'student_' . time() . '.' . $file_ext;
        
        if (move_uploaded_file($_FILES['student_photo']['tmp_name'], $upload_dir . $photo_name)) {
            // Delete old photo if exists
            if (!empty($_POST['existing_photo'])) {
                $old_file = $upload_dir . $_POST['existing_photo'];
                if (file_exists($old_file)) {
                    unlink($old_file);
                }
            }
        } else {
            $photo_name = $_POST['existing_photo'] ?? '';
        }
    }
    
    // Update student in database
    $stmt = $conn->prepare("UPDATE students SET 
        student_name = ?, 
        father_name = ?, 
        mother_name = ?, 
        date_of_birth = ?, 
        gender = ?, 
        religion = ?, 
        blood_group = ?, 
        mobile_no = ?, 
        village = ?, 
        post_office = ?, 
        upazilla = ?, 
        district = ?, 
        class_id = ?, 
        section_id = ?, 
        student_group = ?, 
        roll_no = ?, 
        year = ?, 
        photo = ? 
        WHERE student_id = ?");
    
    $stmt->bind_param("sssssssssssssssssss", 
        $student_name, 
        $father_name, 
        $mother_name, 
        $date_of_birth, 
        $gender, 
        $religion, 
        $blood_group, 
        $mobile_no, 
        $village, 
        $post_office, 
        $upazilla, 
        $district, 
        $class_id, 
        $section_id, 
        $student_group, 
        $roll_no, 
        $year, 
        $photo_name, 
        $student_id);
    
    if ($stmt->execute()) {
        $_SESSION['success'] = "শিক্ষার্থীর তথ্য সফলভাবে আপডেট করা হয়েছে";
    } else {
        $_SESSION['error'] = "শিক্ষার্থীর তথ্য আপডেট করতে সমস্যা হয়েছে: " . $conn->error;
    }
    
    $stmt->close();
    $conn->close();
    
    header("Location: edit_student.php?student_id=" . $student_id);
    exit;
} else {
    header("Location: student_list.php");
    exit;
}