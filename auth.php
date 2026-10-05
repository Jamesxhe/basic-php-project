<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function requireLogin(): void
{
    if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
        header('Location: loginpage.php');
        exit;
    }

    if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'owner'], true)) {
        header('Location: shop.php');
        exit;
    }
}