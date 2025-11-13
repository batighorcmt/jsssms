<?php
@include_once __DIR__ . '/../includes/bootstrap.php';
session_start();
@include_once __DIR__ . '/../config/config.php';
// Allow both super_admin and teacher to use the finder
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin','teacher'])) {
    header('Location: ' . BASE_URL . 'auth/login.php');
    exit();
}
include '../config/db.php';

// Load plans for dropdown
$plans = [];
$rp = $conn->query('SELECT id, plan_name, shift FROM seat_plans ORDER BY id DESC');
if ($rp) { while($r=$rp->fetch_assoc()){ $plans[]=$r; } }

$plan_id = (int)($_GET['plan_id'] ?? 0);
$find = trim($_GET['find'] ?? '');
$results = [];
if ($plan_id>0 && $find !== ''){
    $q = $conn->real_escape_string($find);
    $cond = [];
    if (preg_match('/^\d+$/', $find)){
        $cond[] = "(CAST(COALESCE(s1.roll_no, s2.roll_no) AS CHAR) LIKE '{$q}%' OR CAST(a.student_id AS CHAR) LIKE '{$q}%')";
    } else {
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
            ORDER BY roll_no ASC LIMIT 20";
    $rs = @$conn->query($sql);
    if ($rs){ while($row=$rs->fetch_assoc()){ $results[]=$row; } }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h4>Find Student Seat</h4>
          <p class="text-muted mb-0">Search by Roll or Name inside a seat plan</p>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard<?= ($_SESSION['role']==='teacher' ? '_teacher' : '') ?>.php">Home</a></li>
            <li class="breadcrumb-item active">Find Seat</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-header d-flex align-items-center flex-wrap">
          <form class="form-inline w-100 w-md-auto">
            <div class="form-row align-items-end w-100">
              <div class="form-group col-md-4 col-12">
                <label class="d-block">Seat Plan</label>
                <select name="plan_id" class="form-control w-100" required>
                  <option value="">-- Select Plan --</option>
                  <?php foreach($plans as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" <?= $plan_id===(int)$p['id']?'selected':'' ?>>
                      <?= htmlspecialchars($p['plan_name']) ?> (<?= htmlspecialchars($p['shift']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group col-md-5 col-12">
                <label class="d-block">Find by Roll or Name</label>
                <input type="text" name="find" value="<?= htmlspecialchars($find) ?>" class="form-control w-100" placeholder="e.g., 102 or Hasan" required>
              </div>
              <div class="form-group col-md-3 col-12">
                <label class="d-none d-md-block">&nbsp;</label>
                <button class="btn btn-primary btn-block" type="submit"><i class="fas fa-search"></i> Find</button>
              </div>
            </div>
          </form>
        </div>
        <div class="card-body p-0">
          <?php if ($plan_id>0 && $find!==''): ?>
            <?php if (empty($results)): ?>
              <div class="p-3 text-muted">No assigned seat found for "<?= htmlspecialchars($find) ?>" in this plan.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0">
                  <thead class="thead-light">
                    <tr>
                      <th>Roll</th>
                      <th>Name</th>
                      <th>Class</th>
                      <th>Room</th>
                      <th>Column</th>
                      <th>Bench</th>
                      <th>Side</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($results as $row): ?>
                      <tr>
                        <td><?= htmlspecialchars($row['roll_no'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['student_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['class_name'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['room_no'] ?? '') ?></td>
                        <td><?= (int)($row['col_no'] ?? 0) ?></td>
                        <td><?= (int)($row['bench_no'] ?? 0) ?></td>
                        <td><?= (($row['position'] ?? '')==='R' ? 'Right' : 'Left') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="p-3 text-muted">Select a plan and search to find seats.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include '../includes/footer.php'; ?>
