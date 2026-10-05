<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])) {
	$destination = in_array($_SESSION['user_role'] ?? '', ['staff', 'owner'], true) ? 'inventory.php' : 'shop.php';
	header('Location: ' . $destination);
	exit;
}

function escapeLoginHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$email = '';
$role = 'customer';
$error = '';
$databaseError = '';
$setupMode = false;
$conn = null;

try {
	require __DIR__ . '/conn.php';
	if (!($conn instanceof mysqli)) {
		throw new RuntimeException('The database connection is unavailable.');
	}
	$loginConnection = $conn;
	$userCount = (int) $loginConnection->query('SELECT COUNT(*) FROM users')->fetch_row()[0];
	$setupMode = $userCount === 0;
} catch (Throwable $exception) {
	$databaseError = 'Could not connect to the account database. Start MySQL in XAMPP and try again.';
}

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseError === '') {
	$submittedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
		$error = 'This form expired. Refresh the page and try again.';
	} else {
		$postedRole = $_POST['role'] ?? '';
		$role = is_string($postedRole) && in_array($postedRole, ['customer', 'staff', 'owner'], true) ? $postedRole : '';
		$verificationCode = $_POST['verification_code'] ?? '';
		$verificationCode = is_string($verificationCode) ? $verificationCode : '';
		$postedEmail = $_POST['email'] ?? '';
		$email = is_string($postedEmail) ? trim($postedEmail) : '';
		$password = $_POST['password'] ?? '';
		$password = is_string($password) ? $password : '';

		if ($role === '') {
			$error = 'Choose how you want to sign in.';
		} elseif ($role !== 'customer' && !hash_equals('040207', $verificationCode)) {
			$error = 'Enter the correct staff or owner verification number.';
		} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
			$error = 'Enter a valid email address.';
		} elseif ($setupMode) {
			$confirmation = $_POST['password_confirmation'] ?? '';
			$confirmation = is_string($confirmation) ? $confirmation : '';
			if (strlen($password) < 10) {
				$error = 'Choose a password with at least 10 characters.';
			} elseif ($password !== $confirmation) {
				$error = 'The passwords do not match.';
			} else {
				try {
					$passwordHash = password_hash($password, PASSWORD_DEFAULT);
					$statement = $loginConnection->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
					$statement->bind_param('ss', $email, $passwordHash);
					$statement->execute();
					session_regenerate_id(true);
					$_SESSION['user_id'] = (int) $loginConnection->insert_id;
					$_SESSION['user_email'] = $email;
					$_SESSION['user_role'] = $role;
					$destination = in_array($role, ['staff', 'owner'], true) ? 'inventory.php' : 'shop.php';
					header('Location: ' . $destination);
					exit;
				} catch (Throwable $exception) {
					$error = 'The first account could not be created. Refresh and try again.';
				}
			}
		} elseif ($password === '') {
			$error = 'Enter your password.';
		} else {
			try {
				$statement = $loginConnection->prepare('SELECT id, email, password_hash FROM users WHERE email = ? LIMIT 1');
				$statement->bind_param('s', $email);
				$statement->execute();
				$user = $statement->get_result()->fetch_assoc();
				if ($user !== null && password_verify($password, $user['password_hash'])) {
					session_regenerate_id(true);
					$_SESSION['user_id'] = (int) $user['id'];
					$_SESSION['user_email'] = $user['email'];
					$_SESSION['user_role'] = $role;
					$destination = in_array($role, ['staff', 'owner'], true) ? 'inventory.php' : 'shop.php';
					header('Location: ' . $destination);
					exit;
				}
				$error = 'Email or password is incorrect.';
			} catch (Throwable $exception) {
				$error = 'Sign in could not be completed. Refresh and try again.';
			}
		}
	}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#f5f7f4">
  <title><?= $setupMode ? 'Create Account' : 'Sign In' ?> | JamesGuitars</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
	:root { color-scheme:light; --page:#f5f7f4; --panel:#fff; --ink:#202b25; --muted:#7b867e; --line:#e4e9e3; --accent:#276b4a; --accent-wash:#e7f2e8; --glass-bg:rgba(255,255,255,.64); --glass-edge:rgba(255,255,255,.78); --danger:#b44236; --shadow:0 24px 70px rgba(28,49,36,.1); }
	[data-theme="dark"] { color-scheme:dark; --page:#171d19; --panel:#202923; --ink:#edf3ed; --muted:#9ca99f; --line:#354139; --accent:#a8d7a9; --accent-wash:#2e4435; --glass-bg:rgba(32,41,35,.72); --glass-edge:rgba(222,243,224,.23); --danger:#ff9b8f; --shadow:0 24px 70px rgba(0,0,0,.25); }
	* { box-sizing:border-box; }
	body { position:relative; isolation:isolate; min-width:320px; min-height:100vh; margin:0; display:grid; place-items:center; overflow-x:hidden; padding:24px; background:var(--page); color:var(--ink); font-family:"DM Sans",sans-serif; }
	body::before { position:fixed; z-index:-1; inset:-5%; background-image:radial-gradient(ellipse at 15% 18%, color-mix(in srgb,var(--accent-wash) 72%,transparent), transparent 44%),linear-gradient(110deg,color-mix(in srgb,var(--page) 38%,transparent),color-mix(in srgb,var(--page) 60%,transparent)),url("https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=2000&q=85"); background-position:center,center,55% 48%; background-size:cover; background-repeat:no-repeat; content:""; animation:guitar-drift 34s ease-in-out infinite alternate; }
	button,input { font:inherit; }
	.login-shell { width:min(100%,420px); animation:arrive .55s both; }
	.brand { display:flex; justify-content:center; align-items:center; gap:11px; margin:0 0 28px; font-family:"Space Grotesk",sans-serif; font-size:22px; font-weight:700; }
	.brand-mark { display:grid; width:36px; height:36px; place-items:center; border-radius:11px; background:var(--accent); color:white; }
	[data-theme="dark"] .brand-mark { color:#172219; }
	.brand-mark svg { width:20px; height:20px; }
	.login-panel { padding:32px; border:1px solid var(--glass-edge); border-radius:12px; background:var(--glass-bg); -webkit-backdrop-filter:blur(24px) saturate(155%); backdrop-filter:blur(24px) saturate(155%); box-shadow:inset 0 1px 0 rgba(255,255,255,.58),var(--shadow); }
	.eyebrow { margin:0 0 9px; color:var(--accent); font-size:10px; font-weight:700; letter-spacing:1.5px; text-align:center; text-transform:uppercase; }
	h1 { margin:0 0 8px; font-family:"Space Grotesk",sans-serif; font-size:28px; text-align:center; }
	.intro { margin:0 0 26px; color:var(--muted); font-size:12px; line-height:1.6; text-align:center; }
	.field { margin-bottom:17px; }
	label { display:block; margin-bottom:7px; font-size:11px; font-weight:700; }
	input { width:100%; height:44px; padding:0 12px; border:1px solid var(--glass-edge); border-radius:6px; outline:none; background:color-mix(in srgb,var(--glass-bg) 78%,transparent); color:var(--ink); -webkit-backdrop-filter:blur(10px); backdrop-filter:blur(10px); font-size:12px; }
	input:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 16%,transparent); }
	input::placeholder { color:var(--muted); }
	.role-field { min-width:0; padding:0; border:0; }
	.role-field legend { margin-bottom:8px; font-size:11px; font-weight:700; }
	.role-options { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:7px; }
	.role-option { position:relative; margin:0; cursor:pointer; }
	.role-option input { position:absolute; width:1px; height:1px; padding:0; opacity:0; }
	.role-option span { display:grid; min-height:39px; place-items:center; border:1px solid var(--glass-edge); border-radius:6px; background:var(--glass-bg); color:var(--muted); font-size:11px; font-weight:600; }
	.role-option input:checked + span { border-color:var(--accent); background:var(--accent-wash); color:var(--accent); }
	.role-option input:focus-visible + span { outline:3px solid color-mix(in srgb,var(--accent) 35%,transparent); outline-offset:2px; }
	.verification-field[hidden] { display:none; }
	.form-message { margin:0 0 17px; padding:11px 12px; border:1px solid color-mix(in srgb,var(--danger) 35%,transparent); border-radius:6px; background:color-mix(in srgb,var(--danger) 8%,var(--panel)); color:var(--danger); font-size:11px; line-height:1.5; }
	.submit-button { display:flex; width:100%; min-height:44px; align-items:center; justify-content:center; gap:9px; margin-top:22px; border:1px solid color-mix(in srgb,var(--accent) 72%,var(--glass-edge)); border-radius:7px; background:linear-gradient(120deg,color-mix(in srgb,var(--accent) 78%,transparent),color-mix(in srgb,var(--accent) 95%,transparent)); -webkit-backdrop-filter:blur(14px) saturate(155%); backdrop-filter:blur(14px) saturate(155%); box-shadow:inset 0 1px 0 rgba(255,255,255,.45),0 8px 18px color-mix(in srgb,var(--accent) 25%,transparent); color:#fff; font-size:12px; font-weight:700; cursor:pointer; transition:transform .2s ease,opacity .2s ease,box-shadow .2s ease; }
	[data-theme="dark"] .submit-button { color:#172219; }
	.submit-button:hover { transform:translateY(-1px); opacity:.94; box-shadow:0 11px 22px color-mix(in srgb,var(--accent) 31%,transparent); }
	.submit-button:focus-visible { outline:3px solid color-mix(in srgb,var(--accent) 40%,transparent); outline-offset:3px; }
	.footnote { margin:18px 0 0; color:var(--muted); font-size:10px; line-height:1.6; text-align:center; }
	@keyframes arrive { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
	@keyframes guitar-drift { from { transform:scale(1.04) translate3d(-.4%,.2%,0); } to { transform:scale(1.1) translate3d(.4%,-.3%,0); } }
	@media (max-width:480px) { body { padding:16px; } .login-panel { padding:25px 20px; } }
	@media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms!important; animation-iteration-count:1!important; transition-duration:.01ms!important; } }
  </style>
</head>
<body>
  <main class="login-shell">
	<div class="brand"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>JamesGuitars</div>
	<section class="login-panel" aria-labelledby="page-title">
	  <p class="eyebrow"><?= $setupMode ? 'One-time setup' : 'JamesGuitars account' ?></p>
	  <h1 id="page-title"><?= $setupMode ? 'Create your account' : 'Welcome back' ?></h1>
	  <p class="intro"><?= $setupMode ? 'Create an account to shop or manage the guitar collection.' : 'Choose your account type to continue.' ?></p>
	  <?php if ($databaseError !== ''): ?><p class="form-message" role="alert"><?= escapeLoginHtml($databaseError) ?></p><?php endif; ?>
	  <?php if ($error !== ''): ?><p class="form-message" role="alert"><?= escapeLoginHtml($error) ?></p><?php endif; ?>
	  <form method="post" action="loginpage.php">
		<input type="hidden" name="csrf_token" value="<?= escapeLoginHtml($_SESSION['csrf_token']) ?>">
		<fieldset class="field role-field"><legend>Continue as</legend><div class="role-options">
			<label class="role-option"><input type="radio" name="role" value="customer" <?= $role === 'customer' ? 'checked' : '' ?>><span>Customer</span></label>
			<label class="role-option"><input type="radio" name="role" value="staff" <?= $role === 'staff' ? 'checked' : '' ?>><span>Staff</span></label>
			<label class="role-option"><input type="radio" name="role" value="owner" <?= $role === 'owner' ? 'checked' : '' ?>><span>Owner</span></label>
		</div></fieldset>
		<div class="field verification-field" id="verification-field" <?= $role === 'customer' ? 'hidden' : '' ?>><label for="verification_code">Staff / owner verification number</label><input id="verification_code" name="verification_code" type="password" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="off" placeholder="6-digit number" <?= $role !== 'customer' ? 'required' : '' ?>></div>
		<div class="field"><label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" placeholder="you@example.com" value="<?= escapeLoginHtml($email) ?>" required></div>
		<div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="<?= $setupMode ? 'new-password' : 'current-password' ?>" required><?php if ($setupMode): ?><p class="footnote">Use at least 10 characters.</p><?php endif; ?></div>
		<?php if ($setupMode): ?><div class="field"><label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div><?php endif; ?>
		<button class="submit-button" type="submit"><?= $setupMode ? 'Create account' : 'Sign in' ?></button>
	  </form>
	</section>
	<p class="footnote">Your password is stored securely as a one-way hash.</p>
  </main>
  <script>
	const savedTheme = localStorage.getItem('goodtone-theme');
	if (savedTheme === 'dark' || savedTheme === 'light') document.documentElement.dataset.theme = savedTheme;
	const roleInputs = document.querySelectorAll('input[name="role"]');
	const verificationField = document.getElementById('verification-field');
	const verificationInput = document.getElementById('verification_code');
	roleInputs.forEach((input) => input.addEventListener('change', () => {
		const needsVerification = input.checked && input.value !== 'customer';
		if (input.checked) {
			verificationField.hidden = !needsVerification;
			verificationInput.required = needsVerification;
			if (!needsVerification) verificationInput.value = '';
		}
	}));
  </script>
</body>
</html>
