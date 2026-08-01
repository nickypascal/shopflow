<?php

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

$pageTitle = $pageTitle ?? 'ShopFlow';
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$user = current_user();
$flashMessage = get_flash();
$storeName = isset($pdo) ? setting($pdo, 'store_name', 'ShopFlow') : 'ShopFlow';
$storeLogo = isset($pdo) ? setting($pdo, 'store_logo', '') : '';
$wishlistTotal = isset($pdo) && $user && !is_admin() ? wishlist_count($pdo, (int) $user['id']) : 0;
$notificationTotal = isset($pdo) && $user && !is_admin()
    ? unread_notification_count($pdo, (int) $user['id'], 'customer')
    : 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="ShopFlow - aplikasi e-commerce PHP sederhana dan responsif">
    <title><?= e($pageTitle) ?> | ShopFlow</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="site-header">
    <div class="container nav-wrap">
        <a class="brand" href="index.php">
            <span class="brand-mark"><?php if ($storeLogo !== ''): ?><img src="<?= e($storeLogo) ?>" alt="Logo toko"><?php else: ?>S<?php endif; ?></span>
            <span><?= e($storeName) ?></span>
        </a>

        <button class="nav-toggle" type="button" aria-label="Buka navigasi" data-nav-toggle>☰</button>

        <nav class="main-nav" data-nav>
            <a class="<?= $currentPage === 'index.php' ? 'active' : '' ?>" href="index.php">Beranda</a>
            <?php if (is_admin()): ?>
                <a href="admin/index.php">Dashboard Admin</a>
            <?php else: ?>
                <a class="<?= $currentPage === 'orders.php' ? 'active' : '' ?>" href="orders.php">Pesanan</a>
                <a class="<?= $currentPage === 'promotions.php' ? 'active' : '' ?>" href="promotions.php">Promo</a>
                <a class="wishlist-nav-link <?= $currentPage === 'wishlist.php' ? 'active' : '' ?>" href="wishlist.php">
                    Wishlist <span class="wishlist-nav-badge"><?= $wishlistTotal ?></span>
                </a>
                <a class="<?= in_array($currentPage, ['my_reviews.php', 'review_create.php', 'review_edit.php'], true) ? 'active' : '' ?>" href="my_reviews.php">Ulasan</a>
                <a class="<?= in_array($currentPage, ['my_returns.php', 'return_create.php', 'return_detail.php', 'return_policy.php'], true) ? 'active' : '' ?>" href="my_returns.php">Retur</a>
                <a class="notification-nav-link <?= in_array($currentPage, ['notifications.php', 'notification_preferences.php'], true) ? 'active' : '' ?>" href="notifications.php" aria-label="Notifikasi">
                    🔔 <span>Notifikasi</span>
                    <span class="notification-nav-badge" data-notification-badge <?= $notificationTotal <= 0 ? 'hidden' : '' ?>><?= $notificationTotal > 99 ? '99+' : $notificationTotal ?></span>
                </a>
                <a class="<?= in_array($currentPage, ['addresses.php', 'add_address.php', 'edit_address.php'], true) ? 'active' : '' ?>" href="addresses.php">Alamat</a>
                <a class="cart-link <?= $currentPage === 'cart.php' ? 'active' : '' ?>" href="cart.php">
                    Keranjang <span class="cart-badge"><?= cart_count() ?></span>
                </a>
            <?php endif; ?>

            <?php if ($user): ?>
                <span class="user-chip">Hai, <?= e($user['name']) ?></span>
                <a class="button button-ghost button-small" href="logout.php">Keluar</a>
            <?php else: ?>
                <a class="button button-primary button-small" href="login.php">Login Pengguna</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main>
    <?php if ($flashMessage): ?>
        <div class="container flash-wrap">
            <div class="alert alert-<?= e($flashMessage['type']) ?>">
                <?= e($flashMessage['message']) ?>
            </div>
        </div>
    <?php endif; ?>
