<?php
// FCM Admin / Diagnostics page
// Provides: token list, send logs, test send, delete token, purge stale
// Access: super_admin only
@include_once __DIR__ . '/../includes/bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
@include_once __DIR__ . '/../config/config.php';
@include_once __DIR__ . '/../config/db.php';
@include_once __DIR__ . '/../api/lib/fcm.php';
if (!defined('BASE_URL')) { define('BASE_URL','../'); }
if (($_SESSION['role'] ?? '') !== 'super_admin') { header('Location: ' . BASE_URL . 'auth/forbidden.php'); exit; }

// Load Service Account details for health check display
$saPath = __DIR__ . '/../config/firebase_service_account.json';
$sa = null;
if (is_file($saPath)) {
    $sa = json_decode(file_get_contents($saPath), true);
}

$toast = null;
// Ensure logs table (may not exist yet)
$GLOBALS['conn']->query("CREATE TABLE IF NOT EXISTS fcm_send_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  token TEXT NULL,
  title TEXT NULL,
  body TEXT NULL,
  data_payload TEXT NULL,
  http_code INT NULL,
  response_body TEXT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user (user_id)
)");

// Actions
$action = $_POST['action'] ?? ''; $conn = $GLOBALS['conn'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($action === 'delete_token') {
    $tid = (int)($_POST['token_id'] ?? 0);
    if ($tid>0 && $conn->query('DELETE FROM fcm_tokens WHERE id=' . $tid)) {
      $toast = ['type'=>'success','msg'=>'Token deleted'];
    } else { $toast = ['type'=>'error','msg'=>'Delete failed']; }
  }
  if ($action === 'purge_stale') {
    $days = (int)($_POST['days'] ?? 90); if ($days<1) $days=90;
    $cut = date('Y-m-d H:i:s', time() - ($days*86400));
    $stmt = $conn->prepare('DELETE FROM fcm_tokens WHERE updated_at < ?');
    if ($stmt) { $stmt->bind_param('s', $cut); $stmt->execute(); $aff=$stmt->affected_rows; $stmt->close(); $toast=['type'=>'success','msg'=>"Purged $aff stale tokens (<$cut)" ]; }
  }
  if ($action === 'test_send') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if ($uid>0) {
      $res = fcm_send_to_user($conn, $uid, 'Admin Test', 'This is a test push from admin panel', ['type'=>'admin_test']);
      $okCount = 0; if (!empty($res['results'])) { foreach($res['results'] as $r){ if (!empty($r['ok'])) $okCount++; } }
      $toast=['type'=> ($okCount>0?'success':'warning'), 'msg'=>'Test sent. Success tokens: '.$okCount];
    } else { $toast=['type'=>'error','msg'=>'Invalid user id']; }
  }
  if ($action === 'purge_send_logs') {
    $keep = (int)($_POST['keep'] ?? 5000); if ($keep<100) $keep=100;
    // Delete all except latest $keep by id
    $conn->query("DELETE FROM fcm_send_logs WHERE id NOT IN (SELECT id FROM (SELECT id FROM fcm_send_logs ORDER BY id DESC LIMIT $keep) AS t)");
    $toast=['type'=>'success','msg'=>'Send logs trimmed to latest '.$keep];
  }
}

// Filters
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$showFailuresOnly = isset($_GET['failures']) && $_GET['failures']=='1';

// Load tokens with user info
$tokens = [];
$tokenSql = "SELECT ft.id, ft.user_id, LEFT(ft.token,120) AS token_short, ft.updated_at, u.username, u.role, t.name AS teacher_name
             FROM fcm_tokens ft
             LEFT JOIN users u ON u.id=ft.user_id
             LEFT JOIN teachers t ON t.contact=u.username
             ORDER BY ft.updated_at DESC LIMIT 200";
if ($rt = $conn->query($tokenSql)) { while($row=$rt->fetch_assoc()){ $tokens[]=$row; } }

// Load send logs (latest 200)
$logs = [];
$logWhere = [];
if ($filterUser>0) $logWhere[] = 'user_id='.(int)$filterUser;
if ($showFailuresOnly) $logWhere[] = 'success=0';
$whereClause = empty($logWhere)? '' : ('WHERE '.implode(' AND ',$logWhere));
$logSql = "SELECT id, user_id, http_code, success, LEFT(response_body,150) AS resp_snip, LEFT(title,50) AS title_snip, LEFT(body,50) AS body_snip, created_at FROM fcm_send_logs $whereClause ORDER BY id DESC LIMIT 200";
if ($rl = $conn->query($logSql)) { while($row=$rl->fetch_assoc()){ $logs[]=$row; } }

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="content-wrapper">
  <section class="content-header"><div class="container-fluid"><h4>FCM Admin & Diagnostics</h4></div></section>
  <section class="content"><div class="container-fluid">
    <?php if ($toast): ?>
      <div class="alert alert-<?= $toast['type']=='success'?'success':($toast['type']=='warning'?'warning':'danger') ?>"><?= htmlspecialchars($toast['msg']) ?></div>
    <?php endif; ?>
    <div class="card mb-3">
      <div class="card-header"><strong>Service Account / Key Health</strong></div>
      <div class="card-body">
        <?php $saOk = is_file($saPath) && is_array($sa); ?>
        <div>Service account file: <?= $saOk? '<span class="text-success">OK</span>' : '<span class="text-danger">Missing/Invalid</span>' ?></div>
        <div>Project ID: <code><?= htmlspecialchars($sa['project_id'] ?? 'N/A') ?></code></div>
        <div>Client Email: <code><?= htmlspecialchars($sa['client_email'] ?? 'N/A') ?></code></div>
        <div class="mt-2">
          <a target="_blank" href="<?= BASE_URL ?>api/devices/fcm_debug.php?with_token=1&validate_send=1&api_token=<?= urlencode($_GET['api_token'] ?? '') ?>" class="btn btn-sm btn-outline-primary">Open Debug Endpoint</a>
          <small class="text-muted ml-2">Requires valid ?api_token= in URL (Bearer alternative)</small>
        </div>
        <hr>
        <form method="post" class="form-inline"> <!-- key refresh guidance (manual) -->
          <div class="text-muted">If token failures persist (invalid_grant): regenerate service account key in Firebase Console and replace <code>config/firebase_service_account.json</code>.</div>
        </form>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center"><strong>Registered Tokens (latest 200)</strong>
        <form method="post" class="form-inline mb-0">
          <input type="hidden" name="action" value="purge_stale">
          <input type="number" name="days" class="form-control form-control-sm mr-2" style="width:90px" value="90" min="1">
          <button class="btn btn-sm btn-outline-danger">Purge &lt; Days</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0">
          <thead class="thead-light"><tr><th>ID</th><th>User</th><th>Role</th><th>Token (truncated)</th><th>Updated</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if (empty($tokens)): ?><tr><td colspan="6" class="text-muted">No tokens found</td></tr><?php endif; ?>
          <?php foreach($tokens as $t): ?>
            <tr>
              <td><?= (int)$t['id'] ?></td>
              <td><?= (int)$t['user_id'] ?> — <?= htmlspecialchars($t['teacher_name'] ?: $t['username']) ?></td>
              <td><?= htmlspecialchars($t['role'] ?? '') ?></td>
              <td style="font-size:11px"><?= htmlspecialchars($t['token_short']) ?>...</td>
              <td><?= htmlspecialchars($t['updated_at']) ?></td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Delete token?');">
                  <input type="hidden" name="action" value="delete_token">
                  <input type="hidden" name="token_id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
                <form method="post" style="display:inline">
                  <input type="hidden" name="action" value="test_send">
                  <input type="hidden" name="user_id" value="<?= (int)$t['user_id'] ?>">
                  <button class="btn btn-sm btn-outline-primary">Test Send</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card mb-3">
      <div class="card-header d-flex justify-content-between align-items-center"><strong>Send Logs (latest 200)</strong>
        <form method="get" class="form-inline mb-0">
          <input type="number" name="user_id" class="form-control form-control-sm mr-2" style="width:90px" placeholder="User" value="<?= $filterUser ?: '' ?>">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="checkbox" id="failChk" name="failures" value="1" <?= $showFailuresOnly?'checked':'' ?>>
            <label class="form-check-label" for="failChk">Failures only</label>
          </div>
          <button class="btn btn-sm btn-outline-secondary ml-2">Filter</button>
        </form>
      </div>
      <div class="table-responsive" style="max-height:380px; overflow-y:auto">
        <table class="table table-sm table-hover mb-0">
          <thead class="thead-light"><tr><th>ID</th><th>User</th><th>HTTP</th><th>OK?</th><th>Title</th><th>Body</th><th>Resp Snip</th><th>Time</th></tr></thead>
          <tbody>
            <?php if (empty($logs)): ?><tr><td colspan="8" class="text-muted">No logs</td></tr><?php endif; ?>
            <?php foreach($logs as $l): ?>
              <tr class="<?= $l['success']? 'table-success':'table-danger' ?>">
                <td><?= (int)$l['id'] ?></td>
                <td><?= (int)$l['user_id'] ?></td>
                <td><?= htmlspecialchars($l['http_code']) ?></td>
                <td><?= $l['success']? '✔':'✖' ?></td>
                <td><?= htmlspecialchars($l['title_snip']) ?></td>
                <td><?= htmlspecialchars($l['body_snip']) ?></td>
                <td style="font-size:11px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;" title="<?= htmlspecialchars($l['resp_snip']) ?>"><?= htmlspecialchars($l['resp_snip']) ?></td>
                <td><?= htmlspecialchars($l['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer">
        <form method="post" class="form-inline" onsubmit="return confirm('Trim old logs?');">
          <input type="hidden" name="action" value="purge_send_logs">
          <label class="mr-2">Keep latest</label>
          <input type="number" name="keep" class="form-control form-control-sm mr-2" style="width:100px" value="5000" min="100">
          <button class="btn btn-sm btn-outline-danger">Trim Logs</button>
        </form>
      </div>
    </div>
  </div></section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<style>
  .table-danger td { background:#ffe5e5 !important; }
  .table-success td { background:#e6ffed !important; }
</style>
