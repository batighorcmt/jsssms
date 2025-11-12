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
    // Normalize DOB: dd/mm/yyyy -> yyyy-mm-dd; invalid/empty -> NULL (MySQL strict-safe)
    $date_of_birth = trim($date_of_birth);
    if ($date_of_birth !== '') {
        if (preg_match('~^(\d{2})\/(\d{2})\/(\d{4})$~', $date_of_birth, $m)) {
            $date_of_birth = $m[3] . '-' . $m[2] . '-' . $m[1];
        } elseif (!preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $date_of_birth)) {
            $date_of_birth = null;
        }
    } else {
        $date_of_birth = null;
    }
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
        // Enforce max image size: 2MB
        $maxSize = 2 * 1024 * 1024; // 2MB
        if ((int)$_FILES['student_photo']['size'] > $maxSize) {
            $_SESSION['error'] = "Image is too large. Maximum allowed size is 2MB.";
            header("Location: edit_student.php?student_id=" . urlencode($student_id));
            exit;
        }
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
        // Close DB and show success popup on THIS page, then redirect
        $stmt->close();
        $conn->close();
        @include_once __DIR__ . '/../config/config.php';
        include '../includes/header.php';
        echo '<div class="content-wrapper"><section class="content"><div class="container-fluid py-5 text-center">'
            . '<h4>Processing...</h4>'
            . '<p>Please wait, redirecting to subject assignment.</p>'
            . '</div></section></div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'if(window.showToast){window.showToast("Success","Student updated successfully.","success");}else{alert("Student updated successfully. Redirecting...");}'
            . 'setTimeout(function(){window.location.href = ' . json_encode('add_subjects.php?student_id=' . urlencode($student_id)) . ';}, 1200);'
            . '});</script>';
        include '../includes/footer.php';
        exit;
    } else {
        // Show error popup on THIS page, then return to edit form
        $err = $conn->error;
        $stmt->close();
        $conn->close();
        @include_once __DIR__ . '/../config/config.php';
        include '../includes/header.php';
        echo '<div class="content-wrapper"><section class="content"><div class="container-fluid py-5 text-center">'
            . '<h4>Processing...</h4>'
            . '<p>Returning to edit page.</p>'
            . '</div></section></div>';
        $msg = 'Failed to update student. Please try again.';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){'
            . 'if(window.showToast){window.showToast("Error",' . json_encode($msg) . ',"error");}else{alert(' . json_encode($msg) . ');}'
            . 'setTimeout(function(){window.location.href = ' . json_encode('edit_student.php?student_id=' . urlencode($student_id)) . ';}, 1500);'
            . '});</script>';
        include '../includes/footer.php';
        exit;
    }
} else {
    header("Location: student_list.php");
    exit;
}