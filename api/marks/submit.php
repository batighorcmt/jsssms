<?php
require_once __DIR__ . '/../bootstrap.php';
require_method('POST');
api_require_auth(['teacher','super_admin']);
$conn->query("CREATE TABLE IF NOT EXISTS marks (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  exam_id INT NOT NULL,
  student_id INT NOT NULL,
  subject_id INT NOT NULL,
  creative_marks DECIMAL(6,2) NULL,
  objective_marks DECIMAL(6,2) NULL,
  practical_marks DECIMAL(6,2) NULL,
  UNIQUE KEY uniq_mark (exam_id, student_id, subject_id)
) ENGINE=InnoDB");
// Ensure UNIQUE KEY exists even if table was created earlier without it
$hasUnique = false;
if ($idxRes = $conn->query("SHOW INDEX FROM marks WHERE Key_name='uniq_mark'")) {
  $hasUnique = ($idxRes->num_rows > 0);
}
if (!$hasUnique) {
  // Best-effort add unique composite index to prevent duplicates
  @$conn->query("ALTER TABLE marks ADD UNIQUE KEY uniq_mark (exam_id, student_id, subject_id)");
}

$body = read_json_body();
$exam_id = (int)($body['exam_id'] ?? 0);
$subject_id = (int)($body['subject_id'] ?? 0);
$marks = $body['marks'] ?? [];
if ($exam_id<=0 || $subject_id<=0) api_response(false,'exam_id and subject_id required',400);
if (!is_array($marks)) api_response(false,'marks must be array',400);

// Teacher permission: must be assigned in exam_subjects and honor deadline
if ($authUser['role'] === 'teacher') {
  // Resolve current teacher id by login username (contact number)
  $teacher_id = 0;
  if ($t = $conn->prepare('SELECT id FROM teachers WHERE contact=? LIMIT 1')) {
    $t->bind_param('s', $authUser['username']);
    $t->execute();
    $t->bind_result($teacher_id);
    $t->fetch();
    $t->close();
  }

  // Load assignment + deadline for this exam/subject (match web logic)
  $assigned_teacher_id = null; $deadline = null;
  if ($st = $conn->prepare('SELECT teacher_id, mark_entry_deadline FROM exam_subjects WHERE exam_id=? AND subject_id=? LIMIT 1')) {
    $st->bind_param('ii', $exam_id, $subject_id);
    $st->execute();
    $st->bind_result($assigned_teacher_id, $deadline);
    $st->fetch();
    $st->close();
  }

  // If teacher not resolvable OR not assigned, block (same as web)
  if (empty($teacher_id) || empty($assigned_teacher_id) || intval($teacher_id) !== intval($assigned_teacher_id)) {
    api_response(false, 'Not assigned to this subject', 403);
  }

  // Enforce deadline if present
  if (!empty($deadline)) {
    $today = date('Y-m-d');
    if ($today > $deadline) {
      api_response(false, 'Your mark entry deadline has passed. Contact Admin.', 403);
    }
  }
}

$saved=0;$failed=0;

// Upsert statement (idempotent)
$upsert = $conn->prepare('INSERT INTO marks (exam_id, student_id, subject_id, creative_marks, objective_marks, practical_marks)
  VALUES (?,?,?,?,?,?)
  ON DUPLICATE KEY UPDATE
    creative_marks=COALESCE(VALUES(creative_marks), creative_marks),
    objective_marks=COALESCE(VALUES(objective_marks), objective_marks),
    practical_marks=COALESCE(VALUES(practical_marks), practical_marks)');
if (!$upsert) api_response(false,['error'=>'prepare_failed','db_error'=>$conn->error],500);

foreach ($marks as $m){
  $sid=(int)($m['student_id'] ?? 0);
  $single = null;
  if (isset($m['marks']) && is_numeric($m['marks'])) $single = (float)$m['marks'];
  elseif (isset($m['marks_obtained']) && is_numeric($m['marks_obtained'])) $single = (float)$m['marks_obtained'];
  $creative = isset($m['creative']) && is_numeric($m['creative']) ? (float)$m['creative'] : null;
  $objective = isset($m['objective']) && is_numeric($m['objective']) ? (float)$m['objective'] : null;
  $practical = isset($m['practical']) && is_numeric($m['practical']) ? (float)$m['practical'] : null;
  if ($single !== null && $creative===null && $objective===null && $practical===null) { $creative = $single; $objective = 0.0; $practical = 0.0; }
  if ($sid<=0) { $failed++; continue; }
  if ($creative===null) $creative=0.0; if ($objective===null) $objective=0.0; if ($practical===null) $practical=0.0;

  $upsert->bind_param('iiiddd',$exam_id,$sid,$subject_id,$creative,$objective,$practical);
  if ($upsert->execute()) {
    $saved++;
  } else {
    // Fallback: try UPDATE first, then INSERT
    $upd = $conn->prepare('UPDATE marks SET creative_marks=?, objective_marks=?, practical_marks=? WHERE exam_id=? AND student_id=? AND subject_id=?');
    if ($upd) {
      $upd->bind_param('dddiii',$creative,$objective,$practical,$exam_id,$sid,$subject_id);
      if ($upd->execute() && $upd->affected_rows>0) { $saved++; $upd->close(); continue; }
      $upd->close();
    }
    $ins = $conn->prepare('INSERT INTO marks (exam_id, student_id, subject_id, creative_marks, objective_marks, practical_marks) VALUES (?,?,?,?,?,?)');
    if ($ins) {
      $ins->bind_param('iiiddd',$exam_id,$sid,$subject_id,$creative,$objective,$practical);
      if ($ins->execute()) { $saved++; $ins->close(); continue; }
      $ins->close();
    }
    $failed++;
  }
}
if ($failed>0 && $saved===0) {
  api_response(false,['error'=>'save_failed','db_error'=>$conn->error,'saved'=>$saved,'failed'=>$failed],500);
}
api_response(true,['saved'=>$saved,'failed'=>$failed]);
?>