<?php
require_once __DIR__ . '/../bootstrap.php';
api_require_auth(['teacher','super_admin']);

$exam_id = (int)($_GET['exam_id'] ?? 0);
$class_id = (int)($_GET['class_id'] ?? 0);
if ($exam_id<=0 || $class_id<=0) api_response(false,'exam_id and class_id required',400);

// If teacher restrict to subjects assigned in exam_subjects
$subjects=[];
if ($authUser['role']==='teacher') {
  // Find teacher_id from users table relation to teachers.contact
  $teacher_id=0; $t = $conn->prepare('SELECT id FROM teachers WHERE contact=? LIMIT 1'); if ($t){ $t->bind_param('s',$authUser['username']); $t->execute(); $t->bind_result($teacher_id); $t->fetch(); $t->close(); }
  $hasTeacherCol=false; $cchk = $conn->query("SHOW COLUMNS FROM exam_subjects LIKE 'teacher_id'"); if ($cchk && $cchk->num_rows>0) $hasTeacherCol=true;
  if ($hasTeacherCol && $teacher_id>0){
    $sql = "SELECT s.id, s.subject_name, es.subject_id, es.exam_id
            FROM exam_subjects es
            JOIN subjects s ON s.id=es.subject_id
            WHERE es.exam_id=? AND s.status='Active' AND es.teacher_id=?";
    if ($st = $conn->prepare($sql)) {
      $st->bind_param('ii',$exam_id,$teacher_id); $st->execute(); $res=$st->get_result();
      while($r=$res->fetch_assoc()) $subjects[]=['subject_id'=>(int)$r['id'],'subject_name'=>$r['subject_name']];
      $st->close();
    }
  } else {
    // Fallback: list all active subjects mapped to class via subject_group_map
    $rs = $conn->prepare('SELECT s.id, s.subject_name FROM subjects s JOIN subject_group_map gm ON gm.subject_id=s.id WHERE gm.class_id=? AND s.status="Active"');
    if ($rs){ $rs->bind_param('i',$class_id); $rs->execute(); $re=$rs->get_result(); while($r=$re->fetch_assoc()) $subjects[]=['subject_id'=>(int)$r['id'],'subject_name'=>$r['subject_name']]; $rs->close(); }
  }
} else { // admin
  $rs = $conn->prepare('SELECT s.id, s.subject_name FROM subjects s JOIN subject_group_map gm ON gm.subject_id=s.id WHERE gm.class_id=? AND s.status="Active"');
  if ($rs){ $rs->bind_param('i',$class_id); $rs->execute(); $re=$rs->get_result(); while($r=$re->fetch_assoc()) $subjects[]=['subject_id'=>(int)$r['id'],'subject_name'=>$r['subject_name']]; $rs->close(); }
}

api_response(true,['exam_id'=>$exam_id,'class_id'=>$class_id,'subjects'=>$subjects]);
?>