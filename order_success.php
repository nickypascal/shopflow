<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$orderId = (int) ($_GET['id'] ?? 0);
$user = current_user();

$statement = $pdo->prepare(
    'SELECT id, order_number, subtotal_amount, total_amount, shipping_cost, cod_fee,
            promotion_id, voucher_code, promotion_name, promotion_trigger_type, discount_type, discount_value,
            product_discount, shipping_discount, amount_before_discount,
            total_weight_grams, charged_weight_kg, status, payment_status,
            payment_category, payment_method, payment_detail,
            shipping_courier_name, shipping_service_code, shipping_service_name,
            shipping_estimation, tracking_number,
            shipping_name, shipping_phone, shipping_address, shipping_address_label,
            shipping_province, shipping_city, shipping_district, shipping_village,
            shipping_postal_code, courier_note, created_at
     FROM orders
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$statement->execute([
    'id' => $orderId,
    'user_id' => $user['id'],
]);
$order = $statement->fetch();

if (!$order) {
    flash('danger', 'Pesanan tidak ditemukan.');
    redirect('orders.php');
}

$isCod = is_cod_payment((string) $order['payment_method']);

$itemStatement = $pdo->prepare(
    'SELECT product_name, product_image, variant_id, variant_name, variant_sku,
            variant_color, variant_size, variant_material, price, quantity, subtotal,
            item_weight_grams, total_weight_grams
     FROM order_items WHERE order_id = :order_id ORDER BY id'
);
$itemStatement->execute(['order_id' => $orderId]);
$orderItems = $itemStatement->fetchAll();


$pageTitle = 'Pesanan Berhasil';
require __DIR__ . '/includes/header.php';
?>
<section class="success-section">
    <div class="container">
        <div class="success-card">
            <div class="success-icon">✓</div>
            <span class="eyebrow">Checkout berhasil</span>
            <h1><?= $isCod ? 'Pesanan COD menunggu konfirmasi.' : 'Terima kasih! Pesanan Anda sudah dibuat.' ?></h1>
            <p>Nomor pesanan <strong><?= e(order_code($order)) ?></strong> berhasil disimpan.</p>
            <div class="success-detail success-detail-three">
                <div><span>Total Pembayaran</span><strong><?= rupiah($order['total_amount']) ?></strong></div>
                <div><span>Metode Pembayaran</span><strong><?= e(payment_method_label((string) $order['payment_method'], $order['payment_detail'])) ?></strong></div>
                <div><span>Status</span><strong><?= e($order['status']) ?></strong><small><?= e(weight_label((int) $order['total_weight_grams'])) ?></small></div>
            </div>
            <div class="success-products-card success-payment-breakdown">
                <h2>Ringkasan Pembayaran</h2>
                <div class="summary-row"><span>Subtotal produk</span><strong><?= rupiah($order['subtotal_amount']) ?></strong></div>
                <?php if ((float)$order['product_discount']>0):?><div class="summary-row order-discount-row"><span>Diskon produk<?= $order['voucher_code']?' · '.e((string)$order['voucher_code']):''?></span><strong>-<?=rupiah($order['product_discount'])?></strong></div><?php endif;?>
                <div class="summary-row"><span>Ongkos kirim</span><strong><?= rupiah($order['shipping_cost']) ?></strong></div>
                <?php if ((float)$order['shipping_discount']>0):?><div class="summary-row order-discount-row"><span>Diskon ongkir</span><strong>-<?=rupiah($order['shipping_discount'])?></strong></div><?php endif;?>
                <?php if ((float) $order['cod_fee'] > 0): ?><div class="summary-row"><span>Biaya COD</span><strong><?= rupiah($order['cod_fee']) ?></strong></div><?php endif; ?>
                <div class="summary-row summary-total"><span>Total pembayaran</span><strong><?= rupiah($order['total_amount']) ?></strong></div>
            </div>
            <?php if($order['promotion_id']):?><div class="success-promo-card"><span>🎟️</span><div><strong><?=e((string)$order['promotion_name'])?></strong><p><?= $order['voucher_code']?'Voucher '.e((string)$order['voucher_code']):'Promo otomatis' ?> berhasil digunakan. Anda hemat <?=rupiah((float)$order['product_discount']+(float)$order['shipping_discount'])?>.</p></div></div><?php endif;?>
            <div class="success-products-card">
                <h2>Produk Pesanan</h2>
                <?php foreach ($orderItems as $item): ?>
                    <div class="success-product-row">
                        <img src="<?= e((string) ($item['product_image'] ?: 'assets/images/bag.svg')) ?>" alt="<?= e((string) $item['product_name']) ?>">
                        <div>
                            <strong><?= e((string) $item['product_name']) ?></strong>
                            <?php if ((int) ($item['variant_id'] ?? 0) > 0): ?><small><?= e(variant_description($item)) ?> • SKU <?= e((string) $item['variant_sku']) ?></small><?php endif; ?>
                            <span><?= (int) $item['quantity'] ?> × <?= rupiah($item['price']) ?> • <?= e(weight_label((int) $item['total_weight_grams'])) ?></span>
                        </div>
                        <b><?= rupiah($item['subtotal']) ?></b>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="success-shipping-card">
                <div class="success-address-icon">🚚</div>
                <div>
                    <span>Layanan Pengiriman</span>
                    <strong><?= e((string) ($order['shipping_courier_name'] ?: '-')) ?> <?= e((string) ($order['shipping_service_code'] ?: '')) ?></strong>
                    <p><?= e((string) ($order['shipping_service_name'] ?: '-')) ?> • Estimasi <?= e((string) ($order['shipping_estimation'] ?: '-')) ?></p>
                    <small>Berat dikenakan <?= (int) $order['charged_weight_kg'] ?> kg</small>
                </div>
            </div>
            <div class="success-address-card">
                <div class="success-address-icon"><?= e(address_icon((string) ($order['shipping_address_label'] ?: 'Alamat'))) ?></div>
                <div>
                    <span>Alamat Pengiriman · <?= e((string) ($order['shipping_address_label'] ?: 'Alamat')) ?></span>
                    <strong><?= e((string) $order['shipping_name']) ?> · <?= e((string) $order['shipping_phone']) ?></strong>
                    <p><?= nl2br(e((string) $order['shipping_address'])) ?><br><?= e(address_region_text($order, true)) ?></p>
                    <?php if (trim((string) ($order['courier_note'] ?? '')) !== ''): ?><small>Catatan kurir: <?= e((string) $order['courier_note']) ?></small><?php endif; ?>
                </div>
            </div>
            <?php if ($isCod): ?>
                <div class="cod-success-note">
                    <?= $order['payment_method'] === 'cod_check'
                        ? 'Toko akan mengonfirmasi pesanan. Setelah dikirim, Anda dapat memeriksa paket sebelum membayar kepada kurir.'
                        : 'Toko akan mengonfirmasi pesanan. Siapkan pembayaran ketika paket telah sampai.' ?>
                </div>
            <?php endif; ?>
            <div class="success-actions">
                <a class="button button-primary" href="orders.php">Lihat Pesanan</a>
                <a class="button button-secondary" href="index.php">Kembali Belanja</a>
            </div>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
