<?php 
session_start();
if (isset($_SESSION['username']) && isset($_SESSION['id'])) {
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Jorepukuria Secondary School Login</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0-beta1/dist/css/bootstrap.min.css" rel="stylesheet" />
	<style>
		body {
			background: linear-gradient(to right, #6a11cb, #2575fc);
			min-height: 100vh;
			display: flex;
			align-items: center;
			justify-content: center;
			font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
		}
		.login-box {
			background: #fff;
			border-radius: 15px;
			box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
			padding: 30px;
			width: 100%;
			max-width: 400px;
		}
	</style>
</head>
<body>
<div class="login-box">
	<h3 class="mb-4 text-center">Jorepukuria Secondary School Login</h3>

	<?php if (isset($_GET['error'])): ?>
		<div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
	<?php endif; ?>

	<form action="check-login.php" method="post">
		<div class="mb-3">
			<label for="username" class="form-label">ইউজারনেম</label>
			<input type="text" name="username" id="username" class="form-control" required autofocus />
		</div>

		<div class="mb-3">
			<label for="password" class="form-label">পাসওয়ার্ড</label>
			<input type="password" name="password" id="password" class="form-control" required />
		</div>

		<div class="mb-3">
			<label for="role" class="form-label">লগইন রোল</label>
			<select name="role" id="role" class="form-select" required>
				<option value="">-- রোল সিলেক্ট করুন --</option>
				<option value="super_admin">Super Admin</option>
				<option value="teacher">Teacher</option>
			</select>
		</div>

		<button type="submit" class="btn btn-primary w-100">লগইন</button>
	</form>
</div>
</body>
</html>
