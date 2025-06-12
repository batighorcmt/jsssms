<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';

// SQL Query
$sql = "SELECT 
            e.id AS exam_id, 
            e.exam_name, 
            e.class_id, 
            c.class_name, 
            s.year 
        FROM exam_subjects es
        JOIN exams e ON es.exam_id = e.id
        JOIN classes c ON e.class_id = c.id
        JOIN marks m ON es.exam_id = m.exam_id
        JOIN students s ON m.student_id = s.id
        GROUP BY e.id, s.year
        ORDER BY s.year DESC, e.class_id ASC";

$result = mysqli_query($conn, $sql);
?>
<
<div class="d-flex">
<?php include '../includes/sidebar.php'; ?>

    <div class="container mt-4">
    <div class="container">
        <h3 class="text-center">পরীক্ষার তালিকা</h3>
        <table class="table table-bordered table-striped">
            <thead class="thead-dark">
                <tr>
                    <th>ক্রমিক</th>
                    <th>পরীক্ষার নাম</th>
                    <th>শ্রেণি</th>
                    <th>বছর</th>
                    <th>অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (mysqli_num_rows($result) > 0) {
                    $i = 1;
                    while ($row = mysqli_fetch_assoc($result)) {
                        echo "<tr>";
                        echo "<td>" . $i++ . "</td>";
                        echo "<td>" . htmlspecialchars($row['exam_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['class_name']) . "</td>";
                        echo "<td>" . htmlspecialchars($row['year']) . "</td>";
                        echo "<td>
                            <form action='tabulation_sheet.php' method='GET' target='_blank'>
                                <input type='hidden' name='exam_id' value='" . $row['exam_id'] . "'>
                                <input type='hidden' name='class_id' value='" . $row['class_id'] . "'>
                                <input type='hidden' name='year' value='" . $row['year'] . "'>
                                <button type='submit' class='btn btn-sm btn-success'>দেখুন</button>
                            </form>
                        </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='5' class='text-center'>কোনো তথ্য পাওয়া যায়নি।</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
    </div>
</body>
</html>
