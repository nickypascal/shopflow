<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('notifications.php');
}
verify_csrf();

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
$notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

if ($action === 'mark_all') {
    $statement = $pdo->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_id = :user_id AND audience = 'customer' AND is_read = 0"
    );
    $statement->execute(['user_id' => $userId]);
    flash('success', 'Semua notifikasi telah ditandai dibaca.');
    redirect('notifications.php');
}

if (!$notificationId) {
    flash('warning', 'Notifikasi tidak valid.');
    redirect('notifications.php');
}

$statement = $pdo->prepare(
    "SELECT id, url FROM notifications
     WHERE id = :id AND user_id = :user_id AND audience = 'customer' LIMIT 1"
);
$statement->execute(['id' => $notificationId, 'user_id' => $userId]);
$notification = $statement->fetch();
if (!$notification) {
    flash('warning', 'Notifikasi tidak ditemukan atau bukan milik Anda.');
    redirect('notifications.php');
}

if (in_array($action, ['mark_read', 'open'], true)) {
    $pdo->prepare(
        "UPDATE notifications SET is_read = 1, read_at = COALESCE(read_at, NOW())
         WHERE id = :id AND user_id = :user_id"
    )->execute(['id' => $notificationId, 'user_id' => $userId]);

    if ($action === 'open') {
        $target = notification_url((string) ($notification['url'] ?? ''), 'customer');
        redirect($target ?: 'notifications.php');
    }
    flash('success', 'Notifikasi ditandai sudah dibaca.');
} elseif ($action === 'delete') {
    $pdo->prepare('DELETE FROM notifications WHERE id = :id AND user_id = :user_id')
        ->execute(['id' => $notificationId, 'user_id' => $userId]);
    flash('success', 'Notifikasi berhasil dihapus.');
} else {
    flash('warning', 'Tindakan notifikasi tidak dikenali.');
}

redirect('notifications.php');
