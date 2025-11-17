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
if ($plan_id <= 0) { header('Location: seat_plan.php'); exit(); }

// Load plan
$plan = null;
$res = $conn->query('SELECT * FROM seat_plans WHERE id='.$plan_id);
if ($res && $res->num_rows>0) { $plan = $res->fetch_assoc(); }
if (!$plan) { header('Location: seat_plan.php'); exit(); }

// Actions
$toast = null;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    if ($action==='add_room'){
        $room_no = trim($_POST['room_no'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $columns_count = (int)($_POST['columns_count'] ?? 3);
        $col1 = (int)($_POST['col1_benches'] ?? 0);
        $col2 = (int)($_POST['col2_benches'] ?? 0);
        $col3 = (int)($_POST['col3_benches'] ?? 0);
        if ($room_no===''){
            $toast = ['type'=>'danger','msg'=>'Room number is required'];
        } else {
            $ins = $conn->prepare('INSERT INTO seat_plan_rooms (plan_id, room_no, title, columns_count, col1_benches, col2_benches, col3_benches) VALUES (?,?,?,?,?,?,?)');
            $ins->bind_param('issiiii', $plan_id, $room_no, $title, $columns_count, $col1, $col2, $col3);
            if (@$ins->execute()){
                $toast = ['type'=>'success','msg'=>'Room added'];
            } else {
                $toast = ['type'=>'danger','msg'=>'Could not add room (possibly duplicate room number).'];
            }
        }
    }
    if ($action==='update_room'){
        $room_id = (int)($_POST['room_id'] ?? 0);
        $room_no = trim($_POST['room_no'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $columns_count = max(1, min(3, (int)($_POST['columns_count'] ?? 3)));
        $col1 = max(0, (int)($_POST['col1_benches'] ?? 0));
        $col2 = max(0, (int)($_POST['col2_benches'] ?? 0));
        $col3 = max(0, (int)($_POST['col3_benches'] ?? 0));
        // Zero-out benches for hidden columns
        if ($columns_count < 3) { $col3 = 0; }
        if ($columns_count < 2) { $col2 = 0; }
        if ($room_id<=0 || $room_no===''){
            $toast = ['type'=>'danger','msg'=>'Room number is required'];
        } else {
            // Update room
            $up = $conn->prepare('UPDATE seat_plan_rooms SET room_no=?, title=?, columns_count=?, col1_benches=?, col2_benches=?, col3_benches=? WHERE id=? AND plan_id=?');
            if ($up) {
                $up->bind_param('ssiiiiii', $room_no, $title, $columns_count, $col1, $col2, $col3, $room_id, $plan_id);
                if (@$up->execute()){
                    // Prune allocations that exceed new structure
                    // 1) Remove columns beyond count
                    if ($del = $conn->prepare('DELETE FROM seat_plan_allocations WHERE room_id=? AND col_no>?')){
                        $del->bind_param('ii', $room_id, $columns_count);
                        @$del->execute();
                        $del->close();
                    }
                    // 2) Remove benches beyond per-column bench count
                    $benchMap = [1=>$col1, 2=>$col2, 3=>$col3];
                    foreach ($benchMap as $c=>$maxB) {
                        if ($maxB>0) {
                            if ($delb = $conn->prepare('DELETE FROM seat_plan_allocations WHERE room_id=? AND col_no=? AND bench_no>?')){
                                $delb->bind_param('iii', $room_id, $c, $maxB);
                                @$delb->execute();
                                $delb->close();
                            }
                        } else {
                            // If max benches is 0 for this col, remove all in that column
                            if ($delc = $conn->prepare('DELETE FROM seat_plan_allocations WHERE room_id=? AND col_no=?')){
                                $delc->bind_param('ii', $room_id, $c);
                                @$delc->execute();
                                $delc->close();
                            }
                        }
                    }
                    $toast = ['type'=>'success','msg'=>'Room updated'];
                } else {
                    $toast = ['type'=>'danger','msg'=>'Could not update room (possibly duplicate room number).'];
                }
                $up->close();
            } else {
                $toast = ['type'=>'danger','msg'=>'Prepare failed'];
            }
        }
    }
    if ($action==='delete_room'){
        $room_id = (int)($_POST['room_id'] ?? 0);
        if ($room_id>0){
            $conn->query('DELETE FROM seat_plan_allocations WHERE room_id='.$room_id);
            $conn->query('DELETE FROM seat_plan_rooms WHERE id='.$room_id.' AND plan_id='.$plan_id);
            $toast = ['type'=>'success','msg'=>'Room deleted'];
        }
    }
    if ($action==='clear_room'){
        $room_id = (int)($_POST['room_id'] ?? 0);
        if ($room_id>0){
            $conn->query('DELETE FROM seat_plan_allocations WHERE room_id='.$room_id);
            $toast = ['type'=>'success','msg'=>'Allocations cleared'];
        }
    }
}

// Load rooms
$rooms = [];
$rr = $conn->query('SELECT r.*, (SELECT COUNT(*) FROM seat_plan_allocations a WHERE a.room_id=r.id) AS assigned FROM seat_plan_rooms r WHERE r.plan_id='.$plan_id.' ORDER BY r.room_no ASC');
if ($rr) { while($row=$rr->fetch_assoc()){ $rooms[]=$row; } }

function room_capacity_local($r){
    $b = (int)($r['col1_benches'] ?? 0) + (int)($r['col2_benches'] ?? 0) + (int)($r['col3_benches'] ?? 0);
    return $b * 2;
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1>Seat Plan Rooms</h1>
                    <p class="text-muted mb-0">Plan: <strong><?= htmlspecialchars($plan['plan_name']) ?></strong> (Shift: <?= htmlspecialchars($plan['shift']) ?>)</p>
                </div>
                <div class="col-sm-6">
                    <ol class="breadcrumb float-sm-right">
                        <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
                        <li class="breadcrumb-item"><a href="seat_plan.php">Seat Plans</a></li>
                        <li class="breadcrumb-item active">Rooms</li>
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="content">
        <div class="container-fluid">
            <div class="mb-2 d-flex justify-content-between align-items-center flex-wrap">
                <div class="mb-2 mb-md-0">
                    <a class="btn btn-sm btn-outline-secondary" href="seat_plan.php"><i class="fas fa-arrow-left"></i> Back to Plans</a>
                </div>
                <div class="ml-md-auto">
                    <form class="form-inline" method="get" action="seat_plan_rooms.php">
                        <input type="hidden" name="plan_id" value="<?= (int)$plan_id ?>">
                        <div class="input-group input-group-sm">
                            <input type="text" name="find" value="<?= htmlspecialchars($_GET['find'] ?? '') ?>" class="form-control" placeholder="Find seat by Roll or Name">
                            <div class="input-group-append">
                                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i> Find</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php
            // Quick find: search assigned seat by roll/name within this plan
            $find = trim($_GET['find'] ?? '');
            if ($find !== '') {
                $q = $conn->real_escape_string($find);
                $cond = [];
                if (preg_match('/^\d+$/', $find)) {
                    // numeric: match roll or ids prefix
                    $cond[] = "(CAST(COALESCE(s1.roll_no, s2.roll_no) AS CHAR) LIKE '{$q}%' OR CAST(a.student_id AS CHAR) LIKE '{$q}%')";
                } else {
                    // text: name like
                    $cond[] = "(COALESCE(s1.student_name, s2.student_name) LIKE '%{$q}%')";
                }
                $where = implode(' AND ', $cond);
                $sql = "SELECT a.*, r.room_no, r.id AS room_id,
                        COALESCE(s1.student_name, s2.student_name) AS student_name,
                        COALESCE(s1.roll_no, s2.roll_no) AS roll_no,
                        COALESCE(c1.class_name, c2.class_name) AS class_name
                        FROM seat_plan_allocations a
                        JOIN seat_plan_rooms r ON r.id=a.room_id AND r.plan_id={$plan_id}
                        LEFT JOIN students s1 ON s1.student_id=a.student_id
                        LEFT JOIN students s2 ON s2.id=a.student_id
                        LEFT JOIN classes c1 ON c1.id = s1.class_id
                        LEFT JOIN classes c2 ON c2.id = s2.class_id
                        WHERE {$where}
                        ORDER BY a.id DESC LIMIT 1";
                $sr = @$conn->query($sql);
                if ($sr && $sr->num_rows > 0) { $S = $sr->fetch_assoc(); ?>
                    <div class="alert alert-info">
                        <strong>Seat found:</strong>
                        <span class="ml-2">Student: <strong><?= htmlspecialchars(($S['roll_no'] ? $S['roll_no'].' - ' : '').($S['student_name'] ?? '')) ?></strong> (<?= htmlspecialchars($S['class_name'] ?? '') ?>)</span>
                        <span class="ml-2">Room: <strong><?= htmlspecialchars($S['room_no']) ?></strong></span>
                        <span class="ml-2">Column: <strong><?= (int)$S['col_no'] ?></strong></span>
                        <span class="ml-2">Bench: <strong><?= (int)$S['bench_no'] ?></strong></span>
                        <span class="ml-2">Position: <strong><?= ($S['position']==='R'?'Right':'Left') ?></strong></span>
                        <a class="btn btn-sm btn-outline-primary ml-3" href="seat_plan_assign.php?plan_id=<?= (int)$plan_id ?>&room_id=<?= (int)$S['room_id'] ?>&highlight_c=<?= (int)$S['col_no'] ?>&highlight_b=<?= (int)$S['bench_no'] ?>&highlight_p=<?= urlencode($S['position']) ?>">
                            Open room & highlight
                        </a>
                    </div>
                <?php } else { ?>
                    <div class="alert alert-warning">No assigned seat found for "<?= htmlspecialchars($find) ?>" in this plan.</div>
                <?php }
            }
            ?>
            <?php if ($toast): ?>
            <div class="alert alert-<?= $toast['type'] ?>"><?= htmlspecialchars($toast['msg']) ?></div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header"><strong>Add Room</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="add_room">
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-3">
                                <label>Room No</label>
                                <input type="text" name="room_no" class="form-control" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Title (optional)</label>
                                <input type="text" name="title" class="form-control" placeholder="e.g., North Building 2nd Floor">
                            </div>
                            <div class="form-group col-md-1">
                                <label>Columns</label>
                                <select name="columns_count" id="columns_count" class="form-control">
                                    <option value="1">1</option>
                                    <option value="2">2</option>
                                    <option selected value="3">3</option>
                                </select>
                            </div>
                            <div class="form-group col-md-1 bench-group" data-col="1">
                                <label>Col 1 Benches</label>
                                <input type="number" name="col1_benches" id="col1_benches" class="form-control" min="0" value="5">
                            </div>
                            <div class="form-group col-md-1 bench-group" data-col="2">
                                <label>Col 2 Benches</label>
                                <input type="number" name="col2_benches" id="col2_benches" class="form-control" min="0" value="5">
                            </div>
                            <div class="form-group col-md-1 bench-group" data-col="3">
                                <label>Col 3 Benches</label>
                                <input type="number" name="col3_benches" id="col3_benches" class="form-control" min="0" value="5">
                            </div>
                        </div>
                        <button class="btn btn-success">Add Room</button>
                    </form>
                </div>
            </div>
            <?php
            $edit_room_id = isset($_GET['edit_room_id']) ? (int)$_GET['edit_room_id'] : 0;
            $editRoom = null;
            if ($edit_room_id>0) {
                $qr = $conn->query('SELECT * FROM seat_plan_rooms WHERE id='.(int)$edit_room_id.' AND plan_id='.(int)$plan_id.' LIMIT 1');
                if ($qr && $qr->num_rows>0) { $editRoom = $qr->fetch_assoc(); }
            }
            if ($editRoom):
            ?>
            <div class="card mb-3">
                <div class="card-header"><strong>Edit Room</strong> <small class="text-muted">(ID: <?= (int)$editRoom['id'] ?>)</small></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="update_room">
                        <input type="hidden" name="room_id" value="<?= (int)$editRoom['id'] ?>">
                        <div class="form-row align-items-end">
                            <div class="form-group col-md-3">
                                <label>Room No</label>
                                <input type="text" name="room_no" class="form-control" value="<?= htmlspecialchars($editRoom['room_no']) ?>" required>
                            </div>
                            <div class="form-group col-md-3">
                                <label>Title (optional)</label>
                                <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($editRoom['title'] ?? '') ?>" placeholder="e.g., North Building 2nd Floor">
                            </div>
                            <div class="form-group col-md-1">
                                <label>Columns</label>
                                <select name="columns_count" id="edit_columns_count" class="form-control">
                                    <option value="1" <?= (int)$editRoom['columns_count']===1?'selected':'' ?>>1</option>
                                    <option value="2" <?= (int)$editRoom['columns_count']===2?'selected':'' ?>>2</option>
                                    <option value="3" <?= (int)$editRoom['columns_count']===3?'selected':'' ?>>3</option>
                                </select>
                            </div>
                            <div class="form-group col-md-1 bench-group-edit" data-col="1">
                                <label>Col 1 Benches</label>
                                <input type="number" name="col1_benches" id="edit_col1_benches" class="form-control" min="0" value="<?= (int)$editRoom['col1_benches'] ?>">
                            </div>
                            <div class="form-group col-md-1 bench-group-edit" data-col="2">
                                <label>Col 2 Benches</label>
                                <input type="number" name="col2_benches" id="edit_col2_benches" class="form-control" min="0" value="<?= (int)$editRoom['col2_benches'] ?>">
                            </div>
                            <div class="form-group col-md-1 bench-group-edit" data-col="3">
                                <label>Col 3 Benches</label>
                                <input type="number" name="col3_benches" id="edit_col3_benches" class="form-control" min="0" value="<?= (int)$editRoom['col3_benches'] ?>">
                            </div>
                        </div>
                        <button class="btn btn-primary">Update Room</button>
                        <a class="btn btn-outline-secondary ml-2" href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>">Cancel</a>
                    </form>
                </div>
            </div>
            <script>
            (function(){
                var select = document.getElementById('edit_columns_count');
                if (!select) return;
                var groups = Array.prototype.slice.call(document.querySelectorAll('.bench-group-edit'));
                var inputs = {
                    1: document.getElementById('edit_col1_benches'),
                    2: document.getElementById('edit_col2_benches'),
                    3: document.getElementById('edit_col3_benches')
                };
                function applyColumns(){
                    var cols = parseInt(select.value, 10) || 0;
                    groups.forEach(function(g){
                        var c = parseInt(g.getAttribute('data-col'), 10) || 0;
                        var input = inputs[c];
                        if (c <= cols){
                            g.style.display = '';
                            if (input && input.dataset.prevValue){ input.value = input.dataset.prevValue; }
                        } else {
                            g.style.display = 'none';
                            if (input){ input.dataset.prevValue = input.value; input.value = 0; }
                        }
                    });
                }
                select.addEventListener('change', applyColumns);
                applyColumns();
            })();
            </script>
            <?php endif; ?>
            <script>
            (function(){
                var select = document.getElementById('columns_count');
                if (!select) return;
                var groups = Array.prototype.slice.call(document.querySelectorAll('.bench-group'));
                var inputs = {
                    1: document.getElementById('col1_benches'),
                    2: document.getElementById('col2_benches'),
                    3: document.getElementById('col3_benches')
                };
                function applyColumns(){
                    var cols = parseInt(select.value, 10) || 0;
                    groups.forEach(function(g){
                        var c = parseInt(g.getAttribute('data-col'), 10) || 0;
                        var input = inputs[c];
                        if (c <= cols){
                            g.style.display = '';
                            if (input && input.dataset.prevValue){
                                input.value = input.dataset.prevValue;
                            }
                        } else {
                            g.style.display = 'none';
                            if (input){
                                input.dataset.prevValue = input.value;
                                input.value = 0; // ensure server receives 0 for hidden columns
                            }
                        }
                    });
                }
                select.addEventListener('change', applyColumns);
                // Initialize on load
                applyColumns();
            })();
            </script>

            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <strong>Rooms in this Plan</strong>
                    <?php if (!empty($rooms)): ?>
                    <a class="btn btn-sm btn-outline-primary ml-auto" target="_blank" href="seat_plan_print_all.php?plan_id=<?= (int)$plan_id ?>"><i class="fas fa-print"></i> Print All Rooms</a>
                    <?php endif; ?>
                </div>
                <div class="card-body table-responsive p-0">
                    <table class="table table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>#</th>
                                <th>Room No</th>
                                <th>Title</th>
                                <th>Cols</th>
                                <th>Col1</th>
                                <th>Col2</th>
                                <th>Col3</th>
                                <th>Assigned</th>
                                <th>Capacity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rooms)): ?>
                            <tr><td colspan="10" class="text-center text-muted">No rooms yet.</td></tr>
                        <?php else: $i=1; $sumAssigned=0; $sumCapacity=0; foreach($rooms as $r): $cap=room_capacity_local($r); $sumAssigned += (int)$r['assigned']; $sumCapacity += $cap; ?>
                            <tr>
                                <td><?= $i++ ?></td>
                                <td><?= htmlspecialchars($r['room_no']) ?></td>
                                <td><?= htmlspecialchars($r['title'] ?? '') ?></td>
                                <td><?= (int)$r['columns_count'] ?></td>
                                <td><?= (int)$r['col1_benches'] ?></td>
                                <td><?= (int)$r['col2_benches'] ?></td>
                                <td><?= (int)$r['col3_benches'] ?></td>
                                <td><?= (int)$r['assigned'] ?></td>
                                <td><?= $cap ?></td>
                                <td>
                                    <a class="btn btn-sm btn-primary" href="seat_plan_assign.php?plan_id=<?= (int)$plan_id ?>&room_id=<?= (int)$r['id'] ?>">Assign Seats</a>
                                    <a class="btn btn-sm btn-secondary" target="_blank" href="seat_plan_print.php?plan_id=<?= (int)$plan_id ?>&room_id=<?= (int)$r['id'] ?>">Print</a>
                                    <a class="btn btn-sm btn-info" href="seat_plan_rooms.php?plan_id=<?= (int)$plan_id ?>&edit_room_id=<?= (int)$r['id'] ?>">Edit</a>
                                    <form method="post" action="" style="display:inline" onsubmit="return confirm('Clear all allocations in this room?');">
                                        <input type="hidden" name="action" value="clear_room">
                                        <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-warning">Clear</button>
                                    </form>
                                    <form method="post" action="" style="display:inline" onsubmit="return confirm('Delete this room? This will remove allocations too.');">
                                        <input type="hidden" name="action" value="delete_room">
                                        <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                                        <button class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                        <?php if (!empty($rooms)): ?>
                        <tfoot>
                            <tr class="font-weight-bold">
                                <td colspan="7" class="text-right">Totals</td>
                                <td><?= (int)$sumAssigned ?></td>
                                <td><?= (int)$sumCapacity ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
