<?php
$total_marks = 0;
$total_gpa = 0;
$subject_count = 0;
$failed = false;

$subject_data = [];
$merged_subjects = [
    'Bangla' => ['101', '102'],
    'English' => ['107', '108']
];

$marks_q = mysqli_query($conn, "
  SELECT s.id as subject_id, s.subject_name, s.subject_code,
         m.creative_marks, m.objective_marks, m.practical_marks,
         es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks,
         s.has_creative, s.has_objective, s.has_practical, es.pass_type
  FROM marks m
  JOIN subjects s ON m.subject_id = s.id
  JOIN subject_group_map gm ON gm.subject_id = s.id
  JOIN exam_subjects es ON es.subject_id = s.id AND es.exam_id = m.exam_id
  WHERE m.student_id='$student_id' AND m.exam_id='$exam_id'
  ORDER BY s.subject_code
");

while ($row = mysqli_fetch_assoc($marks_q)) {
    $code = $row['subject_code'];
    $subject_data[$code] = $row;
}

function calculateGPA($percent) {
    if ($percent >= 80) return 5.00;
    if ($percent >= 70) return 4.00;
    if ($percent >= 60) return 3.50;
    if ($percent >= 50) return 3.00;
    if ($percent >= 40) return 2.00;
    if ($percent >= 33) return 1.00;
    return 0.00;
}

function renderSubjectRow($subject_name, $codes, $subject_data, $is_english = false) {
    global $total_marks, $total_gpa, $subject_count, $failed;

    $creative = $objective = $practical = $total = 0;
    $has_creative = $has_objective = $has_practical = false;
    $pass = true;
    $total_full_marks = 0;

    foreach ($codes as $code) {
        if (!isset($subject_data[$code])) continue;
        $d = $subject_data[$code];
        $creative += $d['creative_marks'];
        $objective += $d['objective_marks'];
        $practical += $d['practical_marks'];

        $has_creative |= $d['has_creative'];
        $has_objective |= $d['has_objective'];
        $has_practical |= $d['has_practical'];

        $total_full_marks += $d['total_marks'];
    }

    $total = $creative + $objective + $practical;

    if ($is_english) {
        $average = $creative / count($codes);
        $first = $subject_data[$codes[0]];
        $pass = $average >= $first['creative_pass'];
    } else {
        foreach ($codes as $code) {
            if (!isset($subject_data[$code])) continue;
            $d = $subject_data[$code];
            if ($d['pass_type'] == 'total') {
                $req = $d['creative_pass'] + $d['objective_pass'] + $d['practical_pass'];
                if (($d['creative_marks'] + $d['objective_marks'] + $d['practical_marks']) < $req) $pass = false;
            } else {
                if (($d['has_creative'] && $d['creative_marks'] < $d['creative_pass']) ||
                    ($d['has_objective'] && $d['objective_marks'] < $d['objective_pass']) ||
                    ($d['has_practical'] && $d['practical_marks'] < $d['practical_pass'])) {
                    $pass = false;
                }
            }
        }
    }

    if (!$pass) $failed = true;

    $gpa = $pass ? calculateGPA(($total / $total_full_marks) * 100) : 0.00;
    $total_marks += $total;
    $total_gpa += $gpa;
    $subject_count++;

    echo "<tr>
      <td>" . implode(",", $codes) . "</td>
      <td>$subject_name</td>
      <td>$creative</td>
      <td>$objective</td>
      <td>$practical</td>
      <td>$total</td>
      <td>" . number_format($gpa, 2) . "</td>
      <td>" . ($pass ? "<span class='pass'>Pass</span>" : "<span class='fail'>Fail</span>") . "</td>
    </tr>";
}

foreach ($merged_subjects as $merged_name => $codes) {
    renderSubjectRow($merged_name, $codes, $subject_data, $merged_name == 'English');
    foreach ($codes as $code) unset($subject_data[$code]);
}

foreach ($subject_data as $code => $d) {
    renderSubjectRow($d['subject_name'], [$code], $subject_data);
}

$final_gpa = $failed ? 0.00 : number_format($total_gpa / $subject_count, 2);
echo "<tr class='table-secondary'>
  <td colspan='5' class='text-end'><strong>Total Marks</strong></td>
  <td><strong>$total_marks</strong></td>
  <td colspan='2'><strong>GPA: " . number_format($final_gpa, 2) . "</strong></td>
</tr>";
?>
