<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';

if (!$exam_id || !$class_id || !$year) {
    echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>";
    exit;
}

// পরীক্ষার নাম
$exam = mysqli_fetch_assoc(mysqli_query($conn, "SELECT exam_name FROM exams WHERE id='$exam_id'"))['exam_name'];

// ক্লাস নাম
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='$class_id'"))['class_name'];

// বিষয় ও পাস নম্বর
$subjects_q = mysqli_query($conn, "
    SELECT s.id as subject_id, s.subject_name, s.subject_code, 
           s.has_creative, s.has_objective, s.has_practical,
           es.creative_pass, es.objective_pass, es.practical_pass
    FROM exam_subjects es 
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id='$exam_id' AND s.class_id='$class_id'
");

$subjects = [];
while ($row = mysqli_fetch_assoc($subjects_q)) {
    $subjects[] = $row;
}

// শিক্ষার্থীদের তথ্য
$students_q = mysqli_query($conn, "
    SELECT * FROM students 
    WHERE class_id='$class_id' AND year='$year'
    ORDER BY roll_no ASC
");

function getMarks($student_id, $subject_id, $exam_id, $type) {
    global $conn;
    $field = $type . "_marks";
    $res = mysqli_fetch_assoc(mysqli_query($conn, "
        SELECT $field FROM marks 
        WHERE student_id='$student_id' AND subject_id='$subject_id' AND exam_id='$exam_id'
    "));
    return $res[$field] ?? 0;
}

function isFail($mark, $pass_mark) {
    return ($mark < $pass_mark || $mark == 0);
}

function subjectGPA($total) {
    if ($total >= 80) return 5.00;
    elseif ($total >= 70) return 4.00;
    elseif ($total >= 60) return 3.50;
    elseif ($total >= 50) return 3.00;
    elseif ($total >= 40) return 2.00;
    elseif ($total >= 33) return 1.00;
    return 0.00;
}

?>

<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <title>টেবুলেশন শীট</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container my-5">
    <div class="card shadow">
        <div class="card-body">
            <h4 class="text-center mb-4">টেবুলেশন শীট - <?= $exam ?> (<?= $class ?>, <?= $year ?>)</h4>

            <div class="table-responsive">
                <table class="table table-bordered table-striped align-middle text-center">
                    <thead class="table-primary">
                        <tr>
                            <th>ক্রম</th>
                            <th>শিক্ষার্থীর নাম</th>
                            <th>রোল</th>
                            <?php foreach ($subjects as $sub): ?>
                                <th>
                                    <?= $sub['subject_name'] ?><br>
                                    <?= $sub['has_creative'] ? "C " : "" ?>
                                    <?= $sub['has_objective'] ? "O " : "" ?>
                                    <?= $sub['has_practical'] ? "P " : "" ?>
                                </th>
                            <?php endforeach; ?>
                            <th>মোট</th>
                            <th>GPA</th>
                            <th>ফেল</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $all_students = [];
                        while ($stu = mysqli_fetch_assoc($students_q)) {
                            $total = 0;
                            $fail_count = 0;
                            $gpa_total = 0;
                            $gpa_subjects = 0;
                            $row = "<tr><td>{$stu['roll_no']}</td><td>{$stu['student_name']}</td><td>{$stu['roll_no']}</td>";

                            foreach ($subjects as $sub) {
                                $c = $sub['has_creative'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'creative') : null;
                                $o = $sub['has_objective'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'objective') : null;
                                $p = $sub['has_practical'] ? getMarks($stu['student_id'], $sub['subject_id'], $exam_id, 'practical') : null;

                                $marks_display = '';
                                $sub_total = 0;
                                $fail = false;

                                if ($class_id <= 3) {
                                    if (!is_null($c)) $sub_total += $c;
                                    if (!is_null($o)) $sub_total += $o;
                                    if (!is_null($p)) $sub_total += $p;
                                    if ($sub_total < 33 || $c == 0 || $o == 0 || $p == 0) {
                                        $fail = true;
                                        $fail_count++;
                                    }
                                    $marks_display = ($c !== null ? "C: $c<br>" : "") . ($o !== null ? "O: $o<br>" : "") . ($p !== null ? "P: $p<br>" : "");
                                } else {
                                    if ($sub['has_creative']) {
                                        if (isFail($c, $sub['creative_pass'])) $fail = true;
                                        $marks_display .= "C: " . ($c == 0 ? "<span class='text-danger fw-bold'>$c</span>" : $c) . "<br>";
                                    }
                                    if ($sub['has_objective']) {
                                        if (isFail($o, $sub['objective_pass'])) $fail = true;
                                        $marks_display .= "O: " . ($o == 0 ? "<span class='text-danger fw-bold'>$o</span>" : $o) . "<br>";
                                    }
                                    if ($sub['has_practical']) {
                                        if (isFail($p, $sub['practical_pass'])) $fail = true;
                                        $marks_display .= "P: " . ($p == 0 ? "<span class='text-danger fw-bold'>$p</span>" : $p) . "<br>";
                                    }

                                    $sub_total = ($c ?? 0) + ($o ?? 0) + ($p ?? 0);
                                    if ($fail) $fail_count++;
                                }

                                $gpa = subjectGPA($sub_total);
                                $gpa_total += $gpa;
                                $gpa_subjects++;

                                $total += $sub_total;
                                $row .= "<td>" . ($fail ? "<span class='text-danger fw-bold'>$sub_total</span>" : $sub_total) . "<br><small>$marks_display</small></td>";
                            }

                            $final_gpa = $fail_count > 0 ? 0.00 : round($gpa_total / $gpa_subjects, 2);
                            $row .= "<td>$total</td><td>$final_gpa</td><td>$fail_count</td></tr>";

                            $all_students[] = ['fail' => $fail_count, 'total' => $total, 'row' => $row];
                        }

                        usort($all_students, function ($a, $b) {
                            return $a['fail'] === $b['fail'] ? $b['total'] - $a['total'] : $a['fail'] - $b['fail'];
                        });

                        foreach ($all_students as $i => $stu) {
                            echo $stu['row'];
                        }
                        ?>
                    </tbody>
                </table>
            </div>

        </div>
    </div>
</div>
</body>
</html>
