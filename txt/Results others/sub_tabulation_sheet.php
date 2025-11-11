<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
?>

<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>
<div class="container mt-4">
    <h4>টেবুলেশন শিট</h4>

    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-5">
            <label class="form-label">পরীক্ষা নির্বাচন করুন</label>
            <select name="exam_id" class="form-select" required>
                <option value="">-- পরীক্ষা নির্বাচন করুন --</option>
                <?php
                $exams = $conn->query("SELECT id, exam_name FROM exams ORDER BY exam_name ASC");
                while ($exam = $exams->fetch_assoc()):
                ?>
                <option value="<?= $exam['id'] ?>" <?= (isset($_GET['exam_id']) && $_GET['exam_id'] == $exam['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($exam['exam_name']) ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="col-md-2 align-self-end">
            <button type="submit" class="btn btn-primary">দেখুন</button>
        </div>
    </form>

    <?php
    if (isset($_GET['exam_id'])):
        $exam_id = intval($_GET['exam_id']);

        // Get exam name and class
        $examInfo = $conn->query("SELECT exams.exam_name, classes.class_name, classes.id AS class_id 
                                  FROM exams 
                                  JOIN classes ON exams.class_id = classes.id 
                                  WHERE exams.id = $exam_id")->fetch_assoc();

        if (!$examInfo) {
            echo '<div class="alert alert-danger">পরীক্ষা খুঁজে পাওয়া যায়নি।</div>';
        } else {
            echo "<div class='d-flex justify-content-between align-items-center'>
                    <h5>পরীক্ষা: {$examInfo['exam_name']} | শ্রেণি: {$examInfo['class_name']}</h5>
                    <a href='export_tabulation_pdf.php?exam_id={$exam_id}' class='btn btn-sm btn-secondary'>Export PDF</a>
                  </div><hr>";

            // Get subjects of the exam
            $subjects = $conn->query("SELECT es.subject_id, s.subject_name 
                                      FROM exam_subjects es 
                                      JOIN subjects s ON es.subject_id = s.id 
                                      WHERE es.exam_id = $exam_id");

            $subjectList = [];
            while ($s = $subjects->fetch_assoc()) {
                $subjectList[$s['subject_id']] = $s['subject_name'];
            }

            if (empty($subjectList)) {
                echo '<div class="alert alert-warning">এই পরীক্ষার জন্য কোনো বিষয় নির্ধারিত নেই।</div>';
            } else {
                // Get students of the class
                $students = $conn->query("SELECT id, student_name, roll_no FROM students WHERE class_id = {$examInfo['class_id']} ORDER BY roll_no ASC");

                echo '<div class="table-responsive">';
                echo '<table class="table table-bordered table-striped table-sm">';
                echo '<thead class="table-light">';
                echo '<tr>';
                echo '<th rowspan="2">রোল</th>';
                echo '<th rowspan="2">নাম</th>';

                foreach ($subjectList as $subName) {
                    echo "<th colspan='4' class='text-center'>$subName</th>";
                }

                echo '<th rowspan="2">মোট</th>';
                echo '<th rowspan="2">গ্রেড</th>';
                echo '</tr>';

                // Sub-Headers
                echo '<tr>';
                foreach ($subjectList as $_) {
                    echo "<th>সৃজন</th><th>নৈর্ব</th><th>ব্যবহ</th><th>মোট</th>";
                }
                echo '</tr>';
                echo '</thead><tbody>';

                while ($stu = $students->fetch_assoc()) {
                    $student_id = $stu['id'];
                    echo '<tr>';
                    echo "<td>{$stu['roll_no']}</td>";
                    echo "<td>{$stu['student_name']}</td>";

                    $total = 0;

                    foreach (array_keys($subjectList) as $sub_id) {
                        $mark = $conn->query("SELECT 
                                                COALESCE(creative_marks,0) AS creative,
                                                COALESCE(objective_marks,0) AS objective,
                                                COALESCE(practical_marks,0) AS practical
                                              FROM marks 
                                              WHERE exam_id=$exam_id AND student_id=$student_id AND subject_id=$sub_id")
                                    ->fetch_assoc();

                        $sub_total = $mark['creative'] + $mark['objective'] + $mark['practical'];
                        echo "<td>{$mark['creative']}</td><td>{$mark['objective']}</td><td>{$mark['practical']}</td><td><strong>$sub_total</strong></td>";
                        $total += $sub_total;
                    }

                    $grade = get_grade($total);
                    echo "<td><strong>$total</strong></td><td><strong>$grade</strong></td>";
                    echo '</tr>';
                }

                echo '</tbody></table></div>';
            }
        }
    endif;

    // Grade function
    function get_grade($total) {
        if ($total >= 80) return "A+";
        if ($total >= 70) return "A";
        if ($total >= 60) return "A-";
        if ($total >= 50) return "B";
        if ($total >= 40) return "C";
        if ($total >= 33) return "D";
        return "F";
    }
    ?>
</div>
</div>

<?php include '../includes/footer.php'; ?>
