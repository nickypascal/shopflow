<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();

$statement = $pdo->prepare(
    'SELECT o.id, o.order_number, o.subtotal_amount, o.total_amount, o.shipping_cost, o.cod_fee,
            o.promotion_id, o.voucher_code, o.promotion_name, o.promotion_trigger_type,
            o.discount_type, o.discount_value, o.product_discount, o.shipping_discount, o.amount_before_discount,
            o.total_weight_grams, o.charged_weight_kg, o.status, o.payment_status,
            o.payment_category, o.payment_method, o.payment_detail,
            o.shipping_courier_name, o.shipping_service_code, o.shipping_service_name,
            o.shipping_estimation, o.tracking_number, o.shipped_at, o.delivered_at,
            o.shipping_name, o.shipping_phone, o.shipping_address, o.shipping_address_label,
            o.shipping_province, o.shipping_city, o.shipping_district, o.shipping_village,
            o.shipping_postal_code, o.courier_note,
            o.cancellation_reason, o.cancelled_at,
            o.rejection_reason, o.rejected_at, o.completed_at, o.created_at,
            COUNT(oi.id) AS item_count
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.user_id = :user_id
     GROUP BY o.id
     ORDER BY o.id DESC'
);
$statement->execute(['user_id' => $user['id']]);
$orders = $statement->fetchAll();

$itemStatement = $pdo->prepare(
    'SELECT oi.id AS order_item_id,
            oi.order_id,
            oi.product_id,
            oi.product_name,
            oi.price,
            oi.quantity,
            oi.subtotal,
            oi.variant_id,
            oi.variant_name,
            oi.variant_sku,
            oi.variant_color,
            oi.variant_size,
            oi.variant_material,
            oi.item_weight_grams,
            oi.total_weight_grams,
            COALESCE(oi.product_image, p.image) AS image,
            pr.id AS review_id,
            pr.status AS review_status,
            (SELECT rr.id FROM return_items rri INNER JOIN return_requests rr ON rr.id = rri.return_request_id WHERE rri.order_item_id = oi.id ORDER BY rr.id DESC LIMIT 1) AS return_request_id,
            (SELECT rr.status FROM return_items rri INNER JOIN return_requests rr ON rr.id = rri.return_request_id WHERE rri.order_item_id = oi.id ORDER BY rr.id DESC LIMIT 1) AS return_status,
            (SELECT COALESCE(SUM(rri.return_quantity),0) FROM return_items rri INNER JOIN return_requests rr ON rr.id = rri.return_request_id WHERE rri.order_item_id = oi.id AND rr.status NOT IN (\'REJECTED\',\'CANCELLED\')) AS returned_quantity
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     LEFT JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_reviews pr ON pr.order_item_id = oi.id
     WHERE o.user_id = :user_id
     ORDER BY oi.order_id DESC, oi.id ASC'
);
$itemStatement->execute(['user_id' => $user['id']]);
$orderItems = $itemStatement->fetchAll();

$itemsByOrder = [];
foreach ($orderItems as $item) {
    $itemsByOrder[(int) $item['order_id']][] = $item;
}

$historyStatement = $pdo->prepare(
    'SELECT h.order_id, h.status, h.note, h.created_at
     FROM order_status_history h
     INNER JOIN orders o ON o.id = h.order_id
     WHERE o.user_id = :user_id
     ORDER BY h.order_id DESC, h.id ASC'
);
$historyStatement->execute(['user_id' => $user['id']]);
$historyByOrder = [];
foreach ($historyStatement->fetchAll() as $history) {
    $historyByOrder[(int) $history['order_id']][] = $history;
}

$returnPeriodDays = return_period_days($pdo);

$cancelReasons = [
    'wrong_product' => 'Salah memilih produk',
    'wrong_quantity' => 'Jumlah produk tidak sesuai',
    'wrong_address' => 'Alamat pengiriman salah',
    'change_payment' => 'Ingin mengubah metode pembayaran',
    'not_buying' => 'Tidak jadi membeli',
    'other' => 'Lainnya',
];

$pageTitle = 'Riwayat Pesanan';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header">
    <div class="container">
        <span class="eyebrow">Akun saya</span>
        <h1>Riwayat Pesanan</h1>
        <p>Pantau tahapan pesanan, termasuk pesanan COD dan COD Cek Dulu.</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <?php if ($orders === []): ?>
            <div class="empty-state">
                <div class="empty-icon">📦</div>
                <h2>Belum ada pesanan</h2>
                <p>Pesanan yang selesai dibuat akan muncul di halaman ini.</p>
                <a class="button button-primary" href="index.php#produk">Belanja Sekarang</a>
            </div>
        <?php else: ?>
            <div class="orders-list">
                <?php foreach ($orders as $order): ?>
                    <?php
                    $orderId = (int) $order['id'];
                    $products = $itemsByOrder[$orderId] ?? [];
                    $history = $historyByOrder[$orderId] ?? [];
                    $isCod = is_cod_payment((string) $order['payment_method']);
                    $steps = cod_status_steps();
                    $currentStepIndex = array_search((string) $order['status'], $steps, true);
                    $currentStepIndex = $currentStepIndex === false ? -1 : (int) $currentStepIndex;
                    $historyTimes = [];
                    foreach ($history as $historyItem) {
                        $historyTimes[(string) $historyItem['status']] = (string) $historyItem['created_at'];
                    }
                    ?>

                    <article class="order-card">
                        <div class="order-top">
                            <div>
                                <span>Nomor Pesanan</span>
                                <strong><?= e(order_code($order)) ?></strong>
                            </div>
                            <span class="status-pill <?= e(status_css_class((string) $order['status'])) ?>"><?= e($order['status']) ?></span>
                        </div>

                        <div class="order-grid order-grid-five">
                            <div>
                                <span>Tanggal</span>
                                <strong><?= date('d M Y, H:i', strtotime((string) $order['created_at'])) ?></strong>
                            </div>
                            <div>
                                <span>Jumlah Item</span>
                                <strong><?= (int) $order['item_count'] ?> jenis produk</strong>
                            </div>
                            <div>
                                <span>Penerima</span>
                                <strong><?= e($order['shipping_name']) ?></strong>
                            </div>
                            <div>
                                <span>Metode</span>
                                <strong><?= e(payment_method_label((string) $order['payment_method'], $order['payment_detail'])) ?></strong>
                            </div>
                            <div>
                                <span>Total</span>
                                <strong><?= rupiah($order['total_amount']) ?></strong>
                                <small><?= e(weight_label((int) $order['total_weight_grams'])) ?></small>
                            </div>
                        </div>

                        <?php if ($isCod): ?>
                            <div class="cod-status-panel">
                                <div class="cod-status-heading">
                                    <div>
                                        <span class="eyebrow">Pelacakan COD</span>
                                        <h3><?= e(cod_status_description((string) $order['status'], (string) $order['payment_method'])) ?></h3>
                                    </div>
                                    <span class="payment-type-badge"><?= e(payment_method_label((string) $order['payment_method'])) ?></span>
                                </div>

                                <?php if (in_array($order['status'], ['Ditolak', 'Dibatalkan'], true)): ?>
                                    <div class="order-terminal-note <?= $order['status'] === 'Ditolak' ? 'order-terminal-rejected' : 'order-terminal-cancelled' ?>">
                                        <strong><?= e($order['status']) ?></strong>
                                        <span>
                                            <?= e((string) (
                                                $order['status'] === 'Ditolak'
                                                    ? ($order['rejection_reason'] ?: 'Pesanan tidak dapat diproses oleh toko.')
                                                    : ($order['cancellation_reason'] ?: 'Pesanan dibatalkan oleh pelanggan.')
                                            )) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="cod-progress" aria-label="Tahapan pesanan COD">
                                        <?php foreach ($steps as $index => $step): ?>
                                            <?php
                                            $stepClass = 'pending';
                                            if ($index < $currentStepIndex) {
                                                $stepClass = 'done';
                                            } elseif ($index === $currentStepIndex) {
                                                $stepClass = 'current';
                                            }
                                            $stepTime = $historyTimes[$step] ?? null;
                                            ?>
                                            <div class="cod-progress-step <?= e($stepClass) ?>">
                                                <div class="cod-progress-marker"><?= $index < $currentStepIndex || $order['status'] === 'Selesai' ? '✓' : $index + 1 ?></div>
                                                <div class="cod-progress-copy">
                                                    <strong><?= e($step) ?></strong>
                                                    <span><?= $stepTime ? date('d M Y, H:i', strtotime($stepTime)) : 'Belum tercapai' ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="cod-info-note">
                                    <?php if ($order['payment_method'] === 'cod_check'): ?>
                                        <strong>COD Cek Dulu:</strong> periksa kondisi dan kesesuaian produk sebelum melakukan pembayaran kepada kurir.
                                    <?php else: ?>
                                        <strong>COD:</strong> siapkan uang sesuai total pembayaran ketika paket diterima.
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="order-products">
                            <h3>Produk Pesanan</h3>
                            <div class="order-product-list">
                                <?php foreach ($products as $product): ?>
                                    <div class="order-product-item">
                                        <img
                                            src="<?= e($product['image'] ?: 'assets/images/bag.svg') ?>"
                                            alt="<?= e($product['product_name']) ?>"
                                        >
                                        <div class="order-product-info">
                                            <strong><?= e((string) $product['product_name']) ?></strong>
                                            <?php if ((int) ($product['variant_id'] ?? 0) > 0): ?>
                                                <span class="order-variant-line"><?= e(variant_description($product)) ?> • SKU <?= e((string) $product['variant_sku']) ?></span>
                                            <?php endif; ?>
                                            <span><?= (int) $product['quantity'] ?> × <?= rupiah($product['price']) ?></span>
                                            <small>Berat: <?= e(weight_label((int) $product['total_weight_grams'])) ?></small>
                                            <?php if ((string) $order['status'] === 'Selesai'): ?>
                                                <div class="order-review-actions">
                                                    <?php if (!empty($product['review_id'])): ?>
                                                        <a class="button button-secondary button-small" href="review_edit.php?id=<?= (int) $product['review_id'] ?>">Edit Ulasan</a>
                                                        <span class="review-order-status"><?= e(review_status_label((string) ($product['review_status'] ?? ''))) ?></span>
                                                    <?php else: ?>
                                                        <a class="button button-primary button-small" href="review_create.php?order_item=<?= (int) $product['order_item_id'] ?>">Beri Ulasan</a>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <small class="review-availability-note">Ulasan tersedia setelah pesanan selesai.</small>
                                            <?php endif; ?>
                                            <?php
                                            $returnedQty = (int) ($product['returned_quantity'] ?? 0);
                                            $returnAvailableQty = max(0, (int) $product['quantity'] - $returnedQty);
                                            $canReturnNow = (string) $order['status'] === 'Selesai'
                                                && return_is_open((string) ($order['completed_at'] ?? ''), $returnPeriodDays)
                                                && $returnAvailableQty > 0;
                                            ?>
                                            <div class="order-return-actions">
                                                <?php if (!empty($product['return_request_id'])): ?>
                                                    <a class="button button-secondary button-small" href="return_detail.php?id=<?= (int) $product['return_request_id'] ?>">Detail Retur</a>
                                                    <span class="review-order-status"><?= e(return_status_label((string) ($product['return_status'] ?? ''))) ?></span>
                                                    <?php if ($canReturnNow): ?><a class="button button-ghost button-small" href="return_create.php?order_item=<?= (int) $product['order_item_id'] ?>"><?= in_array((string)($product['return_status'] ?? ''), ['REJECTED','CANCELLED'], true) ? 'Ajukan Retur Lagi' : 'Retur Sisa (' . $returnAvailableQty . ')' ?></a><?php endif; ?>
                                                <?php elseif ($canReturnNow): ?>
                                                    <a class="button button-secondary button-small" href="return_create.php?order_item=<?= (int) $product['order_item_id'] ?>">Ajukan Retur</a>
                                                <?php elseif ((string) $order['status'] === 'Selesai' && $returnAvailableQty <= 0): ?>
                                                    <small class="review-availability-note">Seluruh jumlah produk sudah diajukan retur.</small>
                                                <?php elseif ((string) $order['status'] === 'Selesai'): ?>
                                                    <small class="review-availability-note">Periode retur telah berakhir.</small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <strong class="order-product-price"><?= rupiah($product['subtotal']) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>


                        <div class="order-delivery-summary">
                            <div class="order-delivery-icon">🚚</div>
                            <div class="order-delivery-copy">
                                <span>Layanan pengiriman</span>
                                <strong><?= e((string) ($order['shipping_courier_name'] ?: '-')) ?> <?= e((string) ($order['shipping_service_code'] ?: '')) ?></strong>
                                <small><?= e((string) ($order['shipping_service_name'] ?: '-')) ?> • Estimasi <?= e((string) ($order['shipping_estimation'] ?: '-')) ?> • <?= (int) $order['charged_weight_kg'] ?> kg</small>
                                <?php if (trim((string) ($order['tracking_number'] ?? '')) !== ''): ?><b>Resi: <?= e((string) $order['tracking_number']) ?></b><?php endif; ?>
                            </div>
                            <div class="order-delivery-actions">
                                <a class="button button-secondary button-small" href="track_order.php?order=<?= $orderId ?>">Lacak Pengiriman</a>
                                <?php if ($order['status'] === 'Dikirim'): ?>
                                    <form method="post" action="confirm_delivery.php" data-confirm-delivery>
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="order_id" value="<?= $orderId ?>">
                                        <button class="button button-primary button-small" type="submit">Pesanan Sudah Diterima</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="order-cost-breakdown">
                            <div><span>Subtotal produk</span><strong><?= rupiah($order['subtotal_amount']) ?></strong></div>
                            <?php if ((float) $order['product_discount'] > 0): ?><div class="order-discount-row"><span>Diskon produk<?= $order['voucher_code'] ? ' · '.e((string)$order['voucher_code']) : '' ?></span><strong>-<?= rupiah($order['product_discount']) ?></strong></div><?php endif; ?>
                            <div><span>Ongkos kirim</span><strong><?= rupiah($order['shipping_cost']) ?></strong></div>
                            <?php if ((float) $order['shipping_discount'] > 0): ?><div class="order-discount-row"><span>Diskon ongkir</span><strong>-<?= rupiah($order['shipping_discount']) ?></strong></div><?php endif; ?>
                            <?php if ((float) $order['cod_fee'] > 0): ?><div><span>Biaya COD</span><strong><?= rupiah($order['cod_fee']) ?></strong></div><?php endif; ?>
                            <div class="order-cost-total"><span>Total pembayaran</span><strong><?= rupiah($order['total_amount']) ?></strong></div>
                        </div>
                        <?php if ($order['promotion_id']): ?><div class="order-promo-box"><span>🎟️</span><div><strong><?= e((string)$order['promotion_name']) ?></strong><small><?= $order['voucher_code'] ? 'Kode '.e((string)$order['voucher_code']) : 'Promo otomatis' ?> · Total hemat <?= rupiah((float)$order['product_discount'] + (float)$order['shipping_discount']) ?></small></div></div><?php endif; ?>
                        <div class="order-shipping-box order-address-snapshot">
                            <div class="order-address-heading">
                                <div>
                                    <span><?= e(address_icon((string) ($order['shipping_address_label'] ?: 'Alamat'))) ?></span>
                                    <div><strong><?= e((string) ($order['shipping_address_label'] ?: 'Alamat Pengiriman')) ?></strong><small>Salinan alamat saat checkout</small></div>
                                </div>
                            </div>
                            <p><strong><?= e($order['shipping_name']) ?></strong> · <?= e($order['shipping_phone']) ?></p>
                            <p><?= nl2br(e((string) $order['shipping_address'])) ?></p>
                            <?php $regionText = address_region_text($order, true); ?>
                            <?php if ($regionText !== ''): ?><p><?= e($regionText) ?></p><?php endif; ?>
                            <?php if (trim((string) ($order['courier_note'] ?? '')) !== ''): ?>
                                <div class="courier-note"><strong>Catatan kurir:</strong> <?= e((string) $order['courier_note']) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (in_array($order['status'], ['Menunggu Pembayaran', 'Menunggu Konfirmasi'], true)): ?>
                            <details class="cancel-order-box">
                                <summary>Batalkan Pesanan</summary>
                                <form class="cancel-order-form" method="post" action="cancel_order.php">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="order_id" value="<?= $orderId ?>">

                                    <label>
                                        <span>Alasan pembatalan</span>
                                        <select name="reason" data-cancel-reason required>
                                            <option value="">Pilih alasan</option>
                                            <?php foreach ($cancelReasons as $value => $label): ?>
                                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="cancel-other-field" data-cancel-other>
                                        <span>Jelaskan alasan lainnya</span>
                                        <textarea name="other_reason" rows="3" maxlength="200" placeholder="Tuliskan alasan pembatalan..."></textarea>
                                    </label>

                                    <button class="button button-danger" type="submit">Konfirmasi Pembatalan</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<script>
(() => {
    document.querySelectorAll('[data-confirm-delivery]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!confirm('Pastikan paket sudah diterima dan diperiksa. Tandai pesanan sebagai selesai?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('.cancel-order-form').forEach((form) => {
        const select = form.querySelector('[data-cancel-reason]');
        const otherField = form.querySelector('[data-cancel-other]');
        const textarea = otherField.querySelector('textarea');

        function updateOtherReason() {
            const visible = select.value === 'other';
            otherField.classList.toggle('is-visible', visible);
            textarea.required = visible;
            if (!visible) textarea.value = '';
        }

        select.addEventListener('change', updateOtherReason);
        updateOtherReason();

        form.addEventListener('submit', (event) => {
            if (!confirm('Apakah Anda yakin ingin membatalkan pesanan ini?')) {
                event.preventDefault();
            }
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
