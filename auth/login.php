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
	<title>login - Jorepukuria Secondary School</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet" />
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet" />
	<style>
		:root {
			--bg1: #0ea5e9; /* sky */
			--bg2: #6366f1; /* indigo */
			--bg3: #14b8a6; /* teal */
			--card-bg: rgba(255, 255, 255, 0.12);
			--card-border: rgba(255, 255, 255, 0.25);
			--text: #0f172a;
			--text-light: #334155;
			--accent: #2563eb;
		}

		* { box-sizing: border-box; }
		html, body { height: 100%; }
		body {
			margin: 0;
			display: grid;
			place-items: center;
			font-family: 'Poppins', system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji", "Segoe UI Emoji";
			color: var(--text);
			background: linear-gradient(120deg, var(--bg1), var(--bg2), var(--bg3));
			background-size: 200% 200%;
			animation: gradientShift 14s ease infinite;
			overflow: hidden;
		}

		/* Decorative animated blobs */
		.bg-blob { position: absolute; filter: blur(50px); opacity: 0.5; border-radius: 50%; }
		.blob-1 { width: 420px; height: 420px; left: -80px; top: -100px; background: #22d3ee; animation: float 10s ease-in-out infinite; }
		.blob-2 { width: 520px; height: 520px; right: -120px; bottom: -120px; background: #818cf8; animation: float 12s ease-in-out infinite reverse; }
		.blob-3 { width: 380px; height: 380px; right: 20%; top: -140px; background: #34d399; animation: float 11s ease-in-out infinite; }

		/* Fine dotted overlay for texture */
		.overlay {
			position: fixed; inset: 0;
			background-image: radial-gradient(rgba(255,255,255,0.1) 1px, transparent 1px);
			background-size: 12px 12px; pointer-events: none;
		}

		/* Glassmorphism auth card */
		.auth-card {
			position: relative;
			width: 100%; max-width: 440px;
			padding: 28px;
			border-radius: 22px;
			background: var(--card-bg);
			border: 1px solid var(--card-border);
			box-shadow: 0 20px 50px rgba(15, 23, 42, 0.25);
			backdrop-filter: blur(14px);
			-webkit-backdrop-filter: blur(14px);
			animation: rise 600ms cubic-bezier(.2,.6,.28,1) both;
		}

		/* Animated gradient ring */
		.auth-card::before {
			content: "";
			position: absolute; inset: -2px;
			border-radius: inherit;
			padding: 2px;
			background: conic-gradient(from 0deg, #60a5fa, #22d3ee, #34d399, #6366f1, #60a5fa);
			-webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
			mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
			-webkit-mask-composite: xor; mask-composite: exclude;
			/* Keep ring static and non-interactive; animation removed */
			pointer-events: none; z-index: 0;
		}

		.brand {
			display: flex; align-items: center; gap: 10px; justify-content: center;
			margin-bottom: 10px; text-align: center;
		}
		.brand .logo {
			width: 42px; height: 42px; display: grid; place-items: center; border-radius: 12px;
			background: linear-gradient(135deg, #60a5fa, #22d3ee);
			color: white; box-shadow: 0 8px 20px rgba(34, 211, 238, 0.4);
		}
		.brand h1 { font-size: 1.25rem; font-weight: 700; margin: 0; }
		.sub { font-size: .95rem; color: var(--text-light); margin-bottom: 18px; text-align: center; }

		/* Floating labels with icons */
		.form-floating { position: relative; }
		.form-floating .form-control { padding-left: 42px; }
		.form-floating .form-control {
			padding-left: 2.5rem;
		}
		.form-floating .icon {
			left: 1rem;
		}
		.form-floating .icon {
			position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
			color: #64748b; font-size: 1rem; pointer-events: none;
		}

		/* Password toggle */
		.toggle-visibility {
			position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
			border: none; background: transparent; color: #64748b; font-size: 1rem;
			padding: 6px; line-height: 1; cursor: pointer;
		}
		.toggle-visibility:focus { outline: none; color: var(--accent); }

		/* Animated button */
		.btn-gradient {
			--c1: #0ea5e9; --c2: #6366f1; --c3: #22d3ee;
			background: linear-gradient(90deg, var(--c1), var(--c2), var(--c3));
			background-size: 200% 200%;
			color: #fff; border: 0; border-radius: 14px; padding: 12px 16px;
			box-shadow: 0 12px 20px rgba(99, 102, 241, .35);
			/* Foreground animation removed per preference */
		}
		.btn-gradient:hover { filter: brightness(1.05); }

		/* Subtle focus animation */
		.form-control:focus {
			box-shadow: 0 0 0 .25rem rgba(99, 102, 241, .25);
			border-color: #818cf8;
			transition: box-shadow 200ms ease, border-color 200ms ease;
		}

		/* Footer note */
		.foot { margin-top: 16px; color: #475569; font-size: .85rem; text-align: center; }

		/* Animations */
		@keyframes gradientShift {
			0% { background-position: 0% 50%; }
			50% { background-position: 100% 50%; }
			100% { background-position: 0% 50%; }
		}
		@keyframes float {
			0%, 100% { transform: translate(0, 0) scale(1); }
			50% { transform: translate(0, -20px) scale(1.05); }
		}
		@keyframes rise {
			from { opacity: 0; transform: translateY(18px) scale(.98); }
			to { opacity: 1; transform: translateY(0) scale(1); }
		}
		@keyframes spin { to { transform: rotate(360deg); } }

		/* Respect reduced motion */
		@media (prefers-reduced-motion: reduce) {
			body, .btn-gradient, .blob-1, .blob-2, .blob-3, .auth-card::before { animation: none; }
		}
	</style>
</head>
<body>
	<!-- Animated background layers -->
	<div class="bg-blob blob-1"></div>
	<div class="bg-blob blob-2"></div>
	<div class="bg-blob blob-3"></div>
	<div class="overlay"></div>

	<main class="auth-card">
		<div class="brand">
			<div class="logo" style="background: none; box-shadow: none; padding: 0;">
				<img src="../logo.png" alt="Institute Logo" style="width: 54px; height: 54px; object-fit: contain; border-radius: 12px; background: #fff; box-shadow: 0 2px 8px rgba(34,211,238,0.10); display: block; margin: 0 auto 6px auto;">
			</div>
		</div>
		<div class="brand" style="margin-top: -18px;">
			<div class="logo" style="display:none;"></div>
			<h1>Jorepukuria Secondary School</h1>
		</div>
		<div class="sub">Login — Securely access your account</div>

		<?php if (isset($_GET['error'])): ?>
			<div class="alert alert-danger mb-3" role="alert">
				<i class="fa-solid fa-triangle-exclamation me-1"></i>
				<?= htmlspecialchars($_GET['error']) ?>
			</div>
		<?php endif; ?>
		<?php if (isset($_GET['success'])): ?>
			<div class="alert alert-success mb-3" role="alert">
				<i class="fa-solid fa-circle-check me-1"></i>
				<?= htmlspecialchars($_GET['success']) ?>
			</div>
		<?php endif; ?>

		<form action="check-login.php" method="post" novalidate>
			<div class="form-floating mb-3">
				<i class="fa-solid fa-user icon" aria-hidden="true"></i>
				<input type="text" class="form-control" id="username" name="username" placeholder="Username" required autofocus />
				<label for="username">Username</label>
			</div>

			<div class="form-floating mb-2 position-relative">
				<i class="fa-solid fa-lock icon" aria-hidden="true"></i>
				<input type="password" class="form-control" id="password" name="password" placeholder="Password" required />
				<label for="password">Password</label>
				<button type="button" class="toggle-visibility" id="togglePassword" tabindex="-1" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
			</div>

			<button type="submit" class="btn btn-gradient w-100 mt-2">Login</button>
			<div class="foot">Your information is securely protected.</div>
		</form>
	</main>

	<script>
	// Accessible password visibility toggle
	document.addEventListener('DOMContentLoaded', function(){
		var btn = document.getElementById('togglePassword');
		var pw = document.getElementById('password');
		if (btn && pw) {
			btn.addEventListener('click', function(){
				var hidden = pw.type === 'password';
				pw.type = hidden ? 'text' : 'password';
				var icon = btn.querySelector('i');
				if (icon) { icon.className = hidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye'; }
				btn.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
			});
		}
	});
	</script>
</body>
</html>
