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
$hl_c = (int)($_GET['highlight_c'] ?? 0);
$hl_b = (int)($_GET['highlight_b'] ?? 0);
$hl_p = ($_GET['highlight_p'] ?? '') === 'R' ? 'R' : (($_GET['highlight_p'] ?? '') === 'L' ? 'L' : '');

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
            <div class="mb-2 d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <a class="btn btn-sm btn-outline-secondary" href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>"><i class="fas fa-arrow-left"></i> Back to Rooms</a>
                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="seat_plan_print.php?plan_id=<?= (int)$plan_id ?>&room_id=<?= (int)$room_id ?>"><i class="fas fa-print"></i> Print This Room</a>
                </div>
                <div>
                    <a class="btn btn-sm btn-outline-dark" href="seat_plan.php"><i class="fas fa-th-large"></i> All Seat Plans</a>
                </div>
            </div>
            <?php /* Toasts are shown via JS as top-right popups; no inline alert here. */ ?>
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header"><strong>Room Layout</strong></div>
                        <div class="card-body">
                            <div id="grid" class="d-flex" style="gap:16px;">
                                <?php for($c=1;$c<=$columns;$c++): $maxB=$colBenches[$c]??0; ?>
                                <div class="seat-column flex-fill" style="min-width:220px;">
                                    <div class="text-center font-weight-bold mb-2">Column <?= $c ?> (<?= $maxB ?> benches)</div>
                                    <?php for($b=1;$b<=$maxB;$b++): $kL=$c.'-'.$b.'-L'; $kR=$c.'-'.$b.'-R'; $L=$alloc[$kL]??null; $R=$alloc[$kR]??null; ?>
                                    <div class="bench-row d-flex align-items-center mb-2" style="border:1px dashed #bbb; padding:6px;">
                                        <div class="seat-cell p-2 mr-2 flex-fill border" data-c="<?= $c ?>" data-b="<?= $b ?>" data-p="L" style="cursor:pointer; background:#f8f9fa;">
                                            <div class="small text-muted">L</div>
                                            <?php if($L): ?>
                                                <div class="seat-roll"><strong><?= htmlspecialchars($L['roll_no'] ?? '') ?></strong></div>
                                                <div class="seat-name"><?= htmlspecialchars($L['student_name'] ?? '') ?></div>
                                                <div class="seat-class text-muted">Class: <?= htmlspecialchars($L['class_name'] ?? '') ?></div>
                                                <button type="button" class="btn btn-xs btn-outline-danger mt-1 js-clear-seat" data-c="<?= $c ?>" data-b="<?= $b ?>" data-p="L">Clear</button>
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
                                                <button type="button" class="btn btn-xs btn-outline-danger mt-1 js-clear-seat" data-c="<?= $c ?>" data-b="<?= $b ?>" data-p="R">Clear</button>
                                            <?php else: ?>
                                                <em class="text-muted">Empty</em>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endfor; ?>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <div class="mt-2 text-muted">Tip: Click a seat, choose class, then click a student name to assign instantly.</div>
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
    /* Show up to 10 items in the Select2 dropdown; scroll for more */
    #assignModal .select2-results__options { max-height: 360px; overflow-y: auto; }
    /* Keep dropdown within modal viewport */
    #assignModal .select2-container .select2-dropdown { max-height: 400px; }
    /* Seat cells hover */
    .seat-cell:hover{ background:#fff3cd !important; }
    .seat-cell.bg-warning{ background:#ffe8a1 !important; }
    .seat-cell em{ color:#999; }
    /* Assigned seat details */
    .seat-roll{ font-size: 22px; font-weight: 900; color:#b00020; line-height:1; }
    .seat-name{ font-size: 13px; font-weight: 700; line-height:1.1; }
    .seat-class{ font-size: 16px; }

    /* Top-right toast notifications */
    .app-toast-container{ position:fixed; top:16px; right:16px; z-index:3000; display:flex; flex-direction:column; gap:10px; }
    .app-toast{ min-width: 260px; max-width: 380px; background:#343a40; color:#fff; padding:10px 14px; border-radius:4px; box-shadow:0 6px 20px rgba(0,0,0,.2); opacity:0; transform:translateX(12px); transition:opacity .2s ease, transform .2s ease; font-weight:600; letter-spacing:.2px; }
    .app-toast.show{ opacity:1; transform:translateX(0); }
    .app-toast--success{ background:#28a745; }
    .app-toast--danger{ background:#dc3545; }
    .app-toast--warning{ background:#ffc107; color:#212529; }

    /* Responsive adjustments */
    #grid{ flex-wrap: wrap; }
    @media (max-width: 767.98px){
        .content-header h1{ font-size: 1.3rem; }
        .seat-column{ min-width: 100% !important; }
        /* Keep both ends of a bench side-by-side on mobile */
        .bench-row{ flex-wrap: nowrap; }
        .bench-row .seat-cell{ flex: 0 0 calc(50% - 4px); }
        .bench-row .mr-2{ margin-right: 8px !important; }
        .seat-roll{ font-size: 18px; }
        .seat-class{ font-size: 14px; }
        .card .btn{ margin-top: 6px; }
        .mb-2.d-flex.flex-wrap > div{ margin-top: 6px; }
        /* Toast: make it nearly full-width */
        .app-toast-container{ left: 8px; right: 8px; align-items: stretch; }
        .app-toast{ max-width: none; width: 100%; }
    }

    /* Highlight animation for located seat */
    .seat-highlight{ box-shadow: 0 0 0 3px rgba(23,162,184,.45) inset, 0 0 0 2px rgba(23,162,184,.6); animation: seatPulse 1.2s ease-in-out 3; }
    @keyframes seatPulse{ 0%{ background:#e8f7fa; } 50%{ background:#d1f0f7; } 100%{ background:#e8f7fa; } }
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
                        <select id="student_select" name="student_id" class="form-control" style="width:100%" data-placeholder="-- Select Student --" required disabled></select>
                        <small class="form-text text-muted"></small>
                    </div>
                </form>
            </div>
            <div class="modal-footer d-none"><!-- Buttons removed: assign happens on select --></div>
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
    el.addEventListener('click', function(e){
                // If the Clear button inside this seat was clicked, don't open the modal
                if (e && e.target && e.target.closest && e.target.closest('.js-clear-seat')) { return; }
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
// Initialize Select2 with AJAX search, excluding already-assigned by plan (server-side)
$(function(){
        var $student = $('#student_select');
    $student.select2({
        theme: 'bootstrap4',
        width: '100%',
        dropdownParent: $('#assignModal'),
        placeholder: $('#student_select').attr('data-placeholder') || 'Select student',
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
                    return { id: id, text: (roll ? roll : '') + (roll && name ? ' - ' : (name? '' : '')) + (name? name : '') };
                });
                return { results: results };
            }
        },
        language: {
          noResults: function(){ return 'No results'; },
          searching: function(){ return 'Searching…'; }
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

    // Auto-assign on select: submit form immediately when a student is selected
    $student.on('select2:select', function(e){
        var data = e.params && e.params.data ? e.params.data : null;
        var sid = data ? data.id : $('#student_select').val();
        var c = document.getElementById('m_col_no').value;
        var b = document.getElementById('m_bench_no').value;
        var p = document.getElementById('m_position').value;
        if (!sid || !c || !b || !p){ return; }
        // AJAX assign to avoid page reload
        fetch('seat_plan_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                action: 'assign',
                plan_id: '<?= (int)$plan_id ?>',
                room_id: '<?= (int)$room_id ?>',
                student_id: sid,
                col_no: c,
                bench_no: b,
                position: p
            })
        }).then(r=>r.json()).then(function(res){
            if (!res || !res.success){
                showToast('danger', (res && res.message) ? res.message : 'Assign failed');
                return;
            }
            // Update seat cell DOM
            var sel = '.seat-cell[data-c="'+c+'"][data-b="'+b+'"][data-p="'+p+'"]';
            var cell = document.querySelector(sel);
            if (cell){
                renderSeatAssigned(cell, res.data);
            }
            $('#assignModal').modal('hide');
            showToast('success', 'Seat assigned');
        }).catch(function(){ showToast('danger', 'Assign failed'); });
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

    // If highlight params are provided, highlight and scroll to the seat
    <?php if ($hl_c>0 && $hl_b>0 && $hl_p!==''): ?>
    setTimeout(function(){
        var sel = '.seat-cell[data-c="<?= (int)$hl_c ?>"][data-b="<?= (int)$hl_b ?>"][data-p="<?= $hl_p ?>"]';
        var el = document.querySelector(sel);
        if (el){
            el.classList.add('seat-highlight');
            el.scrollIntoView({ behavior:'smooth', block:'center' });
            showToast('info', 'Highlighted seat: C<?= (int)$hl_c ?>-B<?= (int)$hl_b ?>-<?= $hl_p ?>', 2200);
        }
    }, 300);
    <?php endif; ?>
    // Unassign via AJAX
    document.addEventListener('click', function(e){
        var btn = e.target.closest && e.target.closest('.js-clear-seat');
        if (!btn) return;
        e.preventDefault();
        // Ensure this click does not bubble to seat-cell and open the modal
        e.stopPropagation();
        var c = btn.getAttribute('data-c');
        var b = btn.getAttribute('data-b');
        var p = btn.getAttribute('data-p');
        // disable button while processing
        var prevHtml = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Clearing…';
        fetch('seat_plan_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({
                action: 'unassign',
                plan_id: '<?= (int)$plan_id ?>',
                room_id: '<?= (int)$room_id ?>',
                col_no: c,
                bench_no: b,
                position: p
            })
        }).then(r=>r.json()).then(function(res){
            if (!res || !res.success){ showToast('danger', (res && res.message) ? res.message : 'Clear failed'); return; }
            // Update the seat cell to empty state
            var sel = '.seat-cell[data-c="'+c+'"][data-b="'+b+'"][data-p="'+p+'"]';
            var cell = document.querySelector(sel);
            if (cell){ renderSeatEmpty(cell); }
            showToast('success', 'Seat cleared');
        }).catch(function(){ showToast('danger', 'Clear failed'); })
        .finally(function(){ if (btn){ btn.disabled = false; btn.innerHTML = prevHtml; }});
    });
});

// Helpers to render seat cell content (assigned / empty) without reload
function renderSeatAssigned(cell, data){
    if (!cell) return;
    var roll = (data && data.roll_no) ? String(data.roll_no) : '';
    var name = (data && data.student_name) ? String(data.student_name) : '';
    var cls = (data && data.class_name) ? String(data.class_name) : '';
    var c = cell.getAttribute('data-c');
    var b = cell.getAttribute('data-b');
    var p = cell.getAttribute('data-p');
    // Build inner HTML
    var html = '';
    html += '<div class="small text-muted">'+ (p==='R'?'R':'L') +'</div>';
    html += '<div class="seat-roll"><strong>'+ escapeHtml(roll) +'</strong></div>';
    html += '<div class="seat-name">'+ escapeHtml(name) +'</div>';
    html += '<div class="seat-class text-muted">Class: '+ escapeHtml(cls) +'</div>';
    html += '<button type="button" class="btn btn-xs btn-outline-danger mt-1 js-clear-seat" data-c="'+c+'" data-b="'+b+'" data-p="'+p+'">Clear</button>';
    cell.innerHTML = html;
}
function renderSeatEmpty(cell){
    if (!cell) return;
    var p = cell.getAttribute('data-p');
    var html = '';
    html += '<div class="small text-muted">'+ (p==='R'?'R':'L') +'</div>';
    html += '<em class="text-muted">Empty</em>';
    cell.innerHTML = html;
}
function escapeHtml(s){
    return String(s||'').replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]); });
}
</script>

<!-- Toast helper and auto-show from PHP $toast -->
<script>
(function(){
    function ensureToastContainer(){
        var c = document.getElementById('app-toast-container');
        if (!c){
            c = document.createElement('div');
            c.id = 'app-toast-container';
            c.className = 'app-toast-container';
            document.body.appendChild(c);
        }
        return c;
    }
    window.showToast = function(type, message, duration){
        try{
            duration = duration || 2500;
            var c = ensureToastContainer();
            var t = document.createElement('div');
            var cls = 'app-toast';
            if (type === 'success') cls += ' app-toast--success';
            else if (type === 'warning') cls += ' app-toast--warning';
            else cls += ' app-toast--danger';
            t.className = cls;
            t.setAttribute('role','alert');
            t.textContent = message || '';
            c.appendChild(t);
            requestAnimationFrame(function(){ t.classList.add('show'); });
            setTimeout(function(){
                t.classList.remove('show');
                setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 300);
            }, duration);
        } catch(e) {
            console && console.warn && console.warn('Toast error', e);
        }
    };
    <?php if ($toast): ?>
    document.addEventListener('DOMContentLoaded', function(){
        showToast('<?= $toast['type'] ?>', '<?= htmlspecialchars($toast['msg'], ENT_QUOTES) ?>', 2500);
    });
    <?php endif; ?>
})();
</script>
