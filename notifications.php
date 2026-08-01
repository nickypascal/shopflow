<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$filter = trim((string) ($_GET['filter'] ?? 'all'));
$allowedFilters = ['all', 'unread', 'order', 'shipping', 'wishlist', 'promotion', 'review', 'return'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$where = ['user_id = :user_id', "audience = 'customer'"];
$params = ['user_id' => $userId];

if ($filter === 'unread') {
    $where[] = 'is_read = 0';
} elseif (in_array($filter, ['order', 'shipping', 'wishlist', 'promotion', 'review', 'return'], true)) {
    $where[] = 'type = :type';
    $params['type'] = $filter;
}

$whereSql = implode(' AND ', $where);
$countStatement = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE {$whereSql}");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = $pdo->prepare(
    "SELECT * FROM notifications
     WHERE {$whereSql}
     ORDER BY is_read ASC, created_at DESC, id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$statement->execute($params);
$notifications = $statement->fetchAll();

$pageTitle = 'Notifikasi Saya';
require __DIR__ . '/includes/header.php';
?>
<section class="container page-section notification-page">
    <div class="section-heading notification-page-heading">
        <div>
            <span class="eyebrow">Pusat Informasi</span>
            <h1>Notifikasi Saya</h1>
            <p>Perubahan pesanan, pengiriman, wishlist, promo, ulasan, dan retur terbaru tersimpan di sini.</p>
        </div>
        <div class="notification-heading-actions">
            <a class="button button-ghost" href="notification_preferences.php">Pengaturan</a>
            <form method="post" action="notification_action.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="mark_all">
                <button class="button button-primary" type="submit">Tandai Semua Dibaca</button>
            </form>
        </div>
    </div>

    <nav class="notification-filter" aria-label="Filter notifikasi">
        <?php
        $filters = [
            'all' => 'Semua',
            'unread' => 'Belum Dibaca',
            'order' => 'Pesanan',
            'shipping' => 'Pengiriman',
            'wishlist' => 'Wishlist',
            'promotion' => 'Promo',
            'review' => 'Ulasan',
            'return' => 'Retur',
        ];
        ?>
        <?php foreach ($filters as $key => $label): ?>
            <a class="<?= $filter === $key ? 'active' : '' ?>" href="notifications.php?filter=<?= e($key) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if ($notifications === []): ?>
        <div class="empty-state notification-empty">
            <div class="empty-icon">🔔</div>
            <h2>Belum ada notifikasi</h2>
            <p>Informasi terbaru tentang pesanan dan produk favorit Anda akan muncul di halaman ini.</p>
            <a class="button button-primary" href="index.php">Lihat Produk</a>
        </div>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $isUnread = (int) $notification['is_read'] === 0;
                $actionUrl = notification_url((string) ($notification['url'] ?? ''), 'customer');
                ?>
                <article class="notification-card <?= $isUnread ? 'unread' : '' ?>">
                    <div class="notification-card-icon type-<?= e((string) $notification['type']) ?>">
                        <?= notification_icon((string) $notification['type']) ?>
                    </div>
                    <div class="notification-card-content">
                        <div class="notification-card-title-row">
                            <h2><?= e((string) $notification['title']) ?></h2>
                            <?php if ($isUnread): ?><span class="notification-new-badge">Baru</span><?php endif; ?>
                        </div>
                        <p><?= e((string) $notification['message']) ?></p>
                        <time datetime="<?= e((string) $notification['created_at']) ?>"><?= format_datetime((string) $notification['created_at']) ?></time>
                        <div class="notification-card-actions">
                            <?php if ($actionUrl): ?>
                                <form method="post" action="notification_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="open">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                    <button class="button button-primary button-small" type="submit">Buka Detail</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($isUnread): ?>
                                <form method="post" action="notification_action.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                    <button class="button button-ghost button-small" type="submit">Tandai Dibaca</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="notification_action.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                <button class="button button-danger button-small" type="submit" data-confirm="Hapus notifikasi ini?">Hapus</button>
                            </form>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <nav class="pagination" aria-label="Halaman notifikasi">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="<?= $i === $page ? 'active' : '' ?>" href="notifications.php?filter=<?= e($filter) ?>&page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
