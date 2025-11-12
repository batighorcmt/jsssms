<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role'])) { header('Location: ' . BASE_URL . 'auth/login.php'); exit(); }
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

$username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? '';
$msg = null;

// Handle updates for teacher profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'teacher') {
  $name = trim($_POST['name'] ?? $name);
  $email = trim($_POST['email'] ?? '');
  $address = trim($_POST['address'] ?? '');

  // Photo upload handling
  $photoPath = null;
  if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
    $type = mime_content_type($_FILES['photo']['tmp_name']);
    if (!isset($allowed[$type])) {
      $msg = ['type'=>'error','text'=>'শুধু JPG/PNG/GIF/WEBP ফাইল আপলোড করা যাবে'];
    } else if ($_FILES['photo']['size'] > 2*1024*1024) {
      $msg = ['type'=>'error','text'=>'ছবির সাইজ 2MB এর কম হতে হবে'];
    } else {
      $ext = $allowed[$type];
      $dir = __DIR__ . '/../uploads/teachers/';
      if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
      $fname = 't_' . preg_replace('~[^0-9]~','',$username) . '_' . time() . '.' . $ext;
      $dest = $dir . $fname;
      if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        $photoPath = 'uploads/teachers/' . $fname;
        $_SESSION['photo'] = $photoPath; // for header avatar
      } else {
        $msg = ['type'=>'error','text'=>'ছবি আপলোড ব্যর্থ হয়েছে'];
      }
    }
  }

  if (!$msg || $msg['type'] !== 'error') {
    // Update DB row
    $sql = 'UPDATE teachers SET name=?, email=?, address=?' . ($photoPath?', photo=?':'') . ' WHERE contact=?';
    if ($photoPath) {
      $st = $conn->prepare($sql);
      $st->bind_param('sssss', $name, $email, $address, $photoPath, $username);
    } else {
      $st = $conn->prepare($sql);
      $st->bind_param('ssss', $name, $email, $address, $username);
    }
    if ($st && $st->execute()) {
      $_SESSION['name'] = $name;
      $msg = ['type'=>'success','text'=>'প্রোফাইল আপডেট হয়েছে'];
    } else {
      $msg = ['type'=>'error','text'=>'সংরক্ষণ ব্যর্থ'];
    }
  }
}

// Load profile data
$profile = ['name'=>$name,'email'=>'','address'=>'','photo'=>''];
if ($role === 'teacher' && $username) {
  $s = $conn->prepare('SELECT name, email, address, photo FROM teachers WHERE contact=? LIMIT 1');
  $s->bind_param('s', $username);
  $s->execute(); $r = $s->get_result();
  if ($r && ($row=$r->fetch_assoc())) { $profile = $row; }
}
$avatar = $profile['photo'] ? (BASE_URL . ltrim($profile['photo'],'/')) : (BASE_URL . 'assets/img/default-avatar.svg');
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6"><h1 class="bn">আমার প্রোফাইল</h1></div>
  <div class="col-sm-6"><ol class="breadcrumb float-sm-right"><li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li><li class="breadcrumb-item active">Profile</li></ol></div>
      </div>
    </div>
  </section>
  <section class="content">
    <div class="container-fluid">
      <?php if ($msg): ?>
        <script>document.addEventListener('DOMContentLoaded', function(){ if (window.showToast) window.showToast(<?= json_encode($msg['type']==='success'?'সফল':'ত্রুটি') ?>, <?= json_encode($msg['text']) ?>, <?= json_encode($msg['type']) ?>); });</script>
      <?php endif; ?>
      <div class="row">
        <div class="col-md-4">
          <div class="card">
            <div class="card-body text-center">
              <img src="<?= htmlspecialchars($avatar) ?>" class="img-circle mb-3" alt="avatar" style="width:120px;height:120px;object-fit:cover;">
              <h3 class="bn"><?= htmlspecialchars($profile['name'] ?? '') ?></h3>
              <p class="text-muted">Role: <?= htmlspecialchars($role) ?></p>
            </div>
          </div>
        </div>
        <div class="col-md-8">
          <div class="card">
            <div class="card-header"><h3 class="card-title bn">তথ্য হালনাগাদ</h3></div>
            <div class="card-body">
              <?php if ($role === 'teacher'): ?>
              <form method="post" enctype="multipart/form-data">
                <div class="form-group">
                  <label class="bn">নাম</label>
                  <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($profile['name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label class="bn">ইমেইল</label>
                  <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($profile['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                  <label class="bn">ঠিকানা</label>
                  <textarea name="address" class="form-control" rows="3"><?= htmlspecialchars($profile['address'] ?? '') ?></textarea>
                </div>
                <div class="form-group">
                  <label class="bn">ছবি (২ এমবি এর কম)</label>
                  <input type="file" name="photo" accept="image/*" class="form-control-file">
                </div>
                <button type="submit" class="btn btn-primary">সংরক্ষণ</button>
              </form>
              <?php else: ?>
                <p class="text-muted">এই রোলের জন্য প্রোফাইল সম্পাদনা সক্রিয় নয়।</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
