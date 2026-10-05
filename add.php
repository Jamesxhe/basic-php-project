<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireLogin();

function escapeHtml(string $value): string
{
		return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$categories = ['acoustic' => 'Acoustic guitar', 'bass' => 'Bass', 'electric' => 'Electric'];
$values = ['name' => '', 'category' => '', 'brand' => '', 'model' => '', 'price' => '', 'quantity' => ''];
$errors = [];
$databaseError = '';
$conn = null;

try {
		require __DIR__ . '/conn.php';
} catch (Throwable $exception) {
		$databaseError = 'Could not connect to the inventory database. Check that MySQL is running and the mydb database exists.';
}

if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		foreach ($values as $field => $value) {
				$postedValue = $_POST[$field] ?? '';
				$values[$field] = is_string($postedValue) ? trim($postedValue) : '';
		}

		$submittedToken = $_POST['csrf_token'] ?? '';
		if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
				$errors['_form'] = 'This form expired. Refresh the page and try again.';
		}
		if ($values['name'] === '' || strlen($values['name']) > 150) {
				$errors['name'] = 'Enter a product name (up to 150 characters).';
		}
		if (!array_key_exists($values['category'], $categories)) {
				$errors['category'] = 'Choose an instrument type.';
		}
		foreach (['brand' => 100, 'model' => 100] as $field => $maxLength) {
				if (strlen($values[$field]) > $maxLength) {
						$errors[$field] = 'This field is too long.';
				}
		}

		$price = filter_var($values['price'], FILTER_VALIDATE_FLOAT);
		if ($price === false || $price < 0 || $price > 99999999.99) {
				$errors['price'] = 'Enter a valid price of 0 or more.';
		}
		$quantity = filter_var($values['quantity'], FILTER_VALIDATE_INT);
		if ($quantity === false || $quantity < 0) {
				$errors['quantity'] = 'Enter a whole quantity of 0 or more.';
		}
		$temporaryImagePath = '';
		$imageExtension = '';
		$uploadedImage = $_FILES['image'] ?? null;
		if (is_array($uploadedImage) && ($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
				if (($uploadedImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
						$errors['image'] = 'The image could not be uploaded.';
				} elseif (($uploadedImage['size'] ?? 0) > 5 * 1024 * 1024) {
						$errors['image'] = 'Choose an image smaller than 5 MB.';
				} elseif (!is_string($uploadedImage['tmp_name'] ?? null) || !is_uploaded_file($uploadedImage['tmp_name'])) {
						$errors['image'] = 'Choose a valid image file.';
				} else {
						$imageTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
						$imageInfo = new finfo(FILEINFO_MIME_TYPE);
						$imageMimeType = $imageInfo->file($uploadedImage['tmp_name']);
						if (!isset($imageTypes[$imageMimeType]) || getimagesize($uploadedImage['tmp_name']) === false) {
							$errors['image'] = 'Use a valid JPG, PNG, or WebP image.';
						} else {
							$temporaryImagePath = $uploadedImage['tmp_name'];
							$imageExtension = $imageTypes[$imageMimeType];
						}
				}
		}

		if ($errors === [] && $conn instanceof mysqli) {
				$storedImagePath = '';
				try {
						if ($temporaryImagePath !== '') {
							$uploadDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
							if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
								throw new RuntimeException('Could not create image storage directory.');
							}
							$imageFilename = bin2hex(random_bytes(16)) . '.' . $imageExtension;
							$storedImagePath = 'uploads/products/' . $imageFilename;
							if (!move_uploaded_file($temporaryImagePath, __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedImagePath))) {
								throw new RuntimeException('Could not store uploaded image.');
							}
						}
						$statement = $conn->prepare(
							'INSERT INTO products (name, category, brand, model, image_path, price, quantity) VALUES (?, ?, ?, ?, ?, ?, ?)'
						);
						$statement->bind_param('sssssdi', $values['name'], $values['category'], $values['brand'], $values['model'], $storedImagePath, $price, $quantity);
						$statement->execute();
						header('Location: inventory.php?added=1');
						exit;
				} catch (Throwable $exception) {
						if ($storedImagePath !== '') {
							$storedImageFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedImagePath);
							if (is_file($storedImageFile)) {
								unlink($storedImageFile);
							}
						}
						$errors['_form'] = 'The product could not be saved. Please check the database and try again.';
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
	<title>Add Product | JamesGuitars</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
	<style>
		:root { color-scheme: light; --page:#f5f7f4; --panel:#fff; --panel-soft:#edf1ec; --ink:#202b25; --muted:#7b867e; --line:#e4e9e3; --accent:#276b4a; --accent-wash:#e7f2e8; --glass-bg:rgba(255,255,255,.64); --glass-edge:rgba(255,255,255,.78); --danger:#b44236; --shadow:0 18px 44px rgba(28,49,36,.07); }
		[data-theme="dark"] { color-scheme:dark; --page:#171d19; --panel:#202923; --panel-soft:#29352d; --ink:#edf3ed; --muted:#9ca99f; --line:#354139; --accent:#a8d7a9; --accent-wash:#2e4435; --glass-bg:rgba(32,41,35,.72); --glass-edge:rgba(222,243,224,.23); --danger:#ff9b8f; --shadow:0 18px 44px rgba(0,0,0,.18); }
		* { box-sizing:border-box; }
		body { position:relative; isolation:isolate; min-width:320px; min-height:100vh; margin:0; overflow-x:hidden; background:var(--page); color:var(--ink); font-family:"DM Sans",sans-serif; transition:background .3s ease,color .3s ease; }
		body::before { position:fixed; z-index:-1; inset:-5%; background-image:radial-gradient(ellipse at 15% 18%,color-mix(in srgb,var(--accent-wash) 72%,transparent),transparent 44%),linear-gradient(110deg,color-mix(in srgb,var(--page) 38%,transparent),color-mix(in srgb,var(--page) 60%,transparent)),url("https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=2000&q=85"); background-position:center,center,55% 48%; background-size:cover; background-repeat:no-repeat; content:""; animation:guitar-drift 34s ease-in-out infinite alternate; }
		button,input,select { font:inherit; }
		.app-shell { display:grid; grid-template-columns:238px minmax(0,1fr); max-width:1600px; min-height:100vh; margin:0 auto; }
		.sidebar { display:flex; flex-direction:column; padding:30px 20px 24px; border-right:1px solid var(--line); }
		.brand { display:flex; align-items:center; gap:11px; margin:0 0 52px 8px; font-family:"Space Grotesk",sans-serif; font-size:21px; font-weight:700; }
		.brand-mark { display:grid; width:34px; height:34px; place-items:center; border-radius:11px; background:var(--accent); color:#fff; }
		[data-theme="dark"] .brand-mark { color:#172219; }
		.brand-mark svg { width:20px; height:20px; }
		.nav-label { margin:0 0 12px 11px; color:var(--muted); font-size:10px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; }
		.nav-item { display:flex; align-items:center; gap:12px; min-height:44px; padding:0 12px; border-radius:8px; color:var(--muted); font-size:13px; font-weight:600; text-decoration:none; }
		.nav-item.active { background:var(--accent-wash); color:var(--accent); }
		.nav-item svg { width:17px; height:17px; }
		.sidebar-bottom { margin-top:auto; }
		.store-status { display:flex; align-items:center; gap:10px; padding:13px 12px; border:1px solid var(--line); border-radius:8px; color:var(--muted); font-size:11px; }
		.status-dot { width:8px; height:8px; border-radius:50%; background:#63ad78; }
		main { min-width:0; padding:28px 42px 48px; }
		.topbar { display:flex; align-items:center; justify-content:space-between; gap:16px; padding-bottom:27px; border-bottom:1px solid var(--line); }
		.breadcrumbs { color:var(--muted); font-size:12px; }
		.breadcrumbs a { color:inherit; text-decoration:none; }
		.breadcrumbs strong { color:var(--ink); font-weight:600; }
		.top-actions { display:flex; align-items:center; gap:12px; }
		.icon-button { display:grid; width:40px; height:40px; place-items:center; border:1px solid var(--glass-edge); border-radius:50%; background:var(--glass-bg); -webkit-backdrop-filter:blur(12px) saturate(145%); backdrop-filter:blur(12px) saturate(145%); box-shadow:inset 0 1px 0 rgba(255,255,255,.45),0 5px 16px rgba(22,42,29,.08); cursor:pointer; transition:transform .2s ease,border-color .2s ease; }
		.icon-button:hover { transform:translateY(-2px); border-color:var(--accent); }
		.icon-button svg { width:17px; height:17px; }
		.logout-form { margin:0; }
		.logout-button { min-height:38px; padding:0 12px; border:1px solid var(--glass-edge); border-radius:6px; background:var(--glass-bg); color:var(--ink); -webkit-backdrop-filter:blur(12px) saturate(145%); backdrop-filter:blur(12px) saturate(145%); box-shadow:inset 0 1px 0 rgba(255,255,255,.45),0 5px 16px rgba(22,42,29,.08); font-size:11px; font-weight:700; cursor:pointer; }
		.logout-button:hover { border-color:var(--accent); }
		.sun-icon { display:none; }
		[data-theme="dark"] .sun-icon { display:block; }
		[data-theme="dark"] .moon-icon { display:none; }
		.avatar { display:grid; width:38px; height:38px; place-items:center; border-radius:50%; background:#e5c5a9; color:#493526; font-size:12px; font-weight:700; }
		.page-heading { margin:35px 0 25px; animation:rise-in .5s both; }
		.eyebrow { margin:0 0 8px; color:var(--accent); font-size:10px; font-weight:700; letter-spacing:1.6px; text-transform:uppercase; }
		h1,p { margin-top:0; }
		h1 { margin-bottom:7px; font-family:"Space Grotesk",sans-serif; font-size:34px; line-height:1.1; }
		.subtitle { margin:0; color:var(--muted); font-size:13px; }
		.form-panel { max-width:820px; padding:25px; border:1px solid var(--glass-edge); border-radius:12px; background:var(--glass-bg); -webkit-backdrop-filter:blur(22px) saturate(150%); backdrop-filter:blur(22px) saturate(150%); box-shadow:inset 0 1px 0 rgba(255,255,255,.58),var(--shadow); animation:rise-in .55s .08s both; }
		.form-heading { margin-bottom:22px; padding-bottom:17px; border-bottom:1px solid var(--line); }
		.form-heading h2 { margin:0 0 5px; font-family:"Space Grotesk",sans-serif; font-size:18px; }
		.form-heading p { margin:0; color:var(--muted); font-size:11px; }
		.form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px 16px; }
		.field { min-width:0; }
		.field.full { grid-column:1/-1; }
		.field label { display:block; margin-bottom:7px; font-size:11px; font-weight:700; }
		.required { color:var(--accent); }
		.field input,.field select { width:100%; height:42px; padding:0 12px; border:1px solid var(--line); border-radius:6px; outline:none; background:var(--panel); color:var(--ink); font-size:12px; }
		.field input:focus,.field select:focus { border-color:var(--accent); box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 16%,transparent); }
		.field input::placeholder { color:var(--muted); }
		.field input[type="file"] { height:auto; min-height:42px; padding:8px; }
		.field input[type="file"]::file-selector-button { margin-right:10px; padding:6px 9px; border:0; border-radius:4px; background:var(--panel-soft); color:var(--ink); font-size:11px; cursor:pointer; }
		.field-hint { margin:7px 0 0; color:var(--muted); font-size:10px; }
		.image-preview { display:none; max-width:220px; max-height:160px; margin-top:10px; overflow:hidden; border:1px solid var(--line); border-radius:6px; }
		.image-preview.visible { display:block; }
		.image-preview img { display:block; max-width:100%; max-height:160px; object-fit:cover; }
		.field select:invalid { color:var(--muted); }
		.field-error { margin:6px 0 0; color:var(--danger); font-size:10px; }
		.form-error { margin:0 0 18px; padding:11px 13px; border:1px solid color-mix(in srgb,var(--danger) 35%,transparent); border-radius:6px; background:color-mix(in srgb,var(--danger) 8%,var(--panel)); color:var(--danger); font-size:12px; }
		.form-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:24px; padding-top:18px; border-top:1px solid var(--line); }
		.button { display:inline-flex; min-height:40px; align-items:center; justify-content:center; gap:8px; padding:0 15px; border:1px solid var(--glass-edge); border-radius:7px; background:var(--glass-bg); color:var(--ink); -webkit-backdrop-filter:blur(12px) saturate(145%); backdrop-filter:blur(12px) saturate(145%); box-shadow:inset 0 1px 0 rgba(255,255,255,.5),0 5px 16px rgba(22,42,29,.08); font-size:11px; font-weight:700; text-decoration:none; cursor:pointer; transition:transform .2s ease,box-shadow .2s ease; }
		.button:hover { transform:translateY(-1px); box-shadow:inset 0 1px 0 rgba(255,255,255,.58),0 8px 20px rgba(22,42,29,.12); }
		.button.primary { border-color:color-mix(in srgb,var(--accent) 72%,white); background:linear-gradient(125deg,color-mix(in srgb,var(--accent) 78%,transparent),color-mix(in srgb,var(--accent) 95%,transparent)); color:#fff; box-shadow:inset 0 1px 0 rgba(255,255,255,.46),0 8px 20px color-mix(in srgb,var(--accent) 24%,transparent); }
		[data-theme="dark"] .button.primary { color:#172219; }
		.button:focus-visible,.icon-button:focus-visible,.logout-button:focus-visible { outline:3px solid color-mix(in srgb,var(--accent) 40%,transparent); outline-offset:3px; }
		.button svg { width:15px; height:15px; }
		@keyframes rise-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
		@keyframes guitar-drift { from { transform:scale(1.04) translate3d(-.4%,.2%,0); } to { transform:scale(1.1) translate3d(.4%,-.3%,0); } }
		@media (max-width:850px) { .app-shell { grid-template-columns:205px minmax(0,1fr); } main { padding-right:26px; padding-left:26px; } }
		@media (max-width:650px) { .app-shell { display:block; } .sidebar { display:none; } main { padding:18px 16px 32px; } .topbar { padding-bottom:18px; } .page-heading { margin:27px 0 20px; } h1 { font-size:29px; } .form-panel { padding:18px; } }
		@media (max-width:440px) { .form-grid { grid-template-columns:1fr; gap:15px; } .field.full { grid-column:auto; } .form-actions { flex-direction:column-reverse; } .form-actions .button { width:100%; } }
		@media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms!important; animation-iteration-count:1!important; transition-duration:.01ms!important; } }
	</style>
</head>
<body>
	<div class="app-shell">
		<aside class="sidebar" aria-label="Main navigation">
			<div class="brand"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>JamesGuitars</div>
			<p class="nav-label">Workspace</p>
			<a class="nav-item" href="inventory.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z"/></svg>Inventory</a>
			<a class="nav-item active" href="add.php" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>Add product</a>
			<div class="sidebar-bottom"><div class="store-status"><span class="status-dot"></span>Main store · Open</div></div>
		</aside>
		<main>
			<header class="topbar">
				<div class="breadcrumbs"><a href="inventory.php">Inventory</a> <span aria-hidden="true">/</span> <strong>Add product</strong></div>
				<div class="top-actions">
					<button class="icon-button" id="theme-toggle" type="button" aria-label="Switch to dark mode" title="Switch color mode">
						<svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 14.3A8.5 8.5 0 0 1 9.7 3.5 8.5 8.5 0 1 0 20.5 14.3z"/></svg>
						<svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
					</button>
					<div class="avatar" aria-label="Signed in as <?= escapeHtml((string) ($_SESSION['user_email'] ?? '')) ?>"><?= escapeHtml(strtoupper(substr((string) ($_SESSION['user_email'] ?? 'U'), 0, 1))) ?></div>
					<form class="logout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><button class="logout-button" type="submit">Sign out</button></form>
				</div>
			</header>
			<section class="page-heading" aria-labelledby="page-title">
				<p class="eyebrow">Inventory management</p>
				<h1 id="page-title">Add a product</h1>
				<p class="subtitle">Enter the instrument details to add it to your inventory.</p>
			</section>
			<section class="form-panel" aria-labelledby="details-title">
				<div class="form-heading"><h2 id="details-title">Product details</h2><p>Fields marked <span class="required">*</span> are required.</p></div>
				<?php if ($databaseError !== ''): ?><p class="form-error" role="alert"><?= escapeHtml($databaseError) ?></p><?php endif; ?>
				<?php if (isset($errors['_form'])): ?><p class="form-error" role="alert"><?= escapeHtml($errors['_form']) ?></p><?php endif; ?>
				<form method="post" action="add.php" enctype="multipart/form-data" novalidate>
					<input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
					<div class="form-grid">
						<div class="field full"><label for="name">Product name <span class="required">*</span></label><input id="name" name="name" type="text" maxlength="150" placeholder="Product name" value="<?= escapeHtml($values['name']) ?>" required><?php if (isset($errors['name'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['name']) ?></p><?php endif; ?></div>
						<div class="field"><label for="category">Instrument type <span class="required">*</span></label><select id="category" name="category" required><option value="" disabled <?= $values['category'] === '' ? 'selected' : '' ?>>Choose a type</option><?php foreach ($categories as $value => $label): ?><option value="<?= escapeHtml($value) ?>" <?= $values['category'] === $value ? 'selected' : '' ?>><?= escapeHtml($label) ?></option><?php endforeach; ?></select><?php if (isset($errors['category'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['category']) ?></p><?php endif; ?></div>
						<div class="field"><label for="brand">Brand</label><input id="brand" name="brand" type="text" maxlength="100" placeholder="Brand" value="<?= escapeHtml($values['brand']) ?>"><?php if (isset($errors['brand'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['brand']) ?></p><?php endif; ?></div>
						<div class="field"><label for="model">Model</label><input id="model" name="model" type="text" maxlength="100" placeholder="Model" value="<?= escapeHtml($values['model']) ?>"><?php if (isset($errors['model'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['model']) ?></p><?php endif; ?></div>
						<div class="field full"><label for="image">Product image</label><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="image-hint"><?php if (isset($errors['image'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['image']) ?></p><?php endif; ?><p class="field-hint" id="image-hint">JPG, PNG, or WebP. Maximum size 5 MB.</p><div class="image-preview" id="image-preview"><img id="image-preview-img" alt="Selected product image preview"></div></div>
						<div class="field"><label for="price">Unit price <span class="required">*</span></label><input id="price" name="price" type="number" min="0" max="99999999.99" step="0.01" placeholder="0.00" value="<?= escapeHtml($values['price']) ?>" required><?php if (isset($errors['price'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['price']) ?></p><?php endif; ?></div>
						<div class="field"><label for="quantity">Quantity <span class="required">*</span></label><input id="quantity" name="quantity" type="number" min="0" step="1" placeholder="0" value="<?= escapeHtml($values['quantity']) ?>" required><?php if (isset($errors['quantity'])): ?><p class="field-error" role="alert"><?= escapeHtml($errors['quantity']) ?></p><?php endif; ?></div>
					</div>
					<div class="form-actions"><a class="button" href="inventory.php">Cancel</a><button class="button primary" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Save product</button></div>
				</form>
			</section>
		</main>
	</div>
	<script>
		const themeToggle = document.getElementById('theme-toggle');
		const imageInput = document.getElementById('image');
		const imagePreview = document.getElementById('image-preview');
		const imagePreviewImg = document.getElementById('image-preview-img');
		let previewUrl = '';

		imageInput.addEventListener('change', () => {
			if (previewUrl) URL.revokeObjectURL(previewUrl);
			const [file] = imageInput.files;
			if (!file) {
				imagePreview.classList.remove('visible');
				return;
			}
			previewUrl = URL.createObjectURL(file);
			imagePreviewImg.src = previewUrl;
			imagePreview.classList.add('visible');
		});

		function setTheme(theme) {
			document.documentElement.dataset.theme = theme;
			themeToggle.setAttribute('aria-label', `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`);
			localStorage.setItem('goodtone-theme', theme);
		}
		setTheme(localStorage.getItem('goodtone-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
		themeToggle.addEventListener('click', () => setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark'));
	</script>
</body>
</html>
