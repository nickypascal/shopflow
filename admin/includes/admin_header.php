<?php

declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Dashboard Admin';
$activeMenu = $activeMenu ?? 'dashboard';
$flashMessage = get_flash();

$unreadNotifications = unread_notification_count($pdo, (int) ($adminUser['id'] ?? 0), 'admin');

$avatarStatement = $pdo->prepare('SELECT avatar FROM users WHERE id = :id');
$avatarStatement->execute(['id' => (int) ($adminUser['id'] ?? 0)]);
$adminAvatar = (string) ($avatarStatement->fetchColumn() ?: '');
$storeName = setting($pdo, 'store_name', 'ShopFlow');
$storeLogo = setting($pdo, 'store_logo', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dashboard Admin ShopFlow">
    <title><?= e($pageTitle) ?> | Admin ShopFlow</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-body">
<div class="admin-shell">
    <?php require __DIR__ . '/admin_sidebar.php'; ?>
    <div class="admin-main">
        <header class="admin-topbar">
            <button class="admin-menu-toggle" type="button" aria-label="Buka menu" data-sidebar-toggle>☰</button>
            <div class="admin-topbar-title">
                <span>Panel Administrasi</span>
                <strong><?= e($pageTitle) ?></strong>
            </div>
            <div class="admin-topbar-actions">
                <a class="admin-icon-link" href="notifications.php" aria-label="Notifikasi">
                    🔔
                    <?php if ($unreadNotifications > 0): ?>
                        <span><?= $unreadNotifications > 99 ? '99+' : $unreadNotifications ?></span>
                    <?php endif; ?>
                </a>
                <div class="admin-profile-chip">
                    <div class="admin-avatar"><?php if ($adminAvatar !== ''): ?><img src="../<?= e($adminAvatar) ?>" alt="Foto admin"><?php else: ?><?= e(strtoupper(substr((string) ($adminUser['name'] ?? 'A'), 0, 1))) ?><?php endif; ?></div>
                    <div><strong><?= e((string) ($adminUser['name'] ?? 'Admin')) ?></strong><span>Administrator</span></div>
                </div>
            </div>
        </header>
        <main class="admin-content">
            <?php if ($flashMessage): ?>
                <div class="admin-alert admin-alert-<?= e((string) $flashMessage['type']) ?>">
                    <?= e((string) $flashMessage['message']) ?>
                </div>
            <?php endif; ?>
