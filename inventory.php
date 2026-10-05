<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireLogin();
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$products = [];
$databaseError = '';
$deleteError = '';
$productRemoved = ($_GET['removed'] ?? '') === '1';
$totalQuantity = 0;
$inventoryValue = 0.0;
$lowStockCount = 0;
$categoryLabels = ['acoustic' => 'Acoustic guitar', 'bass' => 'Bass', 'electric' => 'Electric'];

try {
	require __DIR__ . '/conn.php';
	if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
		$submittedToken = $_POST['csrf_token'] ?? '';
		$postedId = $_POST['product_id'] ?? '';
		$productId = is_string($postedId) ? filter_var($postedId, FILTER_VALIDATE_INT) : false;

		if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
			$deleteError = 'This request expired. Refresh the page and try again.';
		} elseif ($productId === false || $productId < 1) {
			$deleteError = 'Select a valid product to remove.';
		} else {
			$findProduct = $conn->prepare('SELECT image_path FROM products WHERE id = ?');
			$findProduct->bind_param('i', $productId);
			$findProduct->execute();
			$productToDelete = $findProduct->get_result()->fetch_assoc();
			$findProduct->close();

			if ($productToDelete === null) {
				$deleteError = 'That product no longer exists.';
			} else {
				$deleteProduct = $conn->prepare('DELETE FROM products WHERE id = ?');
				$deleteProduct->bind_param('i', $productId);
				$deleteProduct->execute();
				$deleteProduct->close();

				$relativeImagePath = (string) $productToDelete['image_path'];
				if ($relativeImagePath !== '' && preg_match('~^uploads/products/[a-f0-9]{32}\\.(?:jpg|png|webp)$~D', $relativeImagePath) === 1) {
					$references = $conn->prepare('SELECT COUNT(*) FROM products WHERE image_path = ?');
					$references->bind_param('s', $relativeImagePath);
					$references->execute();
					$references->bind_result($referenceCount);
					$references->fetch();
					$references->close();
					$imageFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeImagePath);
					if ($referenceCount === 0 && is_file($imageFile)) {
						unlink($imageFile);
					}
				}

				header('Location: inventory.php?removed=1');
				exit;
			}
		}
	}

	$result = $conn->query('SELECT id, name, category, brand, model, image_path, price, quantity FROM products ORDER BY created_at DESC, id DESC');
	while ($product = $result->fetch_assoc()) {
		$product['quantity'] = (int) $product['quantity'];
		$product['price'] = (float) $product['price'];
		$products[] = $product;
		$totalQuantity += $product['quantity'];
		$inventoryValue += $product['price'] * $product['quantity'];
		if ($product['quantity'] <= 2) {
			$lowStockCount++;
		}
	}
} catch (Throwable $exception) {
	$databaseError = 'Could not load inventory. Check that MySQL is running and the mydb database exists.';
}

function escapeHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#f5f7f4">
	<title>Inventory | JamesGuitars</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
	<style>
		:root {
			color-scheme: light;
			--page: #f5f7f4;
			--panel: #ffffff;
			--panel-soft: #edf1ec;
			--ink: #202b25;
			--muted: #7b867e;
			--line: #e4e9e3;
			--accent: #276b4a;
			--accent-bright: #d7f36b;
			--accent-wash: #e7f2e8;
			--glass-bg: rgba(255, 255, 255, .64);
			--glass-edge: rgba(255, 255, 255, .78);
			--danger: #b44236;
			--shadow: 0 18px 44px rgba(28, 49, 36, .07);
		}

		[data-theme="dark"] {
			color-scheme: dark;
			--page: #171d19;
			--panel: #202923;
			--panel-soft: #29352d;
			--ink: #edf3ed;
			--muted: #9ca99f;
			--line: #354139;
			--accent: #a8d7a9;
			--accent-bright: #d7f36b;
			--accent-wash: #2e4435;
			--glass-bg: rgba(32, 41, 35, .72);
			--glass-edge: rgba(222, 243, 224, .23);
			--danger: #ff9b8f;
			--shadow: 0 18px 44px rgba(0, 0, 0, .18);
		}

		* { box-sizing: border-box; }

		body {
			position: relative;
			isolation: isolate;
			min-width: 320px;
			min-height: 100vh;
			margin: 0;
			overflow-x: hidden;
			background: var(--page);
			color: var(--ink);
			font-family: "DM Sans", sans-serif;
			transition: background .3s ease, color .3s ease;
		}

		body::before {
			position: fixed;
			z-index: -1;
			inset: -5%;
			background-image: radial-gradient(ellipse at 15% 18%, color-mix(in srgb, var(--accent-wash) 72%, transparent), transparent 44%), linear-gradient(110deg, color-mix(in srgb, var(--page) 38%, transparent), color-mix(in srgb, var(--page) 60%, transparent)), url("https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=2000&q=85");
			background-position: center, center, 55% 48%;
			background-size: cover;
			background-repeat: no-repeat;
			content: "";
			animation: guitar-drift 34s ease-in-out infinite alternate;
		}

		button, input { font: inherit; }
		button { color: inherit; }

		.app-shell {
			display: grid;
			grid-template-columns: 238px minmax(0, 1fr);
			max-width: 1600px;
			min-height: 100vh;
			margin: 0 auto;
		}

		.sidebar {
			display: flex;
			flex-direction: column;
			padding: 30px 20px 24px;
			border-right: 1px solid var(--glass-edge);
			background: var(--glass-bg);
			-webkit-backdrop-filter: blur(18px) saturate(145%);
			backdrop-filter: blur(18px) saturate(145%);
		}

		.brand {
			display: flex;
			align-items: center;
			gap: 11px;
			margin: 0 0 52px 8px;
			font-family: "Space Grotesk", sans-serif;
			font-size: 21px;
			font-weight: 700;
		}

		.brand-mark {
			display: grid;
			width: 34px;
			height: 34px;
			place-items: center;
			border-radius: 11px;
			background: var(--accent);
			color: #fff;
		}

		[data-theme="dark"] .brand-mark { color: #172219; }

		.brand-mark svg { width: 20px; height: 20px; }

		.nav-label {
			margin: 0 0 12px 11px;
			color: var(--muted);
			font-size: 10px;
			font-weight: 700;
			letter-spacing: 1.4px;
			text-transform: uppercase;
		}

		.nav-item {
			display: flex;
			align-items: center;
			gap: 12px;
			min-height: 44px;
			padding: 0 12px;
			border-radius: 8px;
			color: var(--muted);
			font-size: 13px;
			font-weight: 600;
		}

		.nav-item.active { border: 1px solid var(--glass-edge); background: color-mix(in srgb, var(--accent-wash) 72%, transparent); color: var(--accent); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); }
		.nav-item svg { width: 17px; height: 17px; }

		.sidebar-bottom { margin-top: auto; }

		.store-status {
			display: flex;
			align-items: center;
			gap: 10px;
			margin: 0 0 18px;
			padding: 13px 12px;
			border: 1px solid var(--glass-edge);
			border-radius: 8px;
			background: var(--glass-bg);
			color: var(--muted);
			font-size: 11px;
			-webkit-backdrop-filter: blur(12px) saturate(145%);
			backdrop-filter: blur(12px) saturate(145%);
		}

		.status-dot, .stock-dot {
			width: 8px;
			height: 8px;
			flex: 0 0 auto;
			border-radius: 50%;
			background: #63ad78;
		}

		main { min-width: 0; padding: 28px 42px 48px; }

		.topbar {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 16px;
			padding-bottom: 27px;
			border-bottom: 1px solid var(--line);
		}

		.breadcrumbs { color: var(--muted); font-size: 12px; }
		.breadcrumbs strong { color: var(--ink); font-weight: 600; }

		.top-actions { display: flex; align-items: center; gap: 12px; }
		.logout-form { margin: 0; }
		.logout-button { min-height: 38px; padding: 0 12px; border: 1px solid var(--glass-edge); border-radius: 6px; background: var(--glass-bg); color: var(--ink); -webkit-backdrop-filter: blur(12px) saturate(145%); backdrop-filter: blur(12px) saturate(145%); box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 5px 16px rgba(22,42,29,.08); font-size: 11px; font-weight: 700; cursor: pointer; }
		.logout-button:hover { border-color: var(--accent); }

		.icon-button {
			display: grid;
			width: 40px;
			height: 40px;
			place-items: center;
			border: 1px solid var(--glass-edge);
			border-radius: 50%;
			background: var(--glass-bg);
			-webkit-backdrop-filter: blur(12px) saturate(145%);
			backdrop-filter: blur(12px) saturate(145%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 5px 16px rgba(22,42,29,.08);
			cursor: pointer;
			transition: transform .2s ease, border-color .2s ease;
		}

		.icon-button:hover { transform: translateY(-2px); border-color: var(--accent); }
		.icon-button svg { width: 17px; height: 17px; }
		.sun-icon { display: none; }
		[data-theme="dark"] .sun-icon { display: block; }
		[data-theme="dark"] .moon-icon { display: none; }

		.avatar {
			display: grid;
			width: 38px;
			height: 38px;
			place-items: center;
			border-radius: 50%;
			background: #e5c5a9;
			color: #493526;
			font-size: 12px;
			font-weight: 700;
		}

		.page-heading {
			display: flex;
			align-items: flex-end;
			justify-content: space-between;
			gap: 20px;
			margin: 35px 0 25px;
			animation: rise-in .55s both;
		}

		.add-button {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			gap: 9px;
			min-height: 42px;
			padding: 0 16px;
			border-radius: 7px;
			border: 1px solid color-mix(in srgb, var(--accent) 72%, var(--glass-edge));
			background: linear-gradient(120deg, color-mix(in srgb, var(--accent) 78%, transparent), color-mix(in srgb, var(--accent) 95%, transparent));
			color: #fff;
			font-size: 12px;
			font-weight: 700;
			text-decoration: none;
			-webkit-backdrop-filter: blur(14px) saturate(155%);
			backdrop-filter: blur(14px) saturate(155%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 8px 18px color-mix(in srgb, var(--accent) 25%, transparent);
			transition: transform .2s ease, opacity .2s ease, box-shadow .2s ease;
		}

		[data-theme="dark"] .add-button { color: #172219; }
		.add-button:hover { transform: translateY(-2px); opacity: .94; box-shadow: 0 11px 22px color-mix(in srgb, var(--accent) 31%, transparent); }
		.add-button:focus-visible { outline: 3px solid color-mix(in srgb, var(--accent) 40%, transparent); outline-offset: 3px; }
		.add-button svg { width: 16px; height: 16px; }

		.eyebrow {
			margin: 0 0 8px;
			color: var(--accent);
			font-size: 10px;
			font-weight: 700;
			letter-spacing: 1.6px;
			text-transform: uppercase;
		}

		h1, h2, p { margin-top: 0; }
		h1 {
			margin-bottom: 7px;
			font-family: "Space Grotesk", sans-serif;
			font-size: 34px;
			line-height: 1.1;
		}

		.subtitle { margin: 0; color: var(--muted); font-size: 13px; }

		.summary-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 14px;
			margin-bottom: 30px;
		}

		.summary {
			position: relative;
			min-height: 108px;
			overflow: hidden;
			padding: 17px 19px;
			border: 1px solid var(--glass-edge);
			border-radius: 9px;
			background: var(--glass-bg);
			-webkit-backdrop-filter: blur(18px) saturate(150%);
			backdrop-filter: blur(18px) saturate(150%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.5);
			animation: rise-in .55s both;
		}

		.summary:nth-child(2) { animation-delay: .07s; }
		.summary:nth-child(3) { animation-delay: .14s; }
		.summary-label { margin: 0 0 10px; color: var(--muted); font-size: 11px; font-weight: 600; }
		.summary-value { margin: 0; font-family: "Space Grotesk", sans-serif; font-size: 26px; font-weight: 600; }
		.summary-note { position: absolute; top: 18px; right: 18px; color: var(--accent); font-size: 10px; font-weight: 700; }

		.inventory-panel {
			padding: 22px;
			border: 1px solid var(--glass-edge);
			border-radius: 9px;
			background: var(--glass-bg);
			-webkit-backdrop-filter: blur(22px) saturate(155%);
			backdrop-filter: blur(22px) saturate(155%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.58), var(--shadow);
			animation: rise-in .6s .12s both;
		}

		.panel-heading {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 18px;
			margin-bottom: 20px;
		}

		.panel-title { margin: 0 0 5px; font-family: "Space Grotesk", sans-serif; font-size: 18px; }
		.panel-description { margin: 0; color: var(--muted); font-size: 11px; }

		.search-box {
			display: flex;
			align-items: center;
			gap: 8px;
			width: min(230px, 40%);
			height: 38px;
			padding: 0 11px;
			border: 1px solid var(--glass-edge);
			border-radius: 7px;
			background: var(--glass-bg);
			color: var(--muted);
			-webkit-backdrop-filter: blur(12px) saturate(145%);
			backdrop-filter: blur(12px) saturate(145%);
		}

		.search-box svg { width: 15px; height: 15px; flex: 0 0 auto; }
		.search-box input { width: 100%; border: 0; outline: 0; background: transparent; color: var(--ink); font-size: 11px; }
		.search-box input::placeholder { color: var(--muted); }

		.filters {
			display: flex;
			gap: 5px;
			width: fit-content;
			max-width: 100%;
			margin-bottom: 20px;
			padding: 4px;
			border-radius: 8px;
			border: 1px solid var(--glass-edge);
			background: color-mix(in srgb, var(--glass-bg) 75%, transparent);
			-webkit-backdrop-filter: blur(16px) saturate(145%);
			backdrop-filter: blur(16px) saturate(145%);
		}

		.filter-button {
			min-height: 32px;
			padding: 0 12px;
			border: 0;
			border-radius: 6px;
			background: transparent;
			color: var(--muted);
			font-size: 11px;
			font-weight: 600;
			white-space: nowrap;
			cursor: pointer;
			transition: background .2s ease, color .2s ease, transform .2s ease;
		}

		.filter-button:hover { transform: translateY(-1px); color: var(--ink); }
		.filter-button[aria-pressed="true"] { border: 1px solid var(--glass-edge); background: var(--glass-bg); color: var(--ink); -webkit-backdrop-filter: blur(12px); backdrop-filter: blur(12px); box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 2px 8px rgba(20,35,25,.08); }

		.product-grid {
			display: grid;
			grid-template-columns: repeat(3, minmax(0, 1fr));
			gap: 15px;
		}

		.product-card {
			min-width: 0;
			overflow: hidden;
			border: 1px solid var(--glass-edge);
			border-radius: 8px;
			background: color-mix(in srgb, var(--glass-bg) 76%, transparent);
			-webkit-backdrop-filter: blur(16px) saturate(150%);
			backdrop-filter: blur(16px) saturate(150%);
			box-shadow: inset 0 1px 0 rgba(255,255,255,.48);
			animation: card-in .45s both;
			transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease;
		}

		.product-card:hover { transform: translateY(-4px); border-color: color-mix(in srgb, var(--accent) 45%, var(--line)); box-shadow: var(--shadow); }

		.product-image {
			position: relative;
			display: grid;
			height: 168px;
			overflow: hidden;
			background: var(--panel-soft);
			place-items: center;
		}

		.product-image img { width: 100%; height: 100%; object-fit: cover; }
		.product-art { display: grid; width: 62px; height: 62px; place-items: center; border-radius: 18px; background: var(--accent-wash); color: var(--accent); font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; transition: transform .3s ease; }
		.product-card:hover .product-art { transform: rotate(-5deg) scale(1.06); }

		.category-tag {
			position: absolute;
			top: 11px;
			left: 11px;
			padding: 6px 9px;
			border: 1px solid rgba(255, 255, 255, .4);
			border-radius: 5px;
			background: rgba(255, 255, 255, .9);
			color: #25372b;
			font-size: 9px;
			font-weight: 700;
		}

		.product-info { padding: 14px; }
		.product-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
		.product-name { margin: 0 0 4px; font-family: "Space Grotesk", sans-serif; font-size: 14px; font-weight: 600; }
		.product-model { margin: 0; color: var(--muted); font-size: 10px; }
		.product-price { flex: 0 0 auto; font-size: 12px; font-weight: 700; }

		.product-meta {
			display: flex;
			align-items: center;
			justify-content: space-between;
			gap: 8px;
			margin-top: 16px;
			padding-top: 11px;
			border-top: 1px solid var(--line);
			color: var(--muted);
			font-size: 10px;
		}

		.stock-label { display: inline-flex; align-items: center; gap: 6px; color: var(--ink); font-weight: 600; }
		.stock-dot.low { background: #e1a047; }
		.product-actions { display: flex; justify-content: flex-end; margin-top: 11px; }
		.delete-form { margin: 0; }
		.delete-button { display: inline-flex; min-height: 30px; align-items: center; gap: 6px; padding: 0 9px; border: 1px solid var(--glass-edge); border-radius: 5px; background: color-mix(in srgb, var(--glass-bg) 74%, transparent); color: var(--danger); -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); box-shadow: inset 0 1px 0 rgba(255,255,255,.4); font-size: 10px; font-weight: 700; cursor: pointer; transition: background .2s ease, border-color .2s ease; }
		.delete-button:hover { border-color: var(--danger); background: color-mix(in srgb, var(--danger) 8%, transparent); }
		.delete-button svg { width: 13px; height: 13px; }

		.empty-state { grid-column: 1 / -1; padding: 40px 12px; color: var(--muted); text-align: center; font-size: 13px; }
		.empty-state[hidden] { display: none; }
		.empty-state a { display: inline-block; margin-top: 9px; color: var(--accent); font-weight: 700; text-decoration: none; }
		.notice { margin: -8px 0 20px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 7px; background: var(--accent-wash); color: var(--accent); font-size: 12px; }
		.database-error { background: var(--panel); color: #b44236; }

		@keyframes rise-in {
			from { opacity: 0; transform: translateY(12px); }
			to { opacity: 1; transform: translateY(0); }
		}

		@keyframes card-in {
			from { opacity: 0; transform: translateY(9px) scale(.99); }
			to { opacity: 1; transform: translateY(0) scale(1); }
		}

		@keyframes guitar-drift {
			from { transform: scale(1.04) translate3d(-.4%, .2%, 0); }
			to { transform: scale(1.1) translate3d(.4%, -.3%, 0); }
		}

		@media (max-width: 1050px) {
			.app-shell { grid-template-columns: 205px minmax(0, 1fr); }
			main { padding-right: 26px; padding-left: 26px; }
			.product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
		}

		@media (max-width: 720px) {
			.app-shell { display: block; }
			.sidebar { display: none; }
			main { padding: 18px 18px 32px; }
			.topbar { padding-bottom: 18px; }
			.page-heading { align-items: flex-start; margin: 27px 0 20px; }
			h1 { font-size: 29px; }
			.summary-grid { gap: 8px; margin-bottom: 20px; }
			.summary { min-height: 95px; padding: 13px 11px; }
			.summary-label { font-size: 10px; }
			.summary-value { font-size: 22px; }
			.summary-note { top: auto; right: 11px; bottom: 14px; font-size: 9px; }
			.inventory-panel { padding: 16px; }
			.panel-heading { align-items: flex-start; flex-direction: column; }
			.search-box { width: 100%; }
			.filters { width: 100%; overflow-x: auto; }
			.filter-button { flex: 1 0 auto; padding: 0 9px; }
			.product-image { height: 145px; }
		}

		@media (max-width: 480px) {
			main { padding-right: 13px; padding-left: 13px; }
			.page-heading { gap: 10px; }
			.product-grid { gap: 9px; }
			.product-image { height: 118px; }
			.product-info { padding: 10px; }
			.product-title-row { display: block; }
			.product-price { display: block; margin-top: 8px; }
			.product-name { font-size: 12px; }
			.product-meta { align-items: flex-start; flex-direction: column; gap: 5px; margin-top: 10px; }
			.category-tag { top: 7px; left: 7px; padding: 5px 7px; font-size: 8px; }
		}

		@media (prefers-reduced-motion: reduce) {
			*, *::before, *::after { scroll-behavior: auto !important; animation-duration: .01ms !important; animation-iteration-count: 1 !important; transition-duration: .01ms !important; }
		}
	</style>
</head>
<body>
	<div class="app-shell">
		<aside class="sidebar" aria-label="Main navigation">
			<div class="brand">
				<span class="brand-mark" aria-hidden="true">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
				</span>
				JamesGuitars
			</div>
			<p class="nav-label">Workspace</p>
			<div class="nav-item active" aria-current="page">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h8v8H3zM13 3h8v5h-8zM13 10h8v11h-8zM3 13h8v8H3z"/></svg>
				Inventory
			</div>
			<a class="nav-item" href="add.php">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
				Add product
			</a>
			<div class="sidebar-bottom">
				<div class="store-status"><span class="status-dot"></span> Main store · Open</div>
				<div class="nav-item">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.6 2.8-.2-.1a1.7 1.7 0 0 0-1.8.2 1.7 1.7 0 0 0-.8 1.5v.2h-3.2v-.2a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.1l-.2.1-1.6-2.8.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H6v-3.2h.2a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.6-2.8.2.1a1.7 1.7 0 0 0 1.8-.2 1.7 1.7 0 0 0 .8-1.5V3h3.2v.2a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.1l.2-.1 1.6 2.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.2v3.2h-.2a1.7 1.7 0 0 0-1.5 1.5z"/></svg>
					Settings
				</div>
			</div>
		</aside>

		<main>
			<header class="topbar">
				<div class="breadcrumbs">Workspace <span aria-hidden="true">/</span> <strong>Inventory</strong></div>
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
				<div>
					<p class="eyebrow"><?= escapeHtml(date('l, F j')) ?></p>
					<h1 id="page-title">Inventory</h1>
					<p class="subtitle">A clear view of every instrument in your store.</p>
				</div>
				<a class="add-button" href="add.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Add product</a>
			</section>
			<?php if ($databaseError !== ''): ?><p class="notice database-error" role="alert"><?= escapeHtml($databaseError) ?></p><?php endif; ?>
			<?php if (($_GET['added'] ?? '') === '1'): ?><p class="notice" role="status">Product added to inventory.</p><?php endif; ?>
			<?php if ($productRemoved): ?><p class="notice" role="status">Product removed from inventory.</p><?php endif; ?>
			<?php if ($deleteError !== ''): ?><p class="notice database-error" role="alert"><?= escapeHtml($deleteError) ?></p><?php endif; ?>

			<section class="summary-grid" aria-label="Inventory summary">
				<article class="summary"><p class="summary-label">Total instruments</p><p class="summary-value" id="total-count"><?= number_format($totalQuantity) ?></p><span class="summary-note">In stock</span></article>
				<article class="summary"><p class="summary-label">Inventory value</p><p class="summary-value">$<?= number_format($inventoryValue, 2) ?></p><span class="summary-note">Retail</span></article>
				<article class="summary"><p class="summary-label">Low stock</p><p class="summary-value" id="low-count"><?= number_format($lowStockCount) ?></p><span class="summary-note">Needs a look</span></article>
			</section>

			<section class="inventory-panel" aria-labelledby="catalog-title">
				<div class="panel-heading">
					<div>
						<h2 class="panel-title" id="catalog-title">Instrument catalog</h2>
						<p class="panel-description">Browse and keep track of your instruments.</p>
					</div>
					<label class="search-box">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
						<input id="search-input" type="search" placeholder="Search instruments" aria-label="Search instruments">
					</label>
				</div>

				<div class="filters" role="group" aria-label="Filter by instrument type">
					<button class="filter-button" type="button" data-filter="all" aria-pressed="true">All instruments</button>
					<button class="filter-button" type="button" data-filter="acoustic" aria-pressed="false">Acoustic guitar</button>
					<button class="filter-button" type="button" data-filter="bass" aria-pressed="false">Bass</button>
					<button class="filter-button" type="button" data-filter="electric" aria-pressed="false">Electric</button>
				</div>

				<div class="product-grid" id="product-grid" aria-live="polite">
					<?php foreach ($products as $product): ?>
						<?php
						$category = (string) $product['category'];
						$categoryLabel = $categoryLabels[$category] ?? ucfirst($category);
						$searchText = implode(' ', [$product['name'], $categoryLabel, $product['brand'], $product['model']]);
						$imagePath = (string) $product['image_path'];
						$hasImage = preg_match('~^uploads/products/[a-f0-9]{32}\\.(?:jpg|png|webp)$~D', $imagePath) === 1
							&& is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath));
						?>
						<article class="product-card" data-category="<?= escapeHtml($category) ?>" data-search="<?= escapeHtml(strtolower($searchText)) ?>">
							<div class="product-image"><?php if ($hasImage): ?><img src="<?= escapeHtml($imagePath) ?>" alt="<?= escapeHtml($product['name']) ?>" loading="lazy"><?php else: ?><span class="product-art" aria-hidden="true"><?= escapeHtml(strtoupper(substr($categoryLabel, 0, 1))) ?></span><?php endif; ?><span class="category-tag"><?= escapeHtml($categoryLabel) ?></span></div>
							<div class="product-info">
								<div class="product-title-row"><div><h3 class="product-name"><?= escapeHtml($product['name']) ?></h3><p class="product-model"><?= escapeHtml(trim($product['brand'] . ' ' . $product['model'])) ?></p></div><span class="product-price">$<?= number_format($product['price'], 2) ?></span></div>
								<div class="product-meta"><span><?= $hasImage ? 'Photo uploaded' : 'No photo' ?></span><span class="stock-label"><i class="stock-dot<?= $product['quantity'] <= 2 ? ' low' : '' ?>"></i><?= number_format($product['quantity']) ?> in stock</span></div>
								<div class="product-actions"><form class="delete-form" method="post" action="inventory.php" onsubmit="return confirm('Remove this product from inventory? This cannot be undone.');"><input type="hidden" name="action" value="delete"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><button class="delete-button" type="submit" aria-label="Remove <?= escapeHtml($product['name']) ?>" title="Remove product"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2m3 0-1 14H6L5 6m4 4v6m6-6v6"/></svg>Remove</button></form></div>
							</div>
						</article>
					<?php endforeach; ?>
					<p class="empty-state" id="empty-state" <?= $products === [] && $databaseError === '' ? '' : 'hidden' ?>>No instruments yet.<br><a href="add.php">Add your first product</a></p>
				</div>
			</section>
		</main>
	</div>

	<script>
		const themeToggle = document.getElementById('theme-toggle');
		const searchInput = document.getElementById('search-input');
		const cards = [...document.querySelectorAll('.product-card')];
		const filterButtons = [...document.querySelectorAll('.filter-button')];
		const emptyState = document.getElementById('empty-state');
		let activeFilter = 'all';

		function setTheme(theme) {
			document.documentElement.dataset.theme = theme;
			themeToggle.setAttribute('aria-label', `Switch to ${theme === 'dark' ? 'light' : 'dark'} mode`);
			localStorage.setItem('goodtone-theme', theme);
		}

		setTheme(localStorage.getItem('goodtone-theme') || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
		themeToggle.addEventListener('click', () => {
			setTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
		});

		function updateCatalog() {
			const query = searchInput.value.trim().toLowerCase();
			let visibleCount = 0;

			cards.forEach((card, index) => {
				const matchesFilter = activeFilter === 'all' || card.dataset.category === activeFilter;
				const matchesSearch = card.dataset.search.includes(query);
				const isVisible = matchesFilter && matchesSearch;
				card.hidden = !isVisible;
				if (isVisible) {
					card.style.animationDelay = `${Math.min(index, 5) * 45}ms`;
					visibleCount++;
				}
			});

			emptyState.hidden = visibleCount > 0;
		}

		filterButtons.forEach((button) => {
			button.addEventListener('click', () => {
				activeFilter = button.dataset.filter;
				filterButtons.forEach((filter) => filter.setAttribute('aria-pressed', String(filter === button)));
				updateCatalog();
			});
		});

		searchInput.addEventListener('input', updateCatalog);

	</script>
</body>
</html>
