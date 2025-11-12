<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}
include '../config/db.php';

$plan_id = (int)($_GET['plan_id'] ?? 0);
$room_id = (int)($_GET['room_id'] ?? 0);
if ($plan_id<=0 || $room_id<=0) { header('Location: seat_plan.php'); exit(); }

// Load plan, room, classes
$plan = null; $room = null; $classes = [];
$rp = $conn->query('SELECT * FROM seat_plans WHERE id='.$plan_id);
if ($rp && $rp->num_rows>0) $plan = $rp->fetch_assoc();
$rr = $conn->query('SELECT * FROM seat_plan_rooms WHERE id='.$room_id.' AND plan_id='.$plan_id);
if ($rr && $rr->num_rows>0) $room = $rr->fetch_assoc();
$rc = $conn->query('SELECT c.id, c.class_name FROM seat_plan_classes pc JOIN classes c ON c.id=pc.class_id WHERE pc.plan_id='.$plan_id.' ORDER BY c.id');
if ($rc) { while($r=$rc->fetch_assoc()){ $classes[]=$r; } }
if (!$plan || !$room) { header('Location: seat_plan.php'); exit(); }

$columns = max(1, min(3, (int)($room['columns_count'] ?? 3)));
$colBenches = [1=>(int)$room['col1_benches'], 2=>(int)$room['col2_benches'], 3=>(int)$room['col3_benches']];

$toast = null;
if ($_SERVER['REQUEST_METHOD']==='POST'){
    $action = $_POST['action'] ?? '';
    if ($action==='assign'){
        $student_id = (int)($_POST['student_id'] ?? 0);
        $col_no = (int)($_POST['col_no'] ?? 0);
        $bench_no = (int)($_POST['bench_no'] ?? 0);
        $position = ($_POST['position'] ?? 'L')==='R' ? 'R' : 'L';
        if ($student_id>0 && $col_no>0 && $bench_no>0) {
            // Check existing assignment in this plan
            $chk = $conn->prepare('SELECT 1 FROM seat_plan_allocations WHERE plan_id=? AND student_id=? LIMIT 1');
            $chk->bind_param('ii', $plan_id, $student_id);
            $chk->execute(); $chk->store_result();
            if ($chk->num_rows>0){
                $toast = ['type'=>'danger','msg'=>'This student is already assigned in this plan.'];
            } else {
                // Upsert by seat: delete if exists then insert
                $conn->query('DELETE FROM seat_plan_allocations WHERE room_id='.$room_id.' AND col_no='.$col_no.' AND bench_no='.$bench_no.' AND position=\''.$conn->real_escape_string($position).'\'');
                $ins = $conn->prepare('INSERT INTO seat_plan_allocations (plan_id, room_id, student_id, col_no, bench_no, position) VALUES (?,?,?,?,?,?)');
                $ins->bind_param('iiiiss', $plan_id, $room_id, $student_id, $col_no, $bench_no, $position);
                if (@$ins->execute()){
                    $toast = ['type'=>'success','msg'=>'Seat assigned'];
                } else {
                    $toast = ['type'=>'danger','msg'=>'Assignment failed'];
                }
            }
        }
    }
    if ($action==='unassign'){
        $col_no = (int)($_POST['col_no'] ?? 0);
        $bench_no = (int)($_POST['bench_no'] ?? 0);
        $position = ($_POST['position'] ?? 'L')==='R' ? 'R' : 'L';
        if ($col_no>0 && $bench_no>0){
            $conn->query('DELETE FROM seat_plan_allocations WHERE room_id='.$room_id.' AND col_no='.$col_no.' AND bench_no='.$bench_no.' AND position=\''.$conn->real_escape_string($position).'\'');
            $toast = ['type'=>'success','msg'=>'Seat cleared'];
        }
    }
}

// Load current allocations (with basic student info)
$alloc = [];
$aa = $conn->query('SELECT a.*, 
    COALESCE(s1.student_name, s2.student_name) AS student_name, 
    COALESCE(s1.roll_no, s2.roll_no) AS roll_no,
    COALESCE(c1.class_name, c2.class_name) AS class_name
    FROM seat_plan_allocations a 
    LEFT JOIN students s1 ON s1.student_id=a.student_id 
    LEFT JOIN students s2 ON s2.id=a.student_id 
    LEFT JOIN classes c1 ON c1.id = s1.class_id
    LEFT JOIN classes c2 ON c2.id = s2.class_id
    WHERE a.room_id='.$room_id);
if ($aa){ while($row=$aa->fetch_assoc()){ $alloc[$row['col_no'].'-'.$row['bench_no'].'-'.$row['position']]=$row; } }

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Assign Seats</h1>
                    <p class="text-muted mb-0">Plan: <strong><?= htmlspecialchars($plan['plan_name']) ?></strong> — Room: <strong><?= htmlspecialchars($room['room_no']) ?></strong> (Shift: <?= htmlspecialchars($plan['shift']) ?>)</p>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="seat_plan.php">Seat Plans</a></li>
                        <li class="breadcrumb-item"><a href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>">Rooms</a></li>
                        <li class="breadcrumb-item active">Assign</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="mb-2 d-flex justify-content-between align-items-center">
                <div>
                    <a class="btn btn-sm btn-outline-secondary" href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>"><i class="fas fa-arrow-left"></i> Back to Rooms</a>
                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="seat_plan_print.php?plan_id=<?= (int)$plan_id ?>&room_id=<?= (int)$room_id ?>"><i class="fas fa-print"></i> Print This Room</a>
                </div>
                <div>
                    <a class="btn btn-sm btn-outline-dark" href="seat_plan.php"><i class="fas fa-th-large"></i> All Seat Plans</a>
                </div>
            </div>
            <?php if ($toast): ?>
            <div class="alert alert-<?= $toast['type'] ?>"><?= htmlspecialchars($toast['msg']) ?></div>
            <?php endif; ?>
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header"><strong>Room Layout</strong></div>
                        <div class="card-body">
                            <div id="grid" class="d-flex" style="gap:16px;">
                                <?php for($c=1;$c<=$columns;$c++): $maxB=$colBenches[$c]??0; ?>
                                <div class="flex-fill" style="min-width:220px;">
                                    <div class="text-center font-weight-bold mb-2">Column <?= $c ?> (<?= $maxB ?> benches)</div>
                                    <?php for($b=1;$b<=$maxB;$b++): $kL=$c.'-'.$b.'-L'; $kR=$c.'-'.$b.'-R'; $L=$alloc[$kL]??null; $R=$alloc[$kR]??null; ?>
                                    <div class="d-flex align-items-center mb-2" style="border:1px dashed #bbb; padding:6px;">
                                        <div class="seat-cell p-2 mr-2 flex-fill border" data-c="<?= $c ?>" data-b="<?= $b ?>" data-p="L" style="cursor:pointer; background:#f8f9fa;">
                                            <div class="small text-muted">L</div>
                                            <?php if($L): ?>
                                                <div class="seat-roll"><strong><?= htmlspecialchars($L['roll_no'] ?? '') ?></strong></div>
                                                <div class="seat-name"><?= htmlspecialchars($L['student_name'] ?? '') ?></div>
                                                <div class="seat-class text-muted">Class: <?= htmlspecialchars($L['class_name'] ?? '') ?></div>
                                                <form method="post" class="mt-1">
                                                    <input type="hidden" name="action" value="unassign">
                                                    <input type="hidden" name="col_no" value="<?= $c ?>">
                                                    <input type="hidden" name="bench_no" value="<?= $b ?>">
                                                    <input type="hidden" name="position" value="L">
                                                    <button class="btn btn-xs btn-outline-danger">Clear</button>
                                                </form>
                                            <?php else: ?>
                                                <em class="text-muted">Empty</em>
                                            <?php endif; ?>
                                        </div>
                                        <div class="seat-cell p-2 flex-fill border" data-c="<?= $c ?>" data-b="<?= $b ?>" data-p="R" style="cursor:pointer; background:#f8f9fa;">
                                            <div class="small text-muted">R</div>
                                            <?php if($R): ?>
                                                <div class="seat-roll"><strong><?= htmlspecialchars($R['roll_no'] ?? '') ?></strong></div>
                                                <div class="seat-name"><?= htmlspecialchars($R['student_name'] ?? '') ?></div>
                                                <div class="seat-class text-muted">Class: <?= htmlspecialchars($R['class_name'] ?? '') ?></div>
                                                <form method="post" class="mt-1">
                                                    <input type="hidden" name="action" value="unassign">
                                                    <input type="hidden" name="col_no" value="<?= $c ?>">
                                                    <input type="hidden" name="bench_no" value="<?= $b ?>">
                                                    <input type="hidden" name="position" value="R">
                                                    <button class="btn btn-xs btn-outline-danger">Clear</button>
                                                </form>
                                            <?php else: ?>
                                                <em class="text-muted">Empty</em>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <div class="mt-2 text-muted">Tip: Click a seat to open the assign modal, then choose class and student.</div>
                        </div>
                    </div>
                </div>
                                <!-- Right column removed: replaced by seat-click modal workflow -->
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
<!-- Select2 CSS/JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.6.2/dist/select2-bootstrap4.min.css">
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.full.min.js"></script>
<style>
    /* Make Select2 look consistent in modal with Bootstrap 4 */
    .select2-container{ width:100% !important; }
    .select2-container--bootstrap4 .select2-selection--single{ height: calc(2.25rem + 2px); padding:.375rem .75rem; }
    .select2-container--bootstrap4 .select2-selection__arrow{ height: calc(2.25rem + 2px); right:.75rem; }
    .modal .form-group { margin-bottom: .9rem; }
    .select2-results__option { font-size: 14px; }
    .select2-search__field{ outline: none; }
    .select2-dropdown { z-index: 2050; }
    /* Seat cells hover */
    .seat-cell:hover{ background:#fff3cd !important; }
    .seat-cell.bg-warning{ background:#ffe8a1 !important; }
    .seat-cell em{ color:#999; }
    /* Assigned seat details */
    .seat-roll{ font-size: 22px; font-weight: 900; color:#b00020; line-height:1; }
    .seat-name{ font-size: 13px; font-weight: 700; line-height:1.1; }
    .seat-class{ font-size: 16px; }
</style>

<!-- Assign Modal -->
<div class="modal fade" id="assignModal" tabindex="-1" role="dialog" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalLabel">Assign Student to Seat</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="assignForm" method="post">
                    <input type="hidden" name="action" value="assign">
                    <input type="hidden" name="col_no" id="m_col_no" value="">
                    <input type="hidden" name="bench_no" id="m_bench_no" value="">
                    <input type="hidden" name="position" id="m_position" value="">

                    <div class="form-group">
                        <label>Class</label>
                        <select id="modal_class_id" class="form-control" required>
                            <option value="">-- Select --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Student</label>
                        <select id="student_select" name="student_id" class="form-control" style="width:100%" required disabled></select>
                        <small class="form-text text-muted"></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                <button type="submit" form="assignForm" class="btn btn-primary">Assign</button>
            </div>
        </div>
    </div>
    </div>
<script>
let selectedSeat = null;
function markSelection(el){
    document.querySelectorAll('.seat-cell').forEach(x=>x.classList.remove('bg-warning'));
    el.classList.add('bg-warning');
}
document.querySelectorAll('.seat-cell').forEach(el=>{
    el.addEventListener('click', function(){
                selectedSeat = { c: this.dataset.c, b: this.dataset.b, p: this.dataset.p };
                markSelection(this);
                // Fill modal hidden inputs
                document.getElementById('m_col_no').value = selectedSeat.c;
                document.getElementById('m_bench_no').value = selectedSeat.b;
                document.getElementById('m_position').value = selectedSeat.p;
                // Reset selects
                $('#modal_class_id').val('').trigger('change');
                $('#student_select').val(null).trigger('change');
                $('#assignModal').modal('show');
    });
});
// Prevent modal opening when clicking the small Clear buttons inside a seat
document.querySelectorAll('.seat-cell form button').forEach(function(btn){
    btn.addEventListener('click', function(e){ e.stopPropagation(); });
});
// Initialize Select2 with AJAX search, excluding already-assigned by plan (server-side)
$(function(){
        var $student = $('#student_select');
    $student.select2({
        theme: 'bootstrap4',
        width: '100%',
        dropdownParent: $('#assignModal'),
        placeholder: 'শিক্ষার্থী নির্বাচন করুন',
        allowClear: true,
        minimumInputLength: 0,
        ajax: {
            delay: 250,
            url: 'seat_plan_search_students.php',
            dataType: 'json',
            data: function(params){
                return {
                    plan_id: <?= (int)$plan_id ?>,
                    class_id: $('#modal_class_id').val() || '',
                    q: params.term || ''
                };
            },
            processResults: function(data){
                var results = (Array.isArray(data) ? data : []).map(function(s){
                    var id = s.student_id || s.id;
                    var roll = s.roll_no || '';
                    var name = s.student_name || '';
                    // Format: (রোল - নাম)
                    return { id: id, text: (roll ? roll : '') + (roll && name ? ' - ' : (name? '' : '')) + (name? name : '') };
                });
                return { results: results };
            }
        },
                templateResult: function (data) {
                    if (!data.id) { return data.text; }
                    var text = data.text || '';
                    // Ensure only (Roll - Name) is shown; collapse extra spaces
                    text = String(text).replace(/\s+/g,' ').trim();
                    return $('<span>').text(text);
                },
                templateSelection: function (data) {
                    var text = data.text || '';
                    text = String(text).replace(/\s+/g,' ').trim();
                    return text;
                },
        language: {
          noResults: function(){ return 'কোন ফলাফল নেই'; },
          searching: function(){ return 'খোঁজা হচ্ছে…'; }
        }
    });

    // When class changes, clear previous selection and open the dropdown to auto-load list
        $('#modal_class_id').on('change', function(){
            $student.val(null).trigger('change');
            // enable only when class is selected
            if (this.value) { $student.prop('disabled', false); }
            else { $student.prop('disabled', true); }
            // Force a query without term by opening; select2 will fetch with empty q
            if (this.value) setTimeout(function(){
                $student.select2('open');
                // focus search box for typing immediately
                let search = document.querySelector('.select2-container--bootstrap4 .select2-search__field');
                if (search) search.focus();
            }, 80);
        });

    // When modal opens, if class selected, open dropdown, else focus class select
    $('#assignModal').on('shown.bs.modal', function(){
        if ($('#modal_class_id').val()) {
            $student.select2('open');
            let search = document.querySelector('.select2-container--bootstrap4 .select2-search__field');
            if (search) search.focus();
        } else {
            $('#modal_class_id').focus();
        }
    });
});
</script>
