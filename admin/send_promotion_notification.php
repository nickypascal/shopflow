<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('promotions.php');
}
verify_csrf();

$promotionId = filter_input(INPUT_POST, 'promotion_id', FILTER_VALIDATE_INT);
if (!$promotionId) {
    flash('warning', 'Promo tidak valid.');
    redirect('promotions.php');
}

try {
    $pdo->beginTransaction();
    $statement = $pdo->prepare('SELECT * FROM promotions WHERE id = :id FOR UPDATE');
    $statement->execute(['id' => $promotionId]);
    $promotion = $statement->fetch();
    if (!$promotion) {
        throw new RuntimeException('Promo tidak ditemukan.');
    }
    if ((int) $promotion['is_active'] !== 1) {
        throw new RuntimeException('Aktifkan promo sebelum mengirim notifikasi.');
    }
    if (!empty($promotion['notification_sent_at'])) {
        throw new RuntimeException('Notifikasi promo ini sudah pernah dikirim.');
    }

    $customers = $pdo->query("SELECT id FROM users WHERE role = 'customer' AND status = 'active'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $codeText = trim((string) ($promotion['code'] ?? '')) !== ''
        ? ' Gunakan kode ' . (string) $promotion['code'] . ' saat checkout.'
        : ' Promo diterapkan otomatis saat syarat terpenuhi.';
    $message = (string) $promotion['name'] . ': ' . promotion_value_label($promotion)
        . '. Minimal belanja ' . rupiah((float) $promotion['minimum_purchase']) . '.'
        . $codeText . ' Berlaku sampai ' . date('d M Y', strtotime((string) $promotion['end_at'])) . '.';

    $sent = 0;
    foreach ($customers as $customerId) {
        if (!notification_preference_enabled($pdo, (int) $customerId, 'promotion_updates')) {
            continue;
        }
        create_notification(
            $pdo,
            'Promo baru tersedia',
            $message,
            '../promotions.php',
            'promotion',
            'customer',
            (int) $customerId,
            'promotion-announcement-' . $promotionId,
            'promotion',
            $promotionId,
            'promotion_updates'
        );
        $sent++;
    }

    $pdo->prepare('UPDATE promotions SET notification_sent_at = NOW(), updated_at = NOW() WHERE id = :id')
        ->execute(['id' => $promotionId]);
    log_admin_activity($pdo, 'promotion_notification', 'Mengirim pengumuman promo ' . (string) $promotion['name'] . ' kepada ' . $sent . ' pelanggan.');
    $pdo->commit();
    flash('success', 'Notifikasi promo dikirim kepada ' . $sent . ' pelanggan yang mengaktifkan notifikasi promo.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash($exception instanceof RuntimeException ? 'warning' : 'danger', $exception->getMessage());
}

redirect('promotions.php');
