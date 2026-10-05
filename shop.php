<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
	$_SESSION['cart'] = [];
}

function shopEscape(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$categoryLabels = ['acoustic' => 'Acoustic', 'electric' => 'Electric', 'bass' => 'Bass'];
$category = $_GET['category'] ?? 'all';
$category = is_string($category) && isset($categoryLabels[$category]) ? $category : 'all';
$search = $_GET['q'] ?? '';
$search = is_string($search) ? trim(substr($search, 0, 100)) : '';
$notice = '';
$error = '';
$databaseError = '';
$products = [];
$productById = [];
$visibleProducts = [];

try {
	require __DIR__ . '/conn.php';
	$result = $conn->query('SELECT id, name, category, brand, model, image_path, price, quantity FROM products ORDER BY created_at DESC, id DESC');
	while ($product = $result->fetch_assoc()) {
		$product['id'] = (int) $product['id'];
		$product['quantity'] = (int) $product['quantity'];
		$product['price'] = (float) $product['price'];
		$productById[$product['id']] = $product;
		if ($product['quantity'] > 0) {
			$products[] = $product;
		}
	}

	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$token = $_POST['csrf_token'] ?? '';
		$action = $_POST['action'] ?? '';
		$idValue = $_POST['product_id'] ?? '';
		$id = is_string($idValue) ? filter_var($idValue, FILTER_VALIDATE_INT) : false;

		if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
			$error = 'Your session expired. Refresh the page and try again.';
		} elseif ($id === false || $id < 1 || !isset($productById[$id])) {
			$error = 'That guitar is no longer available.';
		} elseif ($action === 'add') {
			$currentQuantity = (int) ($_SESSION['cart'][$id] ?? 0);
			$stock = $productById[$id]['quantity'];
			if ($stock < 1) {
				$error = 'That guitar is currently out of stock.';
			} elseif ($currentQuantity >= $stock) {
				$error = 'Your cart already has all available stock for that guitar.';
			} else {
				$_SESSION['cart'][$id] = $currentQuantity + 1;
				$notice = 'added';
			}
		} elseif ($action === 'update') {
			$quantityValue = $_POST['quantity'] ?? '';
			$quantity = is_string($quantityValue) ? filter_var($quantityValue, FILTER_VALIDATE_INT) : false;
			if ($quantity === false || $quantity < 0) {
				$error = 'Choose a valid quantity.';
			} elseif ($quantity === 0) {
				unset($_SESSION['cart'][$id]);
				$notice = 'removed';
			} elseif ($quantity > $productById[$id]['quantity']) {
				$error = 'Only ' . $productById[$id]['quantity'] . ' available for that guitar.';
			} else {
				$_SESSION['cart'][$id] = $quantity;
				$notice = 'updated';
			}
		} elseif ($action === 'remove') {
			unset($_SESSION['cart'][$id]);
			$notice = 'removed';
		} else {
			$error = 'That cart action is not available.';
		}

		if ($error === '') {
			$query = http_build_query(array_filter(['category' => $category !== 'all' ? $category : null, 'q' => $search !== '' ? $search : null]));
			header('Location: shop.php' . ($query !== '' ? '?' . $query : '') . ($notice !== '' ? (str_contains($query, '=') ? '&' : '?') . 'notice=' . rawurlencode($notice) : ''));
			exit;
		}
	}

	foreach ($products as $product) {
		$searchable = $product['name'] . ' ' . $product['brand'] . ' ' . $product['model'];
		if (($category === 'all' || $product['category'] === $category) && ($search === '' || stripos($searchable, $search) !== false)) {
			$visibleProducts[] = $product;
		}
	}
} catch (Throwable $exception) {
	$databaseError = 'The guitar catalog is unavailable right now. Check that MySQL is running and try again.';
}

$noticeMessages = ['added' => 'Added to your bag.', 'updated' => 'Your bag is up to date.', 'removed' => 'Removed from your bag.'];
$noticeKey = $_GET['notice'] ?? '';
$notice = is_string($noticeKey) ? ($noticeMessages[$noticeKey] ?? '') : '';
$cartCount = 0;
$cartTotal = 0.0;
$cartProducts = [];
foreach ($_SESSION['cart'] as $id => $quantity) {
	$id = (int) $id;
	$quantity = (int) $quantity;
	if ($quantity < 1 || !isset($productById[$id])) {
		unset($_SESSION['cart'][$id]);
		continue;
	}
	$product = $productById[$id];
	$cartCount += $quantity;
	$cartTotal += $product['price'] * $quantity;
	$product['cart_quantity'] = $quantity;
	$cartProducts[] = $product;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#171b18">
	<title>Shop Guitars | JamesGuitars</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
	<style>
		:root { color-scheme:dark; --page:#171b18; --panel:#f3ecdd; --ink:#27231e; --muted:#aaa08e; --line:rgba(234,222,198,.17); --copper:#dc7657; --brass:#d6b66f; --cream:#f3ecdd; --red:#ec907e; --serif:"Instrument Serif",Georgia,serif; }
		* { box-sizing:border-box; }
		body { min-width:320px; margin:0; background:linear-gradient(rgba(16,20,18,.76),rgba(16,20,18,.88)),url("https://images.unsplash.com/photo-1525201548942-d8732f6617a0?auto=format&fit=crop&w=1800&q=88") center/cover fixed; background-color:var(--page); color:var(--cream); font-family:"DM Sans",sans-serif; }
		button,input,select { font:inherit; }
		a { color:inherit; }
		.site-header { display:flex; height:82px; align-items:center; justify-content:space-between; gap:20px; max-width:1500px; margin:auto; padding:0 5vw; border-bottom:1px solid var(--line); }
		.brand { display:inline-flex; align-items:center; gap:11px; text-decoration:none; font-family:var(--serif); font-size:27px; }
		.brand-mark { display:grid; width:36px; height:36px; place-items:center; border:1px solid rgba(214,182,111,.55); border-radius:50%; color:var(--brass); }
		.brand-mark svg { width:20px; height:20px; }
		.header-links { display:flex; align-items:center; gap:30px; font-size:11px; font-weight:700; text-transform:uppercase; }
		.header-links a { text-decoration:none; transition:color .2s ease; }
		.header-links a:hover { color:var(--brass); }
		.signout-form { margin:0; }
		.signout-button { min-height:35px; padding:0 11px; border:1px solid rgba(214,182,111,.55); background:transparent; color:var(--cream); font-size:10px; font-weight:700; text-transform:uppercase; cursor:pointer; transition:border-color .2s ease,color .2s ease; }
		.signout-button:hover { border-color:var(--copper); color:var(--copper); }
		.bag-link { display:flex; align-items:center; gap:9px; }
		.bag-count { display:grid; width:25px; height:25px; place-items:center; border-radius:50%; background:var(--copper); color:#1c1a16; font-size:11px; }
		main { max-width:1500px; margin:auto; padding:0 5vw 76px; }
		.hero { position:relative; display:flex; min-height:480px; align-items:flex-end; overflow:hidden; padding:clamp(34px,5vw,72px); border-bottom:1px solid rgba(214,182,111,.4); background:#20231f url("https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=2000&q=90") center 47%/cover; color:var(--cream); }
		.hero::before { position:absolute; inset:0; background:linear-gradient(90deg,rgba(14,18,16,.88),rgba(14,18,16,.48) 52%,rgba(14,18,16,.04)),linear-gradient(0deg,rgba(14,18,16,.45),transparent 55%); content:""; }
		.hero-copy { position:relative; max-width:710px; animation:rise-in .65s both; }
		.hero-kicker { display:flex; align-items:center; gap:11px; margin:0 0 20px; color:var(--brass); font-size:10px; font-weight:700; text-transform:uppercase; }
		.hero-kicker::before { width:29px; height:1px; background:var(--copper); content:""; }
		h1 { max-width:700px; margin:0 0 17px; font-family:var(--serif); font-size:82px; font-weight:400; line-height:.88; }
		h1 em { color:var(--brass); font-weight:400; }
		.hero-copy p:last-child { max-width:410px; margin:0; color:rgba(243,236,221,.82); font-size:14px; line-height:1.7; }
		.shop-layout { display:grid; grid-template-columns:minmax(0,1fr) 320px; align-items:start; gap:38px; padding-top:52px; }
		.catalog { min-width:0; }
		.catalog-top { display:flex; align-items:flex-end; justify-content:space-between; gap:18px; margin-bottom:23px; }
		h2 { margin:0; font-family:var(--serif); font-size:34px; font-weight:400; }
		.result-count { margin:4px 0 0; color:var(--muted); font-size:11px; }
		.search-form { display:flex; width:min(330px,48%); height:43px; }
		.search-form input { width:100%; min-width:0; padding:0 14px; border:1px solid var(--line); border-right:0; border-radius:0; outline:none; background:rgba(20,24,21,.78); color:var(--cream); font-size:12px; }
		.search-form input:focus { border-color:var(--brass); }
		.search-form input::placeholder { color:#a99f8e; }
		.search-form button { width:47px; flex:0 0 auto; border:1px solid var(--brass); background:var(--brass); color:#1d201b; cursor:pointer; }
		.search-form svg { width:17px; height:17px; vertical-align:middle; }
		.filters { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:21px; }
		.filter { display:inline-flex; min-height:34px; align-items:center; padding:0 14px; border:1px solid var(--line); border-radius:2px; color:#c7bcaa; font-size:10px; font-weight:700; text-decoration:none; text-transform:uppercase; transition:background .2s ease,color .2s ease,border-color .2s ease; }
		.filter:hover,.filter.active { border-color:var(--copper); background:var(--copper); color:#211c19; }
		.product-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:15px; }
		.product-card { min-width:0; overflow:hidden; background:var(--panel); color:var(--ink); animation:rise-in .45s both; }
		.product-image { position:relative; display:grid; aspect-ratio:1.08; place-items:center; overflow:hidden; background:radial-gradient(ellipse at 50% 42%,#d0b989,#8b6c4e 75%); }
		.product-image img { width:100%; height:100%; object-fit:cover; transition:transform .45s ease; }
		.product-card:hover .product-image img { transform:scale(1.045); }
		.image-placeholder { display:grid; width:78px; height:78px; place-items:center; border:1px solid rgba(39,35,30,.3); border-radius:50%; background:rgba(243,236,221,.18); color:#30291f; }
		.image-placeholder svg { width:36px; height:36px; }
		.category-label { position:absolute; top:12px; left:12px; padding:7px 9px; background:var(--brass); color:#231f19; font-size:9px; font-weight:700; text-transform:uppercase; }
		.product-info { padding:16px 16px 15px; }
		.product-brand { margin:0 0 6px; color:#88775e; font-size:9px; font-weight:700; text-transform:uppercase; }
		.product-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; }
		.product-title { margin:0; font-family:var(--serif); font-size:22px; font-weight:400; line-height:1; }
		.product-price { flex:0 0 auto; color:#783b2e; font-size:13px; font-weight:700; }
		.product-model { min-height:16px; margin:7px 0 14px; color:#817767; font-size:10px; }
		.product-footer { display:flex; align-items:center; justify-content:space-between; gap:6px; border-top:1px solid rgba(39,35,30,.14); padding-top:12px; }
		.stock-note { color:#746b5e; font-size:9px; }
		.add-button { display:inline-flex; min-height:35px; align-items:center; gap:7px; padding:0 12px; border:0; border-radius:0; background:#26372d; color:var(--cream); font-size:10px; font-weight:700; cursor:pointer; transition:background .2s ease; }
		.add-button:hover { background:#a34e39; }
		.add-button svg { width:14px; height:14px; }
		.cart-panel { position:sticky; top:20px; border:1px solid var(--line); border-top:2px solid var(--brass); background:rgba(25,30,26,.94); color:var(--cream); }
		.cart-heading { display:flex; align-items:center; justify-content:space-between; padding:18px 18px 14px; border-bottom:1px solid var(--line); }
		.cart-heading h2 { font-size:27px; }
		.cart-heading span { color:var(--brass); font-size:10px; }
		.cart-lines { padding:0 18px; }
		.cart-line { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:8px; padding:16px 0; border-bottom:1px solid var(--line); }
		.cart-product-name { margin:0 0 4px; color:var(--cream); font-size:11px; font-weight:700; line-height:1.4; }
		.cart-unit-price { margin:0; color:var(--muted); font-size:10px; }
		.line-total { color:var(--brass); font-size:11px; font-weight:700; text-align:right; white-space:nowrap; }
		.cart-controls { display:flex; align-items:center; gap:5px; margin-top:9px; }
		.quantity-input { width:45px; height:28px; padding:0 6px; border:1px solid var(--line); border-radius:0; background:#171b18; color:var(--cream); font-size:10px; }
		.small-button { height:28px; padding:0 9px; border:1px solid var(--line); border-radius:0; background:transparent; color:var(--cream); font-size:9px; cursor:pointer; }
		.small-button:hover { border-color:var(--brass); }
		.remove-button { border:0; background:transparent; color:#bcb09d; font-size:9px; text-decoration:underline; cursor:pointer; }
		.cart-empty { margin:0; padding:27px 18px; color:#c0b5a2; font-size:11px; line-height:1.7; }
		.cart-summary { padding:16px 18px 18px; }
		.subtotal { display:flex; justify-content:space-between; color:var(--cream); font-size:13px; font-weight:700; }
		.tax-note { margin:7px 0 0; color:var(--muted); font-size:9px; line-height:1.5; }
		.checkout-note { display:flex; gap:9px; align-items:flex-start; margin-top:15px; padding:11px; background:rgba(214,182,111,.12); color:#d1c4ad; font-size:10px; line-height:1.5; }
		.checkout-note svg { width:15px; height:15px; flex:0 0 auto; color:var(--brass); }
		.message { margin:16px 0 0; padding:12px 15px; border-left:2px solid var(--brass); background:rgba(214,182,111,.12); color:var(--cream); font-size:12px; }
		.message.error { border-color:var(--red); background:rgba(236,144,126,.12); color:#ffd3c7; }
		.empty-state { grid-column:1/-1; padding:48px 20px; border:1px dashed rgba(243,236,221,.27); color:#c0b5a2; text-align:center; font-size:12px; }
		.empty-state strong { display:block; margin-bottom:7px; color:var(--cream); font-family:var(--serif); font-size:26px; font-weight:400; }
		footer { max-width:1500px; margin:0 auto; padding:21px 5vw 30px; border-top:1px solid var(--line); color:#b6aa96; font-size:10px; }
		.button:focus-visible,a:focus-visible,input:focus-visible { outline:2px solid var(--brass); outline-offset:3px; }
		@keyframes rise-in { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
		@media (max-width:1050px) { .shop-layout { grid-template-columns:minmax(0,1fr) 285px; gap:23px; } .product-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } h1 { font-size:70px; } }
		@media (max-width:720px) { .site-header { height:68px; padding:0 18px; } main { padding:0 18px 48px; } .hero { min-height:390px; padding:32px 25px; } h1 { font-size:58px; } .shop-layout { grid-template-columns:1fr; gap:24px; padding-top:35px; } .cart-panel { position:static; grid-row:1; } .cart-lines { max-height:310px; overflow:auto; } .catalog-top { align-items:center; } footer { padding-right:18px; padding-left:18px; } }
		@media (max-width:460px) { .brand { gap:7px; font-size:21px; } .brand-mark { width:32px; height:32px; } .header-links { gap:7px; font-size:9px; } .bag-link { gap:5px; } .signout-button { min-height:32px; padding:0 7px; font-size:9px; white-space:nowrap; } .header-links a:not(.bag-link) { display:none; } .hero { min-height:340px; } h1 { font-size:48px; } .catalog-top { align-items:stretch; flex-direction:column; } .search-form { width:100%; } .product-grid { gap:9px; } .product-info { padding:11px; } .product-title { font-size:19px; } .product-price { font-size:11px; } .product-footer { align-items:flex-start; flex-direction:column; } .add-button { justify-content:center; width:100%; } }
		@media (max-width:360px) { .site-header { gap:7px; padding-right:12px; padding-left:12px; } .brand { gap:5px; font-size:18px; } .brand-mark { width:28px; height:28px; } .header-links { gap:5px; font-size:8px; } .bag-link { gap:3px; } .bag-count { width:22px; height:22px; } .signout-button { padding:0 5px; font-size:8px; } }
		@media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms!important; animation-iteration-count:1!important; transition-duration:.01ms!important; } }
	</style>
</head>
<body>
	<header class="site-header">
		<a class="brand" href="shop.php"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span>JamesGuitars</a>
		<nav class="header-links" aria-label="Main navigation"><a href="#guitars">The collection</a><?php if (in_array($_SESSION['user_role'] ?? '', ['staff', 'owner'], true)): ?><a href="inventory.php">Store admin</a><?php endif; ?><a class="bag-link" href="#your-bag" aria-label="Your bag, <?= $cartCount ?> items">Your bag <span class="bag-count"><?= $cartCount ?></span></a><form class="signout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= shopEscape($_SESSION['csrf_token']) ?>"><button class="signout-button" type="submit">Sign out</button></form></nav>
	</header>
	<main>
		<section class="hero" aria-labelledby="page-title"><div class="hero-copy"><p class="hero-kicker">Independent guitar shop · instruments with a story</p><h1 id="page-title">Find your next <em>favorite</em> noise.</h1><p>Handpicked guitars for loud nights, quiet mornings, and every song still waiting to happen.</p></div></section>
		<?php if ($notice !== ''): ?><p class="message" role="status"><?= shopEscape($notice) ?></p><?php endif; ?>
		<?php if ($error !== ''): ?><p class="message error" role="alert"><?= shopEscape($error) ?></p><?php endif; ?>
		<?php if ($databaseError !== ''): ?><p class="message error" role="alert"><?= shopEscape($databaseError) ?></p><?php endif; ?>
		<div class="shop-layout">
			<section class="catalog" id="guitars" aria-labelledby="catalog-title">
				<div class="catalog-top"><div><h2 id="catalog-title">The collection</h2><p class="result-count"><?= count($visibleProducts) ?> <?= count($visibleProducts) === 1 ? 'instrument' : 'instruments' ?> in stock</p></div>
					<form class="search-form" method="get" action="shop.php"><input type="hidden" name="category" value="<?= shopEscape($category) ?>"><label for="search" class="visually-hidden">Search guitars</label><input id="search" name="q" type="search" value="<?= shopEscape($search) ?>" placeholder="Search guitars or brands"><button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg></button></form>
				</div>
				<nav class="filters" aria-label="Filter by guitar type"><?php foreach (['all' => 'All guitars'] + $categoryLabels as $key => $label): ?><a class="filter <?= $category === $key ? 'active' : '' ?>" href="shop.php?<?= http_build_query(array_filter(['category' => $key !== 'all' ? $key : null, 'q' => $search !== '' ? $search : null])) ?>" <?= $category === $key ? 'aria-current="page"' : '' ?>><?= shopEscape($label) ?></a><?php endforeach; ?></nav>
				<div class="product-grid">
					<?php if ($visibleProducts === []): ?><div class="empty-state"><strong>No guitars found</strong>Try another search or browse all guitars.</div><?php endif; ?>
					<?php foreach ($visibleProducts as $index => $product): ?>
						<article class="product-card" style="animation-delay:<?= min($index, 8) * 45 ?>ms">
							<div class="product-image"><span class="category-label"><?= shopEscape($categoryLabels[$product['category']] ?? 'Guitar') ?></span><?php if ($product['image_path'] !== ''): ?><img src="<?= shopEscape($product['image_path']) ?>" alt="<?= shopEscape($product['name']) ?>" loading="lazy"><?php else: ?><span class="image-placeholder" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></span><?php endif; ?></div>
							<div class="product-info"><p class="product-brand"><?= shopEscape($product['brand'] !== '' ? $product['brand'] : $categoryLabels[$product['category']]) ?></p><div class="product-title-row"><h3 class="product-title"><?= shopEscape($product['name']) ?></h3><span class="product-price">$<?= number_format($product['price'], 2) ?></span></div><p class="product-model"><?= shopEscape($product['model']) ?></p>
								<div class="product-footer"><span class="stock-note"><?= $product['quantity'] <= 3 ? 'Only ' . $product['quantity'] . ' left' : 'In stock' ?></span><form method="post" action="shop.php<?= $search !== '' || $category !== 'all' ? '?' . http_build_query(array_filter(['category' => $category !== 'all' ? $category : null, 'q' => $search !== '' ? $search : null])) : '' ?>"><input type="hidden" name="csrf_token" value="<?= shopEscape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="add"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><button class="add-button" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Add to bag</button></form></div>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<aside class="cart-panel" id="your-bag" aria-labelledby="bag-title"><div class="cart-heading"><h2 id="bag-title">Your bag</h2><span><?= $cartCount ?> <?= $cartCount === 1 ? 'item' : 'items' ?></span></div>
				<?php if ($cartProducts === []): ?><p class="cart-empty">Your bag is waiting for its first guitar.</p><?php else: ?><div class="cart-lines">
					<?php foreach ($cartProducts as $product): ?><div class="cart-line"><div><p class="cart-product-name"><?= shopEscape($product['name']) ?></p><p class="cart-unit-price">$<?= number_format($product['price'], 2) ?> each</p><form class="cart-controls" method="post" action="shop.php#your-bag"><input type="hidden" name="csrf_token" value="<?= shopEscape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><label class="visually-hidden" for="quantity-<?= $product['id'] ?>">Quantity for <?= shopEscape($product['name']) ?></label><input class="quantity-input" id="quantity-<?= $product['id'] ?>" type="number" name="quantity" min="0" max="<?= $product['quantity'] ?>" value="<?= $product['cart_quantity'] ?>"><button class="small-button" type="submit">Update</button></form></div><div><div class="line-total">$<?= number_format($product['price'] * $product['cart_quantity'], 2) ?></div><form method="post" action="shop.php#your-bag"><input type="hidden" name="csrf_token" value="<?= shopEscape($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="product_id" value="<?= $product['id'] ?>"><button class="remove-button" type="submit">Remove</button></form></div></div><?php endforeach; ?>
				</div><div class="cart-summary"><div class="subtotal"><span>Subtotal</span><span>$<?= number_format($cartTotal, 2) ?></span></div><p class="tax-note">Shipping and taxes are calculated when your order is arranged.</p><div class="checkout-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg><span>To complete a purchase, contact the shop directly. Online payment is not set up yet.</span></div></div><?php endif; ?>
			</aside>
		</div>
	</main>
	<footer>JamesGuitars · Instruments for the long way home.</footer>
	<style>.visually-hidden { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }</style>
</body>
</html>
