<?php
include '../config/db.php';

$student_id = $_GET['student_id'] ?? '';
if (!$student_id) {
    echo "<p class='text-danger'>শিক্ষার্থী খুঁজে পাওয়া যায়নি।</p>";
    exit();
}

// ছাত্রের শ্রেণি খোঁজা
$stmt = $conn->prepare("SELECT class_id FROM students WHERE student_id = ?");
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();

if (!$student) {
    echo "<p class='text-danger'>শিক্ষার্থী খুঁজে পাওয়া যায়নি।</p>";
    exit();
}

$class_id = $student['class_id'];

// ঐ শ্রেণির বিষয়গুলো খোঁজা
$subjects = $conn->query("SELECT * FROM subjects WHERE class_id = $class_id AND status='Active'");

// এই ছাত্র কোন কোন বিষয় ইতিমধ্যে নিয়েছে
$assigned = [];
$res = $conn->query("SELECT subject_id FROM student_subjects WHERE student_id = '$student_id'");
while ($row = $res->fetch_assoc()) {
    $assigned[] = $row['subject_id'];
}

if ($subjects->num_rows === 0) {
    echo "<p class='text-warning'>এই শ্রেণির জন্য কোনো বিষয় পাওয়া যায়নি।</p>";
    exit();
}

echo "<label class='form-label'>বিষয়সমূহ নির্বাচন করুন</label><div class='row'>";
while ($row = $subjects->fetch_assoc()) {
    $checked = in_array($row['id'], $assigned) ? 'checked' : '';
    echo "<div class='col-md-4'>
            <div class='form-check'>
                <input class='form-check-input' type='checkbox' name='subjects[]' value='{$row['id']}' id='subject{$row['id']}' $checked>
                <label class='form-check-label' for='subject{$row['id']}'>{$row['subject_name']} ({$row['subject_code']})</label>
            </div>
          </div>";
}
echo "</div>";
