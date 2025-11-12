<?php
@include_once __DIR__ . '/../config/config.php';
$ALLOWED_ROLES = ['super_admin', 'teacher'];
include __DIR__ . '/../auth/session.php';
include __DIR__ . '/../config/db.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

// Fetch created exams with class name and latest year (if any marks exist)
$sql = "SELECT e.id, e.exam_name, e.exam_type, e.class_id, c.class_name, e.created_at,
               y.latest_year
        FROM exams e
        JOIN classes c ON e.class_id = c.id
        LEFT JOIN (
          SELECT m.exam_id, MAX(s.year) AS latest_year
          FROM marks m
          JOIN students s ON m.student_id = s.id
          GROUP BY m.exam_id
        ) y ON y.exam_id = e.id
        ORDER BY e.id DESC";
$exams = $conn->query($sql);
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="bn">পরীক্ষার তালিকা</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Exam Results</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-body table-responsive p-0">
          <table class="table table-hover table-bordered mb-0">
            <thead class="thead-light">
              <tr>
                <th style="width:80px">ক্রমিক</th>
                <th>পরীক্ষার নাম</th>
                <th>শ্রেণি</th>
                <th>ধরন</th>
                <th style="width:120px">বছর</th>
                <th style="width:120px">তৈরির সময়</th>
                <th style="width:80px">একশন</th>
              </tr>
            </thead>
            <tbody>
              <?php $sl=1; if ($exams && $exams->num_rows>0): while($row=$exams->fetch_assoc()):
                $eid = (int)$row['id'];
                $cid = (int)$row['class_id'];
                // Prefer latest marks year if available; otherwise fall back to current year like manage_exams.php
                $latestYear = isset($row['latest_year']) ? trim((string)$row['latest_year']) : '';
                $year = $latestYear !== '' ? htmlspecialchars($latestYear) : date('Y');
                $hasYear = $year !== '';
                $tabUrl   = BASE_URL . 'results/tabulation_sheet.php?exam_id='.$eid.'&class_id='.$cid.'&year='.$year;
                $meritUrl = BASE_URL . 'results/merit_list.php?exam_id='.$eid.'&class_id='.$cid.'&year='.$year;
                $markUrl  = BASE_URL . 'results/marksheet.php?exam_id='.$eid.'&class_id='.$cid.'&year='.$year;
                $statsUrl = BASE_URL . 'results/statistics.php?exam_id='.$eid.'&class_id='.$cid.'&year='.$year;
              ?>
              <tr>
                <td><?= $sl++ ?></td>
                <td class="bn"><?= htmlspecialchars($row['exam_name']) ?></td>
                <td><?= htmlspecialchars($row['class_name']) ?></td>
                <td><?= htmlspecialchars($row['exam_type']) ?></td>
                <td><?= htmlspecialchars($year) ?></td>
                <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                <td class="text-center">
                  <div class="dropdown">
                    <button class="btn btn-sm btn-outline-primary dropdown-toggle" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                      <i class="fas fa-ellipsis-v"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right">
                      <a class="dropdown-item" href="<?= $tabUrl ?>">
                        <i class="fas fa-table mr-2"></i> ট্যাবুলেশন শীট
                      </a>
                      <a class="dropdown-item" href="<?= $meritUrl ?>">
                        <i class="fas fa-list-ol mr-2"></i> মেরিট লিস্ট
                      </a>
                      <a class="dropdown-item" href="<?= $markUrl ?>">
                        <i class="far fa-file-alt mr-2"></i> একাডেমিক ট্রান্সক্রিপ্ট (Marksheet)
                      </a>
                      <div class="dropdown-divider"></div>
                      <a class="dropdown-item" href="<?= $statsUrl ?>">
                        <i class="fas fa-chart-bar mr-2"></i> পরিসংখ্যান
                      </a>
                    </div>
                  </div>
                </td>
              </tr>
              <?php endwhile; else: ?>
                <tr><td colspan="7" class="text-center text-muted">কোনো পরীক্ষা তৈরি করা হয়নি</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
