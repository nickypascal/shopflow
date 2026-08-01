<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$adminId = (int) ($adminUser['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));
    $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

    if ($action === 'mark_all') {
        $statement = $pdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE user_id = :user_id AND audience = 'admin' AND is_read = 0"
        );
        $statement->execute(['user_id' => $adminId]);
        flash('success', 'Semua notifikasi admin ditandai sudah dibaca.');
    } elseif ($notificationId && in_array($action, ['mark_read', 'open'], true)) {
        $statement = $pdo->prepare(
            "SELECT id, url FROM notifications
             WHERE id = :id AND user_id = :user_id AND audience = 'admin' LIMIT 1"
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $adminId]);
        $notification = $statement->fetch();
        if (!$notification) {
            flash('warning', 'Notifikasi tidak ditemukan.');
            redirect('notifications.php');
        }
        $pdo->prepare(
            'UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
             WHERE id = :id AND user_id = :user_id'
        )->execute(['id' => $notificationId, 'user_id' => $adminId]);

        if ($action === 'open') {
            $target = notification_url((string) ($notification['url'] ?? ''), 'admin');
            redirect($target ?: 'notifications.php');
        }
        flash('success', 'Notifikasi ditandai sudah dibaca.');
    } elseif ($action === 'delete' && $notificationId) {
        $statement = $pdo->prepare(
            "DELETE FROM notifications
             WHERE id = :id AND user_id = :user_id AND audience = 'admin'"
        );
        $statement->execute(['id' => $notificationId, 'user_id' => $adminId]);
        flash('success', 'Notifikasi dihapus.');
    } elseif ($action === 'cleanup') {
        $statement = $pdo->prepare(
            "DELETE FROM notifications
             WHERE user_id = :user_id AND audience = 'admin'
               AND is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"
        );
        $statement->execute(['user_id' => $adminId]);
        flash('success', 'Notifikasi lama yang sudah dibaca berhasil dibersihkan.');
    } else {
        flash('warning', 'Tindakan notifikasi tidak valid.');
    }
    redirect('notifications.php');
}

$filter = trim((string) ($_GET['filter'] ?? 'all'));
$allowedFilters = ['all', 'unread', 'order', 'shipping', 'stock', 'warning', 'review', 'return'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$where = ["audience = 'admin'", 'user_id = :user_id'];
$params = ['user_id' => $adminId];
if ($filter === 'unread') {
    $where[] = 'is_read = 0';
} elseif ($filter !== 'all') {
    $where[] = 'type = :type';
    $params['type'] = $filter;
}

$sql = 'SELECT * FROM notifications WHERE ' . implode(' AND ', $where) . ' ORDER BY is_read ASC, created_at DESC, id DESC LIMIT 200';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$notifications = $statement->fetchAll();

$pageTitle = 'Notifikasi';
$activeMenu = 'notifications';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Notifikasi Admin</h1><p>Pesanan baru, pembatalan, stok, pengiriman, retur, dan aktivitas penting lainnya.</p></div>
    <div class="admin-page-actions">
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="cleanup">
            <button class="admin-button admin-button-secondary" type="submit">Bersihkan Lama</button>
        </form>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="mark_all">
            <button class="admin-button admin-button-primary" type="submit">Tandai Semua Dibaca</button>
        </form>
    </div>
</div>

<nav class="admin-notification-filters">
    <?php foreach (['all'=>'Semua','unread'=>'Belum Dibaca','order'=>'Pesanan','shipping'=>'Pengiriman','stock'=>'Stok','warning'=>'Peringatan','review'=>'Ulasan','return'=>'Retur'] as $key=>$label): ?>
        <a class="<?= $filter === $key ? 'active' : '' ?>" href="notifications.php?filter=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</nav>

<section class="admin-card" style="padding:0;overflow:hidden">
<?php if ($notifications === []): ?>
    <div class="admin-empty" style="border:0"><strong>Tidak ada notifikasi</strong><p>Notifikasi terbaru akan muncul di sini.</p></div>
<?php else: ?>
    <?php foreach ($notifications as $notification): ?>
        <?php $isUnread = (int) $notification['is_read'] === 0; ?>
        <div class="admin-notification <?= $isUnread ? 'unread' : '' ?>">
            <div class="admin-notification-icon"><?= notification_icon((string) $notification['type']) ?></div>
            <div class="admin-notification-copy">
                <strong><?= e((string) $notification['title']) ?><?php if ($isUnread): ?> <span class="admin-badge status-shipping">Baru</span><?php endif; ?></strong>
                <p><?= e((string) $notification['message']) ?></p>
                <small><?= format_datetime((string) $notification['created_at']) ?></small>
                <?php if ($notification['url']): ?>
                    <form method="post" style="margin-top:8px">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="open">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                        <button class="admin-inline-link" type="submit">Buka detail →</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="admin-table-actions">
                <?php if ($isUnread): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="mark_read">
                        <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                        <button class="admin-button admin-button-secondary admin-button-small" type="submit">Dibaca</button>
                    </form>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                    <button class="admin-button admin-button-danger admin-button-small" type="submit" data-confirm="Hapus notifikasi ini?">Hapus</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
