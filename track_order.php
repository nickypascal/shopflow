<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$orderId = filter_input(INPUT_GET, 'order', FILTER_VALIDATE_INT);
$user = current_user();
if (!$orderId) {
    flash('warning', 'Pesanan tidak valid.');
    redirect('orders.php');
}

$statement = $pdo->prepare(
    'SELECT id, order_number, status, payment_method, total_amount,
            shipping_courier_name, shipping_service_code, shipping_service_name,
            shipping_estimation, tracking_number, charged_weight_kg,
            shipping_name, shipping_phone, shipping_address, shipping_address_label,
            shipping_province, shipping_city, shipping_district, shipping_village,
            shipping_postal_code, shipped_at, delivered_at, created_at
     FROM orders
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$statement->execute(['id' => $orderId, 'user_id' => (int) $user['id']]);
$order = $statement->fetch();
if (!$order) {
    flash('warning', 'Pesanan tidak ditemukan atau bukan milik Anda.');
    redirect('orders.php');
}

$historyStatement = $pdo->prepare(
    'SELECT status, description, location, created_at
     FROM shipment_histories
     WHERE order_id = :order_id
     ORDER BY id ASC'
);
$historyStatement->execute(['order_id' => $orderId]);
$history = $historyStatement->fetchAll();

$pageTitle = 'Lacak ' . order_code($order);
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div class="container">
        <span class="eyebrow">Pelacakan Pengiriman</span>
        <h1><?= e(order_code($order)) ?></h1>
        <p>Pantau kurir, nomor resi, dan riwayat perjalanan paket Anda.</p>
    </div>
</section>

<section class="section">
    <div class="container tracking-layout">
        <div class="tracking-main-card">
            <div class="tracking-hero">
                <div class="tracking-icon">🚚</div>
                <div>
                    <span class="status-pill <?= e(status_css_class((string) $order['status'])) ?>"><?= e((string) $order['status']) ?></span>
                    <h2><?= e((string) ($order['shipping_courier_name'] ?: 'Kurir belum dipilih')) ?> <?= e((string) ($order['shipping_service_code'] ?: '')) ?></h2>
                    <p><?= e((string) ($order['shipping_service_name'] ?: '-')) ?> • Estimasi <?= e((string) ($order['shipping_estimation'] ?: '-')) ?></p>
                </div>
            </div>

            <div class="tracking-metrics">
                <div><span>Nomor resi</span><strong><?= e((string) ($order['tracking_number'] ?: 'Belum tersedia')) ?></strong></div>
                <div><span>Berat dikenakan</span><strong><?= (int) $order['charged_weight_kg'] ?> kg</strong></div>
                <div><span>Total pesanan</span><strong><?= rupiah($order['total_amount']) ?></strong></div>
            </div>

            <div class="shipment-timeline">
                <?php if ($history === []): ?>
                    <div class="shipping-empty-state"><strong>Belum ada riwayat pengiriman.</strong><span>Pembaruan perjalanan paket akan muncul di sini.</span></div>
                <?php else: ?>
                    <?php foreach ($history as $index => $entry): ?>
                        <div class="shipment-timeline-item <?= $index === count($history) - 1 ? 'is-latest' : '' ?>">
                            <div class="shipment-timeline-marker"><?= $index + 1 ?></div>
                            <div class="shipment-timeline-copy">
                                <strong><?= e((string) $entry['status']) ?></strong>
                                <span><?= e((string) ($entry['description'] ?: 'Status pengiriman diperbarui.')) ?></span>
                                <?php if (trim((string) ($entry['location'] ?? '')) !== ''): ?><small>📍 <?= e((string) $entry['location']) ?></small><?php endif; ?>
                                <time><?= format_datetime((string) $entry['created_at']) ?></time>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <aside class="tracking-side-card">
            <h2>Alamat Tujuan</h2>
            <strong><?= e((string) $order['shipping_name']) ?> · <?= e((string) $order['shipping_phone']) ?></strong>
            <p><?= nl2br(e((string) $order['shipping_address'])) ?></p>
            <p><?= e(address_region_text($order, true)) ?></p>
            <?php if ($order['status'] === 'Dikirim'): ?>
                <form method="post" action="confirm_delivery.php" data-confirm-delivery>
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <button class="button button-primary button-full" type="submit">Pesanan Sudah Diterima</button>
                </form>
            <?php endif; ?>
            <a class="button button-secondary button-full" href="orders.php">Kembali ke Pesanan</a>
        </aside>
    </div>
</section>
<script>
document.querySelectorAll('[data-confirm-delivery]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm('Pastikan paket sudah diterima dan diperiksa. Tandai pesanan sebagai selesai?')) event.preventDefault();
    });
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
