<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$pdo->prepare('INSERT IGNORE INTO notification_preferences (user_id) VALUES (:user_id)')
    ->execute(['user_id' => $userId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $values = [];
    foreach (['order_updates', 'shipping_updates', 'promotion_updates', 'wishlist_price_updates', 'wishlist_stock_updates'] as $field) {
        $values[$field] = isset($_POST[$field]) ? 1 : 0;
    }
    $values['user_id'] = $userId;

    $statement = $pdo->prepare(
        'UPDATE notification_preferences SET
            order_updates = :order_updates,
            shipping_updates = :shipping_updates,
            promotion_updates = :promotion_updates,
            wishlist_price_updates = :wishlist_price_updates,
            wishlist_stock_updates = :wishlist_stock_updates,
            updated_at = NOW()
         WHERE user_id = :user_id'
    );
    $statement->execute($values);
    flash('success', 'Pengaturan notifikasi berhasil disimpan.');
    redirect('notification_preferences.php');
}

$statement = $pdo->prepare('SELECT * FROM notification_preferences WHERE user_id = :user_id');
$statement->execute(['user_id' => $userId]);
$preferences = $statement->fetch() ?: [];

$pageTitle = 'Pengaturan Notifikasi';
require __DIR__ . '/includes/header.php';
?>
<section class="container page-section notification-preferences-page">
    <div class="section-heading">
        <div><span class="eyebrow">Preferensi</span><h1>Pengaturan Notifikasi</h1><p>Pilih informasi tambahan yang ingin Anda terima. Pemberitahuan penting terkait transaksi tetap dapat dikirim.</p></div>
        <a class="button button-ghost" href="notifications.php">← Kembali</a>
    </div>

    <form class="card notification-preferences-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <?php
        $options = [
            'order_updates' => ['🛒', 'Perubahan status pesanan', 'Konfirmasi, penolakan, pembatalan, dan penyelesaian pesanan.'],
            'shipping_updates' => ['📦', 'Informasi pengiriman', 'Nomor resi dan pembaruan perjalanan paket.'],
            'promotion_updates' => ['🏷️', 'Voucher dan promo', 'Promo baru yang diumumkan oleh admin.'],
            'wishlist_price_updates' => ['❤️', 'Penurunan harga wishlist', 'Informasi ketika harga produk favorit turun.'],
            'wishlist_stock_updates' => ['📦', 'Stok wishlist tersedia', 'Informasi ketika produk atau variasi favorit tersedia kembali.'],
        ];
        ?>
        <?php foreach ($options as $field => [$icon, $title, $description]): ?>
            <label class="notification-preference-item">
                <input type="checkbox" name="<?= e($field) ?>" value="1" <?= (int) ($preferences[$field] ?? 1) === 1 ? 'checked' : '' ?>>
                <span class="notification-preference-icon"><?= $icon ?></span>
                <span><strong><?= e($title) ?></strong><small><?= e($description) ?></small></span>
            </label>
        <?php endforeach; ?>
        <button class="button button-primary" type="submit">Simpan Pengaturan</button>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
