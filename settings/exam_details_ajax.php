<?php
include '../config/db.php';

$exam_id = intval($_POST['exam_id']);

$sql = "
    SELECT es.id, s.subject_name, es.exam_date, es.exam_time,
           es.creative_marks, es.objective_marks, es.practical_marks, es.total_marks
    FROM exam_subjects es
    JOIN subjects s ON es.subject_id = s.id
    WHERE es.exam_id = ?
    ORDER BY s.subject_name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo '<div class="alert alert-warning">এই পরীক্ষার কোন বিষয় পাওয়া যায়নি।</div>';
    exit;
}
?>

<table class="table table-bordered">
    <thead>
        <tr>
            <th>ক্রমিক</th>
            <th>বিষয়</th>
            <th>তারিখ</th>
            <th>সময়</th>
            <th>সৃজনশীল</th>
            <th>নৈর্ব্যক্তিক</th>
            <th>ব্যবহারিক</th>
            <th>মোট</th>
        </tr>
    </thead>
    <tbody>
        <?php $i = 1; while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['subject_name']) ?></td>
                <td><?= $row['exam_date'] ?></td>
                <td><?= $row['exam_time'] ?></td>
                <td><?= $row['creative_marks'] ?></td>
                <td><?= $row['objective_marks'] ?></td>
                <td><?= $row['practical_marks'] ?></td>
                <td><?= $row['total_marks'] ?></td>
            </tr>
        <?php endwhile; ?>
    </tbody>
</table>
