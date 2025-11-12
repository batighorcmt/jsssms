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
            <div class="mb-2 d-flex justify-content-between align-items-center">
                <div>
                    <a class="btn btn-sm btn-outline-secondary" href="seat_plan.php"><i class="fas fa-arrow-left"></i> Back to Plans</a>
                </div>
                <div></div>
            </div>
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong>Rooms in this Plan</strong>
                    <?php if (!empty($rooms)): ?>
                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="seat_plan_print_all.php?plan_id=<?= (int)$plan_id ?>"><i class="fas fa-print"></i> Print All Rooms</a>
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
