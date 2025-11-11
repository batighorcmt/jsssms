<?php
session_start();
@include_once __DIR__ . '/../config/config.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
  header("Location: " . BASE_URL . "auth/login.php");
  exit();
}

include '../config/db.php';

// Ensure teachers.initial_password column exists to display plain 6-digit password
$colExists = false;
if ($res = $conn->query("SHOW COLUMNS FROM teachers LIKE 'initial_password'")) {
  $colExists = ($res->num_rows > 0);
}
if (!$colExists) {
  @ $conn->query("ALTER TABLE teachers ADD COLUMN initial_password VARCHAR(20) NULL DEFAULT NULL");
}

function random_6digit() {
  return strval(random_int(100000, 999999));
}

$toast = null; // ['type'=>'success'|'error','msg'=>string]

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';

  if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if ($name === '' || $contact === '') {
      $toast = ['type'=>'error','msg'=>'নাম এবং মোবাইল অবশ্যই দিতে হবে'];
    } elseif (!preg_match('/^01\d{9}$/', $contact)) {
      $toast = ['type'=>'error','msg'=>'সঠিক ১১ ডিজিটের মোবাইল নম্বর (০১…) দিন'];
    } else {
      // Check username (mobile) uniqueness in users
      $u = $conn->prepare("SELECT id FROM users WHERE username = ?");
      $u->bind_param('s', $contact);
      $u->execute(); $ur = $u->get_result();
      if ($ur && $ur->num_rows > 0) {
        $toast = ['type'=>'error','msg'=>'এই মোবাইল নাম্বারে আগে থেকেই ইউজার আছে'];
      } else {
        // Insert teacher
        $ins = $conn->prepare("INSERT INTO teachers (name, subject, contact, email, address, initial_password, created_at) VALUES (?,?,?,?,?, ?, NOW())");
        $pwd6 = random_6digit();
        $ins->bind_param('ssssss', $name, $subject, $contact, $email, $address, $pwd6);
        if ($ins->execute()) {
          // Create user with hashed password
          $hash = password_hash($pwd6, PASSWORD_BCRYPT);
          $role = 'teacher';
          $iu = $conn->prepare("INSERT INTO users (username, password, name, role, created_at) VALUES (?,?,?,?, NOW())");
          $iu->bind_param('ssss', $contact, $hash, $name, $role);
          if ($iu->execute()) {
            $toast = ['type'=>'success','msg'=>'শিক্ষক এবং ইউজার সফলভাবে যুক্ত হয়েছে'];
          } else {
            $toast = ['type'=>'error','msg'=>'শিক্ষক যুক্ত হয়েছে কিন্তু ইউজার তৈরি ব্যর্থ: '.$conn->error];
          }
        } else {
          $toast = ['type'=>'error','msg'=>'শিক্ষক যুক্ত করা ব্যর্থ: '.$conn->error];
        }
      }
    }
  }

  if ($action === 'edit') {
    $id = intval($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    if ($id <= 0) {
      $toast = ['type'=>'error','msg'=>'অবৈধ আইডি'];
    } else {
      // Fetch old contact
      $old = $conn->prepare("SELECT contact FROM teachers WHERE id = ?");
      $old->bind_param('i', $id);
      $old->execute(); $or = $old->get_result(); $oldContact = null;
      if ($or && $row = $or->fetch_assoc()) $oldContact = $row['contact'];

      // If contact changed, ensure new username doesn't already exist
      if ($oldContact !== null && $contact !== $oldContact) {
        $uChk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $uChk->bind_param('s', $contact);
        $uChk->execute(); $ucr = $uChk->get_result();
        if ($ucr && $ucr->num_rows > 0) {
          $toast = ['type'=>'error','msg'=>'নতুন মোবাইল/ইউজারনেম ইতিমধ্যে ব্যবহৃত'];
        }
      }

      if (!($toast && $toast['type'] === 'error')) {
        $upd = $conn->prepare("UPDATE teachers SET name=?, subject=?, contact=?, email=?, address=? WHERE id=?");
        $upd->bind_param('sssssi', $name, $subject, $contact, $email, $address, $id);
        if ($upd->execute()) {
          // Sync users table (username and name)
          if ($oldContact) {
            $uu = $conn->prepare("UPDATE users SET username=?, name=? WHERE username=?");
            $uu->bind_param('sss', $contact, $name, $oldContact);
            $uu->execute();
            if ($conn->affected_rows === 0) {
              // Create user if missing
              $existsNew = $conn->prepare("SELECT id FROM users WHERE username=?");
              $existsNew->bind_param('s', $contact);
              $existsNew->execute(); $en = $existsNew->get_result();
              if (!$en || $en->num_rows === 0) {
                $pwd6 = random_6digit();
                $hash = password_hash($pwd6, PASSWORD_BCRYPT);
                $role = 'teacher';
                $iu = $conn->prepare("INSERT INTO users (username, password, name, role, created_at) VALUES (?,?,?,?, NOW())");
                $iu->bind_param('ssss', $contact, $hash, $name, $role);
                $iu->execute();
                $tp = $conn->prepare("UPDATE teachers SET initial_password=? WHERE id=?");
                $tp->bind_param('si', $pwd6, $id);
                $tp->execute();
              }
            }
          }
          $toast = ['type'=>'success','msg'=>'তথ্য আপডেট হয়েছে'];
        } else {
          $toast = ['type'=>'error','msg'=>'আপডেট ব্যর্থ: '.$conn->error];
        }
      }
    }
  }

  if ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id <= 0) {
      $toast = ['type'=>'error','msg'=>'অবৈধ আইডি'];
    } else {
      $getc = $conn->prepare("SELECT contact FROM teachers WHERE id = ?");
      $getc->bind_param('i', $id);
      $getc->execute(); $gr = $getc->get_result(); $contact = null;
      if ($gr && $r = $gr->fetch_assoc()) $contact = $r['contact'];

      $del = $conn->prepare("DELETE FROM teachers WHERE id = ?");
      $del->bind_param('i', $id);
      if ($del->execute()) {
        if ($contact) {
          $du = $conn->prepare("DELETE FROM users WHERE username = ? AND role = 'teacher'");
          $du->bind_param('s', $contact);
          $du->execute();
        }
        $toast = ['type'=>'success','msg'=>'শিক্ষক মুছে ফেলা হয়েছে'];
      } else {
        $toast = ['type'=>'error','msg'=>'মুছে ফেলতে ব্যর্থ: '.$conn->error];
      }
    }
  }

  $_SESSION['toast'] = $toast;
  header('Location: '.basename(__FILE__));
  exit();
}

if (isset($_SESSION['toast'])) { $toast = $_SESSION['toast']; unset($_SESSION['toast']); }

include '../includes/header.php';
?>
<?php include '../includes/sidebar.php'; ?>

<?php
// Load teachers
$list = $conn->query("SELECT id, name, subject, contact, email, address, initial_password FROM teachers ORDER BY id DESC");
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="bn">শিক্ষক ব্যবস্থাপনা</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-right">
            <li class="breadcrumb-item"><a href="<?= BASE_URL ?>dashboard.php">Home</a></li>
            <li class="breadcrumb-item active">Teachers</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">শিক্ষক তালিকা</h3>
          <div class="card-tools">
            <button class="btn btn-primary btn-sm" data-toggle="modal" data-target="#addTeacherModal"><i class="fas fa-plus"></i> নতুন শিক্ষক</button>
          </div>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-hover table-bordered mb-0">
            <thead class="thead-light">
              <tr>
                <th style="width:80px">ক্রমিক</th>
                <th>নাম</th>
                <th>মোবাইল</th>
                <th>ইমেইল</th>
                <th>ঠিকানা</th>
                <th>পাসওয়ার্ড</th>
                <th style="width:140px">অ্যাকশন</th>
              </tr>
            </thead>
            <tbody>
              <?php $sl = 1; if ($list && $list->num_rows > 0): while($t = $list->fetch_assoc()): ?>
              <tr>
                <td><?= $sl++ ?></td>
                <td><?= htmlspecialchars($t['name']) ?></td>
                <td><?= htmlspecialchars($t['contact']) ?></td>
                <td><?= htmlspecialchars($t['email']) ?></td>
                <td><?= htmlspecialchars($t['address']) ?></td>
                <td><code><?= htmlspecialchars($t['initial_password'] ?? '') ?></code></td>
                <td>
                  <button class="btn btn-sm btn-info btn-edit" 
                          data-id="<?= (int)$t['id'] ?>"
                          data-name="<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>"
                          data-subject="<?= htmlspecialchars($t['subject'], ENT_QUOTES) ?>"
                          data-contact="<?= htmlspecialchars($t['contact'], ENT_QUOTES) ?>"
                          data-email="<?= htmlspecialchars($t['email'], ENT_QUOTES) ?>"
                          data-address="<?= htmlspecialchars($t['address'], ENT_QUOTES) ?>">
                    এডিট
                  </button>
                  <form method="post" action="" style="display:inline" onsubmit="return confirm('ডিলিট করতে চান?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">ডিলিট</button>
                  </form>
                </td>
              </tr>
              <?php endwhile; else: ?>
              <tr><td colspan="7" class="text-center text-muted">কোনো তথ্য নেই</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addTeacherModal" tabindex="-1" role="dialog" aria-labelledby="addTeacherLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" action="">
        <input type="hidden" name="action" value="add">
        <div class="modal-header">
          <h5 class="modal-title" id="addTeacherLabel">নতুন শিক্ষক যোগ করুন</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>নাম</label>
            <input type="text" name="name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>বিষয়</label>
            <input type="text" name="subject" class="form-control">
          </div>
          <div class="form-group">
            <label>মোবাইল (ইউজারনেম)</label>
            <input type="text" name="contact" id="add_contact" class="form-control" required>
            <small id="add_contact_help" class="form-text d-none"></small>
          </div>
          <div class="form-group">
            <label>ইমেইল</label>
            <input type="email" name="email" class="form-control">
          </div>
          <div class="form-group">
            <label>ঠিকানা</label>
            <textarea name="address" class="form-control" rows="2"></textarea>
          </div>
          <small class="text-muted">সেভ হলে স্বয়ংক্রিয়ভাবে ৬ সংখ্যার পাসওয়ার্ড সেট হবে এবং তালিকায় দেখা যাবে।</small>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">সেভ</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">বন্ধ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editTeacherModal" tabindex="-1" role="dialog" aria-labelledby="editTeacherLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="post" action="">
        <input type="hidden" name="action" value="edit">
  <input type="hidden" name="id" id="edit_id">
  <input type="hidden" id="edit_old_contact" value="">
        <div class="modal-header">
          <h5 class="modal-title" id="editTeacherLabel">শিক্ষক সম্পাদনা</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>নাম</label>
            <input type="text" name="name" id="edit_name" class="form-control" required>
          </div>
          <div class="form-group">
            <label>বিষয়</label>
            <input type="text" name="subject" id="edit_subject" class="form-control">
          </div>
          <div class="form-group">
            <label>মোবাইল (ইউজারনেম)</label>
            <input type="text" name="contact" id="edit_contact" class="form-control" required>
            <small id="edit_contact_help" class="form-text d-none"></small>
          </div>
          <div class="form-group">
            <label>ইমেইল</label>
            <input type="email" name="email" id="edit_email" class="form-control">
          </div>
          <div class="form-group">
            <label>ঠিকানা</label>
            <textarea name="address" id="edit_address" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">আপডেট</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">বন্ধ</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Ensure jQuery is loaded before using it
function whenJQReady(cb){
  if (window.jQuery) { cb(window.jQuery); return; }
  var i = 0; var t = setInterval(function(){
    if (window.jQuery) { clearInterval(t); cb(window.jQuery); }
    if (++i > 100) clearInterval(t); // ~5s timeout
  }, 50);
}

whenJQReady(function($){
$(function(){ // DOM ready
  // Edit modal populate (delegated to support dynamic rows)
  $(document).on('click', '.btn-edit', function(e){
    e.preventDefault();
    var b = $(this);
    $('#edit_id').val(b.data('id'));
    $('#edit_name').val(b.data('name'));
    $('#edit_subject').val(b.data('subject'));
    $('#edit_contact').val(b.data('contact'));
    $('#edit_old_contact').val(b.data('contact'));
    $('#edit_email').val(b.data('email'));
    $('#edit_address').val(b.data('address'));
    $('#editTeacherModal').modal('show');
  });

  // Helpers for help messages
  function setHelp($help, msg, type){
    if (!$help || $help.length===0) return;
    $help.text(msg||'');
    $help.removeClass('d-none text-danger text-success');
    if (type==='error') $help.addClass('text-danger');
    else if (type==='success') $help.addClass('text-success');
  }
  function hideHelp($help){ if ($help) { $help.addClass('d-none').removeClass('text-danger text-success'); } }
  function isValidMobile(v){ return /^01\d{9}$/.test((v||'').trim()); }

  // Live username/mobile uniqueness check
  function checkUsername(inputEl, helpSelector, excludeVal, submitBtn){
    var val = $(inputEl).val().trim();
    var $help = $(helpSelector);
    if (val === '') { hideHelp($help); if (submitBtn) $(submitBtn).prop('disabled', false); return; }
    if (!isValidMobile(val)) { setHelp($help, 'সঠিক ১১ ডিজিটের মোবাইল নম্বর (০১…) দিন', 'error'); if (submitBtn) $(submitBtn).prop('disabled', true); return; }
    var data = { username: val };
    if (excludeVal) data.exclude = excludeVal;
    $.post('../ajax/check_username.php', data, function(resp){
      if (resp && resp.ok) {
        if (resp.exists) {
          setHelp($help, 'এই ইউজার ইতিমধ্যে রয়েছে। অন্য নম্বর প্রদান করুন', 'error');
          if (submitBtn) $(submitBtn).prop('disabled', true);
        } else {
          setHelp($help, 'ইউজারনেম ফাঁকা আছে', 'success');
          if (submitBtn) $(submitBtn).prop('disabled', false);
        }
      }
    }, 'json');
  }

  // Bind live checks (input + blur)
  $('#add_contact').on('input blur', function(){
    var form = $(this).closest('form');
    var btn = form.find('button[type="submit"]');
    checkUsername(this, '#add_contact_help', null, btn);
  });
  $('#edit_contact').on('input blur', function(){
    var form = $(this).closest('form');
    var btn = form.find('button[type="submit"]');
    var exclude = $('#edit_old_contact').val();
    checkUsername(this, '#edit_contact_help', exclude, btn);
  });

  // Prevent submit if username not available (extra guard for Enter key)
  $('#addTeacherModal form').on('submit', function(e){
    var $h = $('#add_contact_help');
    if (!$h.hasClass('d-none') && $h.hasClass('text-danger')) {
      e.preventDefault();
      if (window.showToast) window.showToast('ত্রুটি', 'এই ইউজার ইতিমধ্যে রয়েছে অথবা নম্বর সঠিক নয়', 'error');
      $('#add_contact').focus();
    }
  });
  $('#editTeacherModal form').on('submit', function(e){
    var $h = $('#edit_contact_help');
    if (!$h.hasClass('d-none') && $h.hasClass('text-danger')) {
      e.preventDefault();
      if (window.showToast) window.showToast('ত্রুটি', 'এই ইউজার ইতিমধ্যে রয়েছে অথবা নম্বর সঠিক নয়', 'error');
      $('#edit_contact').focus();
    }
  });

  // Toast from server
  <?php if ($toast): ?>
    if (window.showToast) window.showToast(<?= json_encode($toast['type']==='success'?'সফল':'ত্রুটি') ?>, <?= json_encode($toast['msg']) ?>, <?= json_encode($toast['type']) ?>);
  <?php endif; ?>
});
});
</script>

<?php include '../includes/footer.php'; ?>
