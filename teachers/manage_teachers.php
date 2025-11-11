<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

include '../config/db.php';
include '../includes/header.php';
?>

<?php include '../includes/sidebar.php'; ?>

<?php
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

            $upd = $conn->prepare("UPDATE teachers SET name=?, subject=?, contact=?, email=?, address=? WHERE id=?");
            $upd->bind_param('sssssi', $name, $subject, $contact, $email, $address, $id);
            if ($upd->execute()) {
                // Sync users table (username and name)
                if ($oldContact) {
                    $uu = $conn->prepare("UPDATE users SET username=?, name=? WHERE username=?");
                    $uu->bind_param('sss', $contact, $name, $oldContact);
                    $uu->execute();
                    if ($conn->affected_rows === 0) {
                        // If user row not found for old contact, create one for new contact
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
                            // Update initial_password since we created new one
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

    if ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            $toast = ['type'=>'error','msg'=>'অবৈধ আইডি'];
        } else {
            // Get contact to delete related user
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
}

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
            <li class="breadcrumb-item"><a href="/jsssms/dashboard.php">Home</a></li>
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
                <th style="width:60px">আইডি</th>
                <th>নাম</th>
                <th>বিষয়</th>
                <th>মোবাইল</th>
                <th>ইমেইল</th>
                <th>ঠিকানা</th>
                <th>পাসওয়ার্ড</th>
                <th style="width:140px">অ্যাকশন</th>
              </tr>
            </thead>
            <tbody>
              <?php if ($list && $list->num_rows > 0): while($t = $list->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$t['id'] ?></td>
                <td><?= htmlspecialchars($t['name']) ?></td>
                <td><?= htmlspecialchars($t['subject']) ?></td>
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
              <tr><td colspan="8" class="text-center text-muted">কোনো তথ্য নেই</td></tr>
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
            <input type="text" name="contact" class="form-control" required>
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
  // Edit modal populate
  $(document).on('click', '.btn-edit', function(){
    var b = $(this);
    $('#edit_id').val(b.data('id'));
    $('#edit_name').val(b.data('name'));
    $('#edit_subject').val(b.data('subject'));
    $('#edit_contact').val(b.data('contact'));
    $('#edit_email').val(b.data('email'));
    $('#edit_address').val(b.data('address'));
    $('#editTeacherModal').modal('show');
  });

  // Toast from server
  <?php if ($toast): ?>
    $(function(){ if (window.showToast) window.showToast(<?= json_encode($toast['type']==='success'?'সফল':'ত্রুটি') ?>, <?= json_encode($toast['msg']) ?>, <?= json_encode($toast['type']) ?>); });
  <?php endif; ?>
</script>

<?php include '../includes/footer.php'; ?>
