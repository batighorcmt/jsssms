<?php
include '../config/db.php';

$exam_id = $_GET['exam_id'] ?? '';
$class_id = $_GET['class_id'] ?? '';
$year = $_GET['year'] ?? '';
if (!$exam_id || !$class_id || !$year) { echo "<div class='alert alert-danger container mt-4'>অনুগ্রহ করে পরীক্ষার ID, শ্রেণি ও সাল পাঠান GET মাধ্যমে।</div>"; exit; }

// Institute info
$institute_name = "Jorepukuria Secondary School";
$institute_address = "Gangni, Meherpur";
// Resolve app base URL path (e.g., /jsssms) for absolute asset URLs
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$appBasePath = rtrim(str_replace('\\','/', dirname(dirname($scriptName))), '/');
if ($appBasePath === '/') { $appBasePath = ''; }

// Try to load institute info (name/address/logo) from DB if table exists
$logo_src = '';
$__tblCheck = mysqli_query($conn, "SHOW TABLES LIKE 'institute_info'");
if ($__tblCheck && mysqli_num_rows($__tblCheck) > 0) {
  $__ii = mysqli_query($conn, "SELECT school_name, address, logo FROM institute_info ORDER BY id DESC LIMIT 1");
  if ($__ii) {
    $__ir = mysqli_fetch_assoc($__ii);
    if (!empty($__ir['school_name'])) { $institute_name = $__ir['school_name']; }
    if (!empty($__ir['address'])) { $institute_address = $__ir['address']; }
    $dbLogo = trim((string)($__ir['logo'] ?? ''));
    if ($dbLogo !== '') {
      $dbLogoFs = __DIR__ . '/../uploads/logo/' . $dbLogo;
      if (file_exists($dbLogoFs)) {
        $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode($dbLogo);
      }
    }
  }
}

// Determine logo file (filesystem) then build URL relative to app base; keep DB-provided $logo_src if present
if (empty($logo_src)) {
  $defaultLogo = '1757281102_jss_logo.png';
  $defaultLogoFs = __DIR__ . '/../uploads/logo/' . $defaultLogo;
  if (file_exists($defaultLogoFs)) {
    $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode($defaultLogo);
  } else {
    $pattern = __DIR__ . '/../uploads/logo/*.{png,jpg,jpeg,gif}';
    $candidates = glob($pattern, GLOB_BRACE);
    if ($candidates && count($candidates) > 0) {
      usort($candidates, function($a,$b){ return @filemtime($b) <=> @filemtime($a); });
      $logo_src = $appBasePath . '/uploads/logo/' . rawurlencode(basename($candidates[0]));
    }
  }
  // Final fallback to assets logo if uploads are missing
  if (empty($logo_src)) {
    $assetsLogoFs = __DIR__ . '/../assets/logo.png';
    if (file_exists($assetsLogoFs)) {
      $logo_src = $appBasePath . '/assets/logo.png';
    }
  }
}

// Exam and class
$examRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM exams WHERE id='".$conn->real_escape_string($exam_id)."'"));
$exam = $examRow['exam_name'] ?? '';
$subjects_without_fourth = isset($examRow['total_subjects_without_fourth']) ? (int)$examRow['total_subjects_without_fourth'] : 0;
$class = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_name FROM classes WHERE id='".$conn->real_escape_string($class_id)."'"))['class_name'];

// === Custom Subject Merging (keep consistent with tabulation) ===
$merged_subjects = [ 'Bangla' => ['101','102'], 'English' => ['107','108'] ];
$merged_marks = []; $individual_subjects = []; $excluded_subject_codes = ['101','102','107','108'];

// Load individual marks for merged groups
foreach ($merged_subjects as $group_name => $sub_codes) {
  $placeholders = implode(',', array_fill(0, count($sub_codes), '?'));
  $sql = "SELECT m.student_id, m.subject_id, s.subject_code, m.creative_marks, m.objective_marks, m.practical_marks
          FROM marks m JOIN subjects s ON m.subject_id=s.id JOIN students stu ON m.student_id=stu.student_id
          WHERE m.exam_id=? AND stu.class_id=? AND stu.year=? AND s.subject_code IN ($placeholders)";
  $stmt = $conn->prepare($sql);
  $types = 'sss' . str_repeat('s', count($sub_codes));
  $params = array_merge([$exam_id, $class_id, $year], $sub_codes);
  $stmt->bind_param($types, ...$params);
  $stmt->execute(); $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $sid = $row['student_id']; $code = (string)$row['subject_code'];
    if (!isset($individual_subjects[$sid][$code])) { $individual_subjects[$sid][$code] = ['creative'=>0,'objective'=>0,'practical'=>0]; }
    $individual_subjects[$sid][$code]['creative'] += (float)$row['creative_marks'];
    $individual_subjects[$sid][$code]['objective'] += (float)$row['objective_marks'];
    $individual_subjects[$sid][$code]['practical'] += (float)$row['practical_marks'];
    if (!isset($merged_marks[$sid][$group_name])) { $merged_marks[$sid][$group_name] = ['creative'=>0,'objective'=>0,'practical'=>0]; }
    $merged_marks[$sid][$group_name]['creative'] += (float)$row['creative_marks'];
    $merged_marks[$sid][$group_name]['objective'] += (float)$row['objective_marks'];
    $merged_marks[$sid][$group_name]['practical'] += (float)$row['practical_marks'];
  }
}

// Subjects list with exam configs
$subjectPassTypeSelect = "NULL AS subject_pass_type";
$__chk = mysqli_query($conn, "SHOW COLUMNS FROM subjects LIKE 'pass_type'");
if ($__chk && mysqli_num_rows($__chk) > 0) { $subjectPassTypeSelect = "s.pass_type AS subject_pass_type"; }
$subjects_sql = "
  SELECT s.id as subject_id, s.subject_name, s.subject_code, s.has_creative, s.has_objective, s.has_practical,
         es.creative_marks AS creative_full, es.objective_marks AS objective_full, es.practical_marks AS practical_full,
         es.creative_pass, es.objective_pass, es.practical_pass, es.total_marks as max_marks,
         gm.group_name, gm.class_id, gm.type AS subject_type, es.pass_type, $subjectPassTypeSelect
  FROM exam_subjects es JOIN subjects s ON es.subject_id=s.id JOIN subject_group_map gm ON gm.subject_id=s.id
  WHERE es.exam_id='".$conn->real_escape_string($exam_id)."' AND gm.class_id='".$conn->real_escape_string($class_id)."'
  ORDER BY FIELD(gm.type,'Compulsory','Optional'), s.subject_code";
$subjects_q = mysqli_query($conn, $subjects_sql);
$subjects = []; while ($row = mysqli_fetch_assoc($subjects_q)) { $subjects[] = $row; }
// Dedup prefer Compulsory
$dedup = [];
foreach ($subjects as $s) { $sid = $s['subject_id']; if (!isset($dedup[$sid])) $dedup[$sid]=$s; else { if (($dedup[$sid]['subject_type']??'')!=='Compulsory' && ($s['subject_type']??'')==='Compulsory') { $dedup[$sid]['subject_type']='Compulsory'; } } }
$subjects = array_values($dedup);

// Build display subjects incl merged Bangla/English
$display_subjects = []; $merged_pass_marks=[]; $merged_added=[]; $insert_after_codes=['Bangla'=>'102','English'=>'108'];
foreach ($subjects as $sub) {
  $code = $sub['subject_code'];
  $eff_has_creative = (!empty($sub['has_creative']) && intval($sub['creative_full']??0)>0)?1:0;
  $eff_has_objective = (!empty($sub['has_objective']) && intval($sub['objective_full']??0)>0)?1:0;
  $eff_has_practical = (!empty($sub['has_practical']) && intval($sub['practical_full']??0)>0)?1:0;
  $sub['has_creative']=$eff_has_creative; $sub['has_objective']=$eff_has_objective; $sub['has_practical']=$eff_has_practical;
  $display_subjects[] = array_merge($sub, ['is_merged'=>false, 'type'=>($sub['subject_type']??'Compulsory')]);
  foreach ($merged_subjects as $group_name=>$sub_codes) {
    if (in_array($code,$sub_codes,true) && $code === $insert_after_codes[$group_name] && !in_array($group_name,$merged_added,true)) {
      if (!isset($merged_pass_marks[$group_name])) {
        $creative_pass=0;$objective_pass=0;$practical_pass=0;$max_marks=0;$type='Compulsory';$merged_pass_type='total';
        $mf_creative_full=0;$mf_objective_full=0;$mf_practical_full=0;
        foreach ($subjects as $s) {
          if (in_array($s['subject_code'],$merged_subjects[$group_name],true)) {
            $creative_pass += (int)($s['creative_pass']??0); $objective_pass += (int)($s['objective_pass']??0); $practical_pass += (int)($s['practical_pass']??0);
            $max_marks += (int)($s['max_marks']??0); if (isset($s['subject_type'])) $type=$s['subject_type'];
            $mf_creative_full += (int)($s['creative_full']??0); $mf_objective_full += (int)($s['objective_full']??0); $mf_practical_full += (int)($s['practical_full']??0);
            $cmp_pass_raw = isset($s['pass_type'])?trim((string)$s['pass_type']):''; $cmp_pass_sub = isset($s['subject_pass_type'])?trim((string)$s['subject_pass_type']):'';
            $cmp_effective = $cmp_pass_raw!==''? strtolower($cmp_pass_raw) : ($cmp_pass_sub!==''? strtolower($cmp_pass_sub) : 'total');
            if ($cmp_effective==='individual') $merged_pass_type='individual';
          }
        }
        $merged_pass_marks[$group_name] = [
          'creative_pass'=>$creative_pass,'objective_pass'=>$objective_pass,'practical_pass'=>$practical_pass,'max_marks'=>$max_marks,'type'=>$type,'pass_type'=>$merged_pass_type,
          'has_creative'=>$mf_creative_full>0?1:0,'has_objective'=>$mf_objective_full>0?1:0,'has_practical'=>$mf_practical_full>0?1:0
        ];
      }
      $display_subjects[] = [ 'subject_name'=>$group_name, 'subject_code'=>$group_name, 'has_creative'=>$merged_pass_marks[$group_name]['has_creative'],
        'has_objective'=>$merged_pass_marks[$group_name]['has_objective'], 'has_practical'=>$merged_pass_marks[$group_name]['has_practical'],
        'creative_pass'=>$merged_pass_marks[$group_name]['creative_pass'], 'objective_pass'=>$merged_pass_marks[$group_name]['objective_pass'], 'practical_pass'=>$merged_pass_marks[$group_name]['practical_pass'],
        'max_marks'=>$merged_pass_marks[$group_name]['max_marks'], 'type'=>$merged_pass_marks[$group_name]['type'], 'pass_type'=>$merged_pass_marks[$group_name]['pass_type'], 'is_merged'=>true ];
      $merged_added[] = $group_name;
    }
  }
}
// Ensure optional after compulsory
$compulsory_list=[]; $optional_list=[]; foreach ($display_subjects as $ds){ $t=$ds['type']??'Compulsory'; if($t==='Optional') $optional_list[]=$ds; else $compulsory_list[]=$ds; }
$groupOrder=function($name){ $g=strtolower(trim($name??'')); if($g===''||$g==='none'||$g==='common'||$g==='all'||$g==='null') return 0; if($g==='science'||$g==='science group') return 1; if($g==='humanities'||$g==='arts') return 2; if($g==='business'||$g==='business studies'||$g==='commerce') return 3; return 4; };
usort($compulsory_list,function($a,$b) use($groupOrder){ $oa=$groupOrder($a['group_name']??''); $ob=$groupOrder($b['group_name']??''); if($oa!==$ob) return $oa<=>$ob; $ca=$a['subject_code']??''; $cb=$b['subject_code']??''; $isA=!empty($a['is_merged']); $isB=!empty($b['is_merged']); if($isA){ $sa=strtolower($a['subject_name']??''); $na=$sa==='bangla'?102.1:($sa==='english'?108.1:PHP_INT_MAX-0.5);} else { $na=is_numeric($ca)?(float)$ca:(float)PHP_INT_MAX;} if($isB){ $sb=strtolower($b['subject_name']??''); $nb=$sb==='bangla'?102.1:($sb==='english'?108.1:PHP_INT_MAX-0.5);} else { $nb=is_numeric($cb)?(float)$cb:(float)PHP_INT_MAX;} if($na!==$nb) return $na<=>$nb; return strcmp((string)($a['subject_name']??''),(string)($b['subject_name']??'')); });
$display_subjects=array_merge($compulsory_list,$optional_list);

// Assigned subjects and codes
$assignedByStudent=[]; $assignedCodesByStudent=[]; $subjectCodeById=[];
$assign_sql = "SELECT ss.student_id, ss.subject_id, sb.subject_code FROM student_subjects ss JOIN students st ON st.student_id=ss.student_id AND st.class_id='".$conn->real_escape_string($class_id)."' JOIN subjects sb ON sb.id=ss.subject_id";
$assign_res = mysqli_query($conn,$assign_sql);
if($assign_res){ while($ar=mysqli_fetch_assoc($assign_res)){ $sid=$ar['student_id']; if(!isset($assignedByStudent[$sid])){ $assignedByStudent[$sid]=[]; $assignedCodesByStudent[$sid]=[]; } $subId=(int)$ar['subject_id']; $subCode=(string)$ar['subject_code']; $assignedByStudent[$sid][$subId]=true; $assignedCodesByStudent[$sid][$subCode]=true; $subjectCodeById[$subId]=$subCode; } }

// Optional mapping
$optionalIdByStudent=[]; $colCheck=mysqli_query($conn,"SHOW COLUMNS FROM students LIKE 'optional_subject_id'");
if($colCheck && mysqli_num_rows($colCheck)>0){ $optIdRes=mysqli_query($conn,"SELECT student_id, optional_subject_id FROM students WHERE class_id='".$conn->real_escape_string($class_id)."' AND year='".$conn->real_escape_string($year)."' AND optional_subject_id IS NOT NULL"); if($optIdRes){ while($row=mysqli_fetch_assoc($optIdRes)){ $optionalIdByStudent[$row['student_id']]=(int)$row['optional_subject_id']; } } }
$effectiveTypeByStudent=[]; $optionalCodeByStudent=[];
$optSql = "SELECT st.student_id, s.id AS subject_id, s.subject_code,
           CASE WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0
                THEN CASE WHEN SUM(CASE WHEN gm.group_name COLLATE utf8mb4_unicode_ci = st.student_group COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0 THEN 'Optional' ELSE 'Compulsory' END
                ELSE CASE WHEN SUM(CASE WHEN UPPER(gm.group_name) COLLATE utf8mb4_unicode_ci = 'NONE' COLLATE utf8mb4_unicode_ci AND gm.type COLLATE utf8mb4_unicode_ci = 'Optional' COLLATE utf8mb4_unicode_ci THEN 1 ELSE 0 END) > 0 THEN 'Optional' ELSE 'Compulsory' END END AS effective_type
          FROM student_subjects ss JOIN students st ON st.student_id=ss.student_id AND st.class_id=? AND st.year=? JOIN subjects s ON s.id=ss.subject_id JOIN subject_group_map gm ON gm.subject_id=s.id AND gm.class_id=st.class_id GROUP BY st.student_id, s.id, s.subject_code";
$optStmt=$conn->prepare($optSql); $optStmt->bind_param('ss',$class_id,$year); $optStmt->execute(); $optRes=$optStmt->get_result();
while($r=$optRes->fetch_assoc()){ $effectiveTypeByStudent[$r['student_id']][(int)$r['subject_id']]=$r['effective_type']; }
foreach($effectiveTypeByStudent as $stuId=>$typeMap){ $optionalCandidates=[]; foreach($typeMap as $subId=>$etype){ if(strcasecmp((string)$etype,'Optional')===0){ $code=$subjectCodeById[$subId]??''; if($code!==''){ $optionalCandidates[$subId]=$code; } } } if(count($optionalCandidates)===1){ $optionalCodeByStudent[$stuId]=(string)array_values($optionalCandidates)[0]; } elseif(count($optionalCandidates)>1){ $chosenCode=''; $maxNum=-1; foreach($optionalCandidates as $code){ $num=is_numeric($code)?(int)$code:-1; if($num>$maxNum){ $maxNum=$num; $chosenCode=(string)$code; } } if($chosenCode!==''){ $optionalCodeByStudent[$stuId]=$chosenCode; } } }
if(!empty($optionalIdByStudent)){ foreach($optionalIdByStudent as $stuId=>$optSubId){ if(!empty($subjectCodeById[$optSubId])){ $optionalCodeByStudent[$stuId]=(string)$subjectCodeById[$optSubId]; } } }

// Utilities
function getMarks($student_id, $subject_id, $exam_id, $type){ global $conn; $stmt=$conn->prepare("SELECT {$type}_marks FROM marks WHERE student_id=? AND subject_id=? AND exam_id=?"); $stmt->bind_param('sss',$student_id,$subject_id,$exam_id); $stmt->execute(); $marks=0; $stmt->bind_result($marks); if($stmt->fetch()){ $val=(float)$marks; $stmt->close(); return $val; } $stmt->close(); return 0; }
function subjectGPA($marks,$full){ $pct=($full>0)?($marks/$full)*100:0; if($pct>=80) return 5.00; elseif($pct>=70) return 4.00; elseif($pct>=60) return 3.50; elseif($pct>=50) return 3.00; elseif($pct>=40) return 2.00; elseif($pct>=33) return 1.00; else return 0.00; }
function getPassStatus($class_id,$marks,$pass_marks,$pass_type='total'){ if(strtolower((string)$pass_type)==='total'){ $req=($pass_marks['creative']??0)+($pass_marks['objective']??0)+($pass_marks['practical']??0); $tot=($marks['creative']??0)+($marks['objective']??0)+($marks['practical']??0); return $tot>=$req; } else { if(isset($pass_marks['creative']) && isset($marks['creative']) && ($marks['creative']<$pass_marks['creative'])) return false; if(isset($pass_marks['objective']) && isset($marks['objective']) && ($marks['objective']<$pass_marks['objective'])) return false; if(isset($pass_marks['practical']) && isset($marks['practical']) && ($marks['practical']<$pass_marks['practical'])) return false; return true; } }

// Short name helper for long subject titles
function shortSubjectName($name){
  $n = trim((string)$name);
  $map = [
    'Information And Communication Technology' => 'ICT',
    'Information & Communication Technology' => 'ICT',
    'History Of Bangladesh And World Civilization' => 'History',
    'History of Bangladesh And World Civilization' => 'History',
    'Bangladesh and Global Studies' => 'BGS',
    'Higher Mathematics' => 'H. Math',
    'General Science' => 'Science',
  ];
  foreach($map as $long=>$short){
    if(strcasecmp($n,$long)===0){ return $short; }
  }
  return $n;
}

// Fetch students
$students_q = mysqli_query($conn, "SELECT * FROM students WHERE class_id = ".$conn->real_escape_string($class_id)." AND year = ".$conn->real_escape_string($year)." ORDER BY roll_no");
$students=[]; while($stu=mysqli_fetch_assoc($students_q)){ $students[]=$stu; }
$total_students = count($students);

// Aggregations
$overall_pass=0; $overall_fail=0; $gpa_sum=0.0; $gpa_max=0.0; $gpa_buckets=[ '5.00'=>0, '4.00-4.99'=>0, '3.50-3.99'=>0, '3.00-3.49'=>0, '2.00-2.99'=>0, '1.00-1.99'=>0, '0.00-0.99'=>0 ];
$group_stats=[]; // group_name => ['count'=>0,'pass'=>0]
$section_stats=[]; // section_id => ['name'=>..., 'count'=>0,'pass'=>0]
$sections_map=[]; $sres=mysqli_query($conn,"SELECT id, section_name FROM sections"); if($sres){ while($r=mysqli_fetch_assoc($sres)){ $sections_map[(int)$r['id']]=$r['section_name']; } }

$subject_stats=[]; // key by subject_code or group name; store totals, counts, highest, lowest, passes
foreach($display_subjects as $sub){ $key = !empty($sub['is_merged']) ? ($sub['subject_name'] ?? $sub['subject_code']) : ($sub['subject_code'] ?? ''); if($key==='') continue; $subject_stats[$key]=[ 'name'=>$sub['subject_name'] ?? (string)$key, 'code'=>$sub['subject_code'] ?? (string)$key, 'total'=>0, 'count'=>0, 'highest'=>-INF, 'lowest'=>INF, 'pass'=>0, 'fail'=>0, 'max_marks'=>(int)($sub['max_marks']??0), 'has_creative'=>!empty($sub['has_creative']), 'has_objective'=>!empty($sub['has_objective']), 'has_practical'=>!empty($sub['has_practical']), 'pass_type'=>strtolower((string)($sub['pass_type']??($sub['subject_pass_type']??'total'))), 'creative_pass'=>(int)($sub['creative_pass']??0), 'objective_pass'=>(int)($sub['objective_pass']??0), 'practical_pass'=>(int)($sub['practical_pass']??0), 'is_merged'=>!empty($sub['is_merged']) ]; }

$top_students=[]; // collect for top 10
$failed_students=[]; // collect failing students list

foreach($students as $stu){
  $stu_id=$stu['student_id']; $roll=(int)($stu['roll_no'] ?? 0); $group=trim((string)($stu['student_group'] ?? ''));
  if(!isset($group_stats[$group])) $group_stats[$group]=['count'=>0,'pass'=>0]; $group_stats[$group]['count']++;
  $sec_id = isset($stu['section_id']) ? (int)$stu['section_id'] : 0; $sec_name = $sections_map[$sec_id] ?? ''; if(!isset($section_stats[$sec_id])) $section_stats[$sec_id]=['name'=>$sec_name,'count'=>0,'pass'=>0]; $section_stats[$sec_id]['count']++;

  $total_marks=0; $fail_count=0; $comp_gpa_total=0; $comp_gpa_subjects=0; $optional_gpas=[]; $failed_list=[];
  foreach($display_subjects as $sub){
    $subject_code = $sub['subject_code'] ?? null; if(!$subject_code) continue;
    $skip=false; $c=0;$o=0;$p=0; $is_merged=!empty($sub['is_merged']);
    if($is_merged){ $groupName=$sub['subject_name']; $codes=$merged_subjects[$groupName]??[]; $hasAll=true; foreach($codes as $codeReq){ if(empty($assignedCodesByStudent[$stu_id][(string)$codeReq])){ $hasAll=false; break; } } if(!$hasAll){ $skip=true; } else { $c=$merged_marks[$stu_id][$groupName]['creative']??0; $o=$merged_marks[$stu_id][$groupName]['objective']??0; $p=$merged_marks[$stu_id][$groupName]['practical']??0; } }
    else { $sidSub=(int)($sub['subject_id'] ?? 0); if(empty($assignedByStudent[$stu_id][$sidSub])){ $skip=true; } else { $c=!empty($sub['has_creative'])? getMarks($stu_id,$sidSub,$exam_id,'creative'):0; $o=!empty($sub['has_objective'])? getMarks($stu_id,$sidSub,$exam_id,'objective'):0; $p=!empty($sub['has_practical'])? getMarks($stu_id,$sidSub,$exam_id,'practical'):0; } }
    if($skip) continue;

    $sub_total = $c+$o+$p;
    $key = $is_merged ? ($sub['subject_name'] ?? $subject_code) : $subject_code;
    if(!in_array($subject_code,$excluded_subject_codes,true)){
      $pm=[]; $mk=[]; if(!empty($sub['has_creative'])){ $pm['creative']=(int)($sub['creative_pass']??0); $mk['creative']=$c; }
      if(!empty($sub['has_objective'])){ $pm['objective']=(int)($sub['objective_pass']??0); $mk['objective']=$o; }
      if(!empty($sub['has_practical'])){ $pm['practical']=(int)($sub['practical_pass']??0); $mk['practical']=$p; }
      $pass_type=strtolower((string)($sub['pass_type']??($sub['subject_pass_type']??'total')));
      $isPass = getPassStatus($class_id,$mk,$pm,$pass_type);
      // Determine compulsory vs optional by chosen optional code (must do BEFORE counting fails)
      $opt_code = $optionalCodeByStudent[$stu_id] ?? '';
      $is_optional = (!$is_merged && $opt_code!=='' && (string)$subject_code === (string)$opt_code);
      if(!$isPass){
        // Ignore optional-subject fails from overall fail count and failed list
        if(!$is_optional){
          $fail_count++;
          $disp_name = !empty($sub['is_merged']) ? ($sub['subject_name'] ?? (string)$subject_code) : ($sub['subject_name'] ?? (string)$subject_code);
          $failed_list[] = shortSubjectName($disp_name);
        }
        // Subject-wise stats still reflect real pass/fail for analytics
        $subject_stats[$key]['fail']++;
      } else {
        $subject_stats[$key]['pass']++;
      }
      $gpa = $isPass ? subjectGPA($sub_total, (int)($sub['max_marks']??0)) : 0.00;
      $total_marks += $sub_total;
      $is_compulsory = !$is_optional;
      if($is_compulsory){ $comp_gpa_total += $gpa; $comp_gpa_subjects++; } else { $optional_gpas[]=$gpa; }
    } else {
      // Excluded paper (e.g., Bangla/English individual papers):
      // Do NOT add to student's grand total to avoid double-counting,
      // because the merged subject already includes these paper marks.
      // $total_marks += $sub_total;
    }

    // Subject aggregates
    if(isset($subject_stats[$key])){
      $subject_stats[$key]['total'] += $sub_total; $subject_stats[$key]['count']++; if($sub_total>$subject_stats[$key]['highest']) $subject_stats[$key]['highest']=$sub_total; if($sub_total<$subject_stats[$key]['lowest']) $subject_stats[$key]['lowest']=$sub_total;
    }
  }
  $optional_bonus=0.00; if(!empty($optional_gpas)){ $max_opt=max($optional_gpas); if($max_opt>2.00){ $optional_bonus=$max_opt-2.00; } }
  $divisor = ($subjects_without_fourth>0) ? min($subjects_without_fourth,$comp_gpa_subjects) : $comp_gpa_subjects;
  $final_gpa = ($divisor>0) ? (($comp_gpa_total + $optional_bonus)/$divisor) : 0.00; if($fail_count>0) $final_gpa=0.00; $final_gpa = min($final_gpa,5.00);
  $gpa_sum += $final_gpa; if($final_gpa>$gpa_max) $gpa_max=$final_gpa;
  if($fail_count===0){ $overall_pass++; $group_stats[$group]['pass']++; if(isset($section_stats[$sec_id])) $section_stats[$sec_id]['pass']++; } else { $overall_fail++; }
  // GPA bucket
  $g = $final_gpa+1e-8; if($g>=5.00) $gpa_buckets['5.00']++; elseif($g>=4.00) $gpa_buckets['4.00-4.99']++; elseif($g>=3.50) $gpa_buckets['3.50-3.99']++; elseif($g>=3.00) $gpa_buckets['3.00-3.49']++; elseif($g>=2.00) $gpa_buckets['2.00-2.99']++; elseif($g>=1.00) $gpa_buckets['1.00-1.99']++; else $gpa_buckets['0.00-0.99']++;

  $top_students[] = [ 'name'=>$stu['student_name']??'', 'roll'=>$roll, 'group'=>$group, 'total'=>$total_marks, 'gpa'=>number_format($final_gpa,2) ];
  if($fail_count>0){ $failed_students[] = [ 'name'=>$stu['student_name']??'', 'roll'=>$roll, 'group'=>$group, 'section'=>$sec_name, 'fail_count'=>$fail_count, 'fails'=>implode(', ', $failed_list) ]; }
}

$avg_gpa = ($total_students>0) ? number_format($gpa_sum/$total_students, 2) : '0.00';
$pass_rate = ($total_students>0) ? round(($overall_pass*100.0)/$total_students) : 0;

// Sort failing students by section (asc), then roll (asc)
usort($failed_students,function($a,$b){
  $sa = strtolower(trim($a['section'] ?? ''));
  $sb = strtolower(trim($b['section'] ?? ''));
  if($sa === '') $sa = 'zzz';
  if($sb === '') $sb = 'zzz';
  $cmp = strcmp($sa,$sb);
  if($cmp !== 0) return $cmp;
  return ($a['roll'] <=> $b['roll']);
});

// Build order map from display_subjects to match tabulation order
$order_map = [];
$__idx = 0;
foreach($display_subjects as $ds){
  $k = !empty($ds['is_merged']) ? ($ds['subject_name'] ?? ($ds['subject_code'] ?? '')) : ($ds['subject_code'] ?? '');
  if($k==='') continue; if(!isset($order_map[$k])){ $order_map[$k] = $__idx++; }
}

// Prepare subject rows with averages and pass/fail counts
$subject_rows = [];
foreach($subject_stats as $code=>$st){ if($st['count']<=0) continue; $avg = $st['total']/$st['count']; $pr = ($st['pass']+$st['fail'])>0 ? round(($st['pass']*100.0)/($st['pass']+$st['fail'])) : 0; $subject_rows[] = [ 'order'=>($order_map[$code] ?? 9999), 'name'=>$st['name'], 'code'=>$st['code'], 'avg'=>round($avg,2), 'high'=>($st['highest']===-INF?0:$st['highest']), 'low'=>($st['lowest']===INF?0:$st['lowest']), 'count'=>$st['count'], 'pass'=>$st['pass'], 'fail'=>$st['fail'], 'pass_rate'=>$pr, 'max'=>$st['max_marks'] ]; }
// Sort by display_subjects order
usort($subject_rows,function($a,$b){ return ($a['order']<=>$b['order']); });

// Top 10 students by total desc then roll asc
usort($top_students,function($a,$b){ return ($b['total']<=>$a['total']) ?: ($a['roll']<=>$b['roll']); });
$top_students = array_slice($top_students,0,10);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<title>বিস্তারিত পরিসংখ্যান - <?= htmlspecialchars($institute_name) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#0a0a0a; --muted:#374151; --border:#1f2937; --bg:#ffffff; --blue:#0ea5e9; --green:#22c55e; --red:#ef4444; --amber:#f59e0b; --violet:#8b5cf6; }
  *{ box-sizing:border-box }
  body{ margin:0; font-family:'Hind Siliguri',system-ui,Segoe UI,Roboto,Arial,sans-serif; color:var(--ink); background:var(--bg); }
  .actions{ position:sticky; top:0; z-index:10; background:#fff; border-bottom:1px solid var(--border); padding:8px; text-align:center }
  .btn{ background:var(--blue); color:#fff; border:none; border-radius:8px; padding:8px 14px; font-weight:800; cursor:pointer }
  .page{ width:100%; max-width:297mm; margin:0 auto; padding:0 }
  .header{ display:grid; grid-template-columns:80px 1fr; gap:12px; align-items:center; padding:6px 8px; border-bottom:1px solid var(--border) }
  .logo img{ width:72px; height:72px; object-fit:contain }
  .brand h1{ font-size:22px; margin:0; font-weight:800 }
  .brand .meta{ color:var(--muted); font-weight:700; margin-top:2px }
  .brand .exam{ font-weight:800; color:#111 }
  .brand .pagetitle{ margin-top:4px; font-size:18px; font-weight:900; color:#111 }

  .kpis{ display:grid; grid-template-columns: repeat(6, 1fr); gap:8px; margin:8px 0 }
  .kpi{ border:1px solid var(--border); border-radius:8px; padding:6px 8px }
  .kpi .label{ color:#111; font-weight:800; font-size:12px }
  .kpi .value{ font-weight:900; font-size:18px }

  .section{ margin:8px 0 }
  .section h3{ margin:6px 0; font-size:16px; font-weight:900; color:#111 }

  table{ width:100%; border-collapse:collapse }
  th,td{ border:1px solid var(--border); padding:6px 8px; font-size:12px; text-align:center }
  thead th{ background:#e5f3fb; font-weight:900; }
  td.left, th.left{ text-align:left }

  .bar{ height:12px; background:#e5e7eb; border-radius:6px; overflow:hidden }
  .bar > span{ display:block; height:100%; background:linear-gradient(90deg, var(--blue), #60a5fa); }
  .bar.green > span{ background:linear-gradient(90deg, var(--green), #86efac) }
  .bar.red > span{ background:linear-gradient(90deg, var(--red), #fca5a5) }
  .bar.amber > span{ background:linear-gradient(90deg, var(--amber), #fde68a) }
  .bar.violet > span{ background:linear-gradient(90deg, var(--violet), #c4b5fd) }

  .grid-2{ display:grid; grid-template-columns: 1fr 1fr; gap:8px }
  .grid-3{ display:grid; grid-template-columns: repeat(3, 1fr); gap:8px }

  /* Per-page print footer */
  .print-footer{ position: static; left:0; right:0; bottom:0; background:#fff; border-top:1px solid var(--border); padding:6px 8px; text-align:center; font-weight:900 }
  .spacer{ display:none }

  @media print{
    /* Print only the footer on the last page by keeping it in normal flow */
    @page{ size:A4 landscape; margin: 1in 0.5in 0.5in 0.5in }
    .actions{ display:none }
    .page{ padding:0; display:flex; flex-direction:column; min-height:100vh }
    .spacer{ display:block; flex:1 0 auto; page-break-inside: avoid }
    .print-footer{ position: static; display:block; margin-top:8px; padding:6px 8px; }
    .print-footer{ page-break-inside: avoid }
    html, body, table, th, td, thead, tbody{ -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  }
</style>
</head>
<body>
<div class="actions"><button class="btn" onclick="window.print()">প্রিন্ট করুন</button></div>
<div class="page">
  <div class="header">
    <div class="logo"><?php if (!empty($logo_src)): ?><img src="<?= htmlspecialchars($logo_src) ?>" alt="Logo" onerror="this.style.display='none'"><?php endif; ?></div>
    <div class="brand">
      <h1><?= htmlspecialchars($institute_name) ?></h1>
      <div class="meta"><?= htmlspecialchars($institute_address) ?></div>
      <div class="exam"><?= htmlspecialchars($exam) ?> - <?= htmlspecialchars($class) ?> (<?= htmlspecialchars($year) ?>)</div>
      <div class="pagetitle">বিস্তারিত পরিসংখ্যান</div>
    </div>
  </div>

  <div class="kpis">
    <div class="kpi"><div class="label">মোট শিক্ষার্থী</div><div class="value"><?= $total_students ?></div></div>
    <div class="kpi"><div class="label">পাস</div><div class="value" style="color:#16a34a"><?= $overall_pass ?></div></div>
    <div class="kpi"><div class="label">ফেল</div><div class="value" style="color:#dc2626"><?= $overall_fail ?></div></div>
    <div class="kpi"><div class="label">পাস হার</div><div class="value"><?= $pass_rate ?>%</div></div>
    <div class="kpi"><div class="label">গড় GPA</div><div class="value"><?= $avg_gpa ?></div></div>
    <div class="kpi"><div class="label">সর্বোচ্চ GPA</div><div class="value"><?= number_format($gpa_max,2) ?></div></div>
  </div>

  <div class="section">
    <h3>বিষয়ভিত্তিক সারাংশ</h3>
    <table>
      <thead><tr>
        <th class="left">বিষয় (কোড)</th>
        <th>গড়</th>
        <th>সর্বোচ্চ</th>
        <th>সর্বনিম্ন</th>
        <th>মোট</th>
        <th>পাস</th>
        <th>ফেল</th>
        <th>পাস হার</th>
        <th style="width:35%">চার্ট</th>
      </tr></thead>
      <tbody>
        <?php foreach ($subject_rows as $sr): $w = max(0,min(100,(int)$sr['pass_rate'])); ?>
          <tr>
            <td class="left"><?= htmlspecialchars($sr['name']) ?> <small>(<?= htmlspecialchars($sr['code']) ?>)</small></td>
            <td><?= htmlspecialchars($sr['avg']) ?></td>
            <td><?= htmlspecialchars($sr['high']) ?></td>
            <td><?= htmlspecialchars($sr['low']) ?></td>
            <td><?= (int)$sr['count'] ?></td>
            <td><?= (int)$sr['pass'] ?></td>
            <td><?= (int)$sr['fail'] ?></td>
            <td><?= htmlspecialchars($sr['pass_rate']) ?>%</td>
            <td>
              <div class="bar green"><span style="width: <?= $w ?>%"></span></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="grid-2">
    <div class="section">
      <h3>GPA বন্টন</h3>
      <table>
        <thead><tr><th class="left">রেঞ্জ</th><th>সংখ্যা</th><th style="width:60%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($gpa_buckets as $label=>$cnt): $w = ($total_students>0)? round(($cnt*100.0)/$total_students) : 0; ?>
            <tr>
              <td class="left"><?= htmlspecialchars($label) ?></td>
              <td><?= (int)$cnt ?></td>
              <td><div class="bar violet"><span style="width: <?= $w ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="section">
      <h3>গ্রুপভিত্তিক পাস হার</h3>
      <table>
        <thead><tr><th class="left">গ্রুপ</th><th>শিক্ষার্থী</th><th>পাস</th><th>পাস হার</th><th style="width:50%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($group_stats as $g=>$st): $cnt=$st['count']; if($cnt<=0) continue; $pr=round(($st['pass']*100.0)/$cnt); ?>
            <tr>
              <td class="left"><?= htmlspecialchars($g !== '' ? $g : 'N/A') ?></td>
              <td><?= (int)$cnt ?></td>
              <td><?= (int)$st['pass'] ?></td>
              <td><?= $pr ?>%</td>
              <td><div class="bar amber"><span style="width: <?= $pr ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="grid-2">
    <div class="section">
      <h3>শাখাভিত্তিক পাস হার</h3>
      <table>
        <thead><tr><th class="left">শাখা</th><th>শিক্ষার্থী</th><th>পাস</th><th>পাস হার</th><th style="width:50%">চার্ট</th></tr></thead>
        <tbody>
          <?php foreach ($section_stats as $sid=>$st): $cnt=$st['count']; if($cnt<=0) continue; $pr=round(($st['pass']*100.0)/$cnt); ?>
            <tr>
              <td class="left"><?= htmlspecialchars($st['name'] !== '' ? $st['name'] : 'N/A') ?></td>
              <td><?= (int)$cnt ?></td>
              <td><?= (int)$st['pass'] ?></td>
              <td><?= $pr ?>%</td>
              <td><div class="bar blue"><span style="width: <?= $pr ?>%"></span></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="section">
      <h3>শীর্ষ ১০ শিক্ষার্থী</h3>
      <table>
        <thead><tr><th>#</th><th class="left">নাম</th><th>রোল</th><th>গ্রুপ</th><th>মোট</th><th>GPA</th></tr></thead>
        <tbody>
          <?php $i=0; foreach ($top_students as $ts): $i++; ?>
            <tr>
              <td><?= $i ?></td>
              <td class="left"><?= htmlspecialchars($ts['name']) ?></td>
              <td><?= (int)$ts['roll'] ?></td>
              <td><?= htmlspecialchars($ts['group']) ?></td>
              <td><?= (int)$ts['total'] ?></td>
              <td><?= htmlspecialchars($ts['gpa']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="section">
    <h3>ফেল করা শিক্ষার্থীদের তালিকা</h3>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th class="left">শিক্ষার্থীর নাম</th>
          <th>রোল নং</th>
          <th>গ্রুপ</th>
          <th>শাখা</th>
          <th>ফেলের সংখ্যা</th>
          <th class="left">ফেলকৃত বিষয় সমূহ</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($failed_students)): ?>
          <tr>
            <td colspan="7">কোনো শিক্ষার্থী ফেল করেনি।</td>
          </tr>
        <?php else: $i=0; foreach ($failed_students as $fs): $i++; ?>
          <tr>
            <td><?= $i ?></td>
            <td class="left"><?= htmlspecialchars($fs['name']) ?></td>
            <td><?= (int)$fs['roll'] ?></td>
            <td><?= htmlspecialchars($fs['group'] ?? '') !== '' ? htmlspecialchars($fs['group']) : 'N/A' ?></td>
            <td><?= htmlspecialchars($fs['section']) !== '' ? htmlspecialchars($fs['section']) : 'N/A' ?></td>
            <td><?= (int)$fs['fail_count'] ?></td>
            <td class="left"><?= htmlspecialchars($fs['fails']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</div>
<div class="spacer"></div>
<div class="print-footer">কারিগরি সহযোগীতায়ঃ বাতিঘর কম্পিউটার’স</div>
</div>
</body>
</html>
