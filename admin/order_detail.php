<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$orderId) {
    flash('danger', 'Nomor pesanan tidak valid.');
    redirect('orders.php');
}

$statement = $pdo->prepare(
    "SELECT o.*, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
     FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     WHERE o.id = :id LIMIT 1"
);
$statement->execute(['id' => $orderId]);
$order = $statement->fetch();
if (!$order) {
    flash('warning', 'Pesanan tidak ditemukan.');
    redirect('orders.php');
}

$itemStatement = $pdo->prepare(
    "SELECT oi.*, COALESCE(oi.product_image, p.image, 'assets/images/bag.svg') AS display_image,
            pr.id AS review_id, pr.status AS review_status, pr.rating AS review_rating,
            (SELECT rr.id FROM return_items rri INNER JOIN return_requests rr ON rr.id=rri.return_request_id WHERE rri.order_item_id=oi.id ORDER BY rr.id DESC LIMIT 1) AS return_request_id,
            (SELECT rr.status FROM return_items rri INNER JOIN return_requests rr ON rr.id=rri.return_request_id WHERE rri.order_item_id=oi.id ORDER BY rr.id DESC LIMIT 1) AS return_status
     FROM order_items oi
     LEFT JOIN products p ON p.id = oi.product_id
     LEFT JOIN product_reviews pr ON pr.order_item_id = oi.id
     WHERE oi.order_id = :order_id ORDER BY oi.id"
);
$itemStatement->execute(['order_id' => $orderId]);
$items = $itemStatement->fetchAll();

$historyStatement = $pdo->prepare(
    "SELECT h.*, u.name AS changed_by_name
     FROM order_status_history h
     LEFT JOIN users u ON u.id = h.changed_by
     WHERE h.order_id = :order_id ORDER BY h.id DESC"
);
$historyStatement->execute(['order_id' => $orderId]);
$history = $historyStatement->fetchAll();

$shipmentHistoryStatement = $pdo->prepare(
    "SELECT sh.*, u.name AS changed_by_name
     FROM shipment_histories sh
     LEFT JOIN users u ON u.id = sh.changed_by
     WHERE sh.order_id = :order_id
     ORDER BY sh.id DESC"
);
$shipmentHistoryStatement->execute(['order_id' => $orderId]);
$shipmentHistory = $shipmentHistoryStatement->fetchAll();

$nextActions = [];
$currentStatus = (string) $order['status'];
if ($currentStatus === 'Menunggu Konfirmasi') $nextActions = ['Diproses' => 'Terima & Proses', 'Ditolak' => 'Tolak Pesanan'];
if ($currentStatus === 'Menunggu Pembayaran') $nextActions = ['Diproses' => 'Konfirmasi Pembayaran & Proses', 'Ditolak' => 'Tolak Pesanan'];
if ($currentStatus === 'Diproses') $nextActions = ['Dikirim' => 'Tandai Sudah Dikirim'];
if ($currentStatus === 'Dikirim') $nextActions = ['Selesai' => 'Tandai Selesai'];

$rejectionOptions = [
    'Alamat pengiriman tidak lengkap',
    'Nomor telepon tidak dapat dihubungi',
    'Produk sedang tidak tersedia',
    'Wilayah tidak mendukung COD',
    'Pesanan terindikasi tidak valid',
    'Pelanggan memiliki riwayat penolakan COD',
    'Lainnya',
];

$pageTitle = 'Detail ' . order_code($order);
$activeMenu = 'orders';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1><?= e(order_code($order)) ?></h1><p>Dibuat <?= format_datetime((string) $order['created_at']) ?> oleh <?= e((string) $order['customer_name']) ?>.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="orders.php">← Kembali</a><span class="admin-badge <?= e(status_css_class($currentStatus)) ?>"><?= e($currentStatus) ?></span></div>
</div>

<div class="admin-order-layout">
    <div class="admin-grid">
        <section class="admin-card">
            <div class="admin-card-head"><h2>Produk Pesanan</h2><span><?= count($items) ?> jenis produk</span></div>
            <div class="admin-order-products">
                <?php foreach ($items as $item): ?>
                    <div class="admin-order-product">
                        <img src="../<?= e((string) $item['display_image']) ?>" alt="<?= e((string) $item['product_name']) ?>">
                        <div>
                            <strong><?= e((string) $item['product_name']) ?></strong>
                            <?php if ((int) ($item['variant_id'] ?? 0) > 0): ?>
                                <small><?= e(variant_description($item)) ?> • SKU <?= e((string) $item['variant_sku']) ?></small>
                            <?php endif; ?>
                            <span><?= (int) $item['quantity'] ?> × <?= rupiah($item['price']) ?> • <?= e(weight_label((int) $item['total_weight_grams'])) ?></span>
                            <?php if (!empty($item['review_id'])): ?><small>Ulasan: <?= (int) $item['review_rating'] ?> bintang · <?= e(review_status_label((string) $item['review_status'])) ?> · <a href="review_detail.php?id=<?= (int) $item['review_id'] ?>">Lihat</a></small><?php elseif ($currentStatus === 'Selesai'): ?><small>Belum diulas pelanggan.</small><?php endif; ?>
                            <?php if (!empty($item['return_request_id'])): ?><small>Retur: <?= e(return_status_label((string)$item['return_status'])) ?> · <a href="return_detail.php?id=<?= (int)$item['return_request_id'] ?>">Lihat Retur</a></small><?php endif; ?>
                        </div>
                        <strong><?= rupiah($item['subtotal']) ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:16px">
                <div class="admin-summary-row"><span>Subtotal</span><strong><?= rupiah($order['subtotal_amount']) ?></strong></div>
                <?php if((float)$order['product_discount']>0):?><div class="admin-summary-row order-discount-row"><span>Diskon produk</span><strong>-<?=rupiah($order['product_discount'])?></strong></div><?php endif;?>
                <div class="admin-summary-row"><span>Total berat aktual</span><strong><?= e(weight_label((int) $order['total_weight_grams'])) ?></strong></div>
                <div class="admin-summary-row"><span>Berat dikenakan</span><strong><?= (int) $order['charged_weight_kg'] ?> kg</strong></div>
                <div class="admin-summary-row"><span>Ongkos kirim</span><strong><?= rupiah($order['shipping_cost']) ?></strong></div>
                <?php if((float)$order['shipping_discount']>0):?><div class="admin-summary-row order-discount-row"><span>Diskon ongkir</span><strong>-<?=rupiah($order['shipping_discount'])?></strong></div><?php endif;?>
                <?php if ((float) $order['cod_fee'] > 0): ?><div class="admin-summary-row"><span>Biaya COD</span><strong><?= rupiah($order['cod_fee']) ?></strong></div><?php endif; ?>
                <div class="admin-summary-row"><strong>Total Pesanan</strong><strong><?= rupiah($order['total_amount']) ?></strong></div>
            </div>
        </section>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Informasi Pengiriman</h2><span>Snapshot saat checkout</span></div>
            <div class="admin-detail-grid">
                <div class="admin-detail-item"><span>Label alamat</span><strong><?= e((string) ($order['shipping_address_label'] ?: 'Alamat Pengiriman')) ?></strong></div>
                <div class="admin-detail-item"><span>Nama penerima</span><strong><?= e((string) $order['shipping_name']) ?></strong></div>
                <div class="admin-detail-item"><span>Nomor telepon</span><strong><?= e((string) $order['shipping_phone']) ?></strong></div>
                <div class="admin-detail-item"><span>Kode pos</span><strong><?= e((string) ($order['shipping_postal_code'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Provinsi</span><strong><?= e((string) ($order['shipping_province'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Kota/Kabupaten</span><strong><?= e((string) ($order['shipping_city'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Kecamatan</span><strong><?= e((string) ($order['shipping_district'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Kelurahan/Desa</span><strong><?= e((string) ($order['shipping_village'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item" style="grid-column:1/-1"><span>Alamat lengkap</span><strong><?= nl2br(e((string) $order['shipping_address'])) ?></strong></div>
                <div class="admin-detail-item" style="grid-column:1/-1"><span>Catatan kurir</span><strong><?= e((string) ($order['courier_note'] ?: '-')) ?></strong></div>
            </div>
        </section>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Layanan Pengiriman</h2><span>Snapshot saat checkout</span></div>
            <div class="admin-detail-grid">
                <div class="admin-detail-item"><span>Kurir</span><strong><?= e((string) ($order['shipping_courier_name'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Layanan</span><strong><?= e((string) ($order['shipping_service_code'] ?: '-')) ?> — <?= e((string) ($order['shipping_service_name'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Estimasi</span><strong><?= e((string) ($order['shipping_estimation'] ?: '-')) ?></strong></div>
                <div class="admin-detail-item"><span>Nomor resi</span><strong><?= e((string) ($order['tracking_number'] ?: 'Belum tersedia')) ?></strong></div>
            </div>
        </section>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Riwayat Pengiriman</h2></div>
            <?php if ($shipmentHistory === []): ?>
                <div class="admin-empty"><strong>Belum ada riwayat pengiriman</strong><p>Pembaruan paket akan muncul di sini.</p></div>
            <?php else: ?>
                <div class="admin-timeline">
                    <?php foreach ($shipmentHistory as $entry): ?>
                        <div class="admin-timeline-item">
                            <strong><?= e((string) $entry['status']) ?></strong>
                            <span><?= e((string) ($entry['description'] ?: 'Status pengiriman diperbarui.')) ?></span>
                            <?php if (trim((string) ($entry['location'] ?? '')) !== ''): ?><span>📍 <?= e((string) $entry['location']) ?></span><?php endif; ?>
                            <span><?= format_datetime((string) $entry['created_at']) ?><?= $entry['changed_by_name'] ? ' • ' . e((string) $entry['changed_by_name']) : '' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (in_array($currentStatus, ['Diproses', 'Dikirim'], true)): ?>
                <form class="admin-form-stack" method="post" action="shipment_update.php" style="margin-top:18px">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                    <div class="admin-form-grid">
                        <label class="admin-field"><span>Status perjalanan</span><input class="admin-input" name="shipment_status" maxlength="100" required placeholder="Contoh: Paket dalam perjalanan"></label>
                        <label class="admin-field"><span>Lokasi</span><input class="admin-input" name="location" maxlength="150" placeholder="Contoh: Rembang Hub"></label>
                    </div>
                    <label class="admin-field"><span>Keterangan</span><textarea class="admin-textarea" name="description" maxlength="255" placeholder="Keterangan pembaruan paket"></textarea></label>
                    <button class="admin-button admin-button-secondary" type="submit">Tambah Riwayat Pengiriman</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Timeline Pesanan</h2></div>
            <?php if ($history === []): ?>
                <div class="admin-empty"><strong>Belum ada riwayat</strong><p>Perubahan status akan muncul di sini.</p></div>
            <?php else: ?>
                <div class="admin-timeline">
                    <?php foreach ($history as $entry): ?>
                        <div class="admin-timeline-item"><strong><?= e((string) $entry['status']) ?></strong><span><?= e((string) ($entry['note'] ?: 'Status pesanan diperbarui.')) ?></span><span><?= format_datetime((string) $entry['created_at']) ?><?= $entry['changed_by_name'] ? ' • ' . e((string) $entry['changed_by_name']) : '' ?></span></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <aside class="admin-grid">
        <section class="admin-card">
            <div class="admin-card-head"><h2>Informasi Pesanan</h2></div>
            <div class="admin-list">
                <div class="admin-list-item"><div class="admin-list-copy"><span>Metode pembayaran</span><strong><?= e(payment_method_label((string) $order['payment_method'], $order['payment_detail'])) ?></strong></div></div>
                <?php if ($order['payment_method'] === 'cod_check'): ?><div class="admin-alert admin-alert-info" style="margin:10px 0 0"><strong>COD Cek Dulu</strong><br>Pelanggan diperbolehkan memeriksa kondisi dan kesesuaian produk sebelum melakukan pembayaran kepada kurir.</div><?php endif; ?>
                <div class="admin-list-item"><div class="admin-list-copy"><span>Status pembayaran</span><strong><span class="admin-badge <?= e(payment_status_css_class((string) $order['payment_status'])) ?>"><?= e((string) $order['payment_status']) ?></span></strong></div></div>
                <div class="admin-list-item"><div class="admin-list-copy"><span>Pelanggan</span><strong><?= e((string) $order['customer_name']) ?></strong><small><?= e((string) $order['customer_email']) ?></small></div></div>
                <?php if ($order['cancellation_reason']): ?><div class="admin-list-item"><div class="admin-list-copy"><span>Alasan pembatalan</span><strong><?= e((string) $order['cancellation_reason']) ?></strong></div></div><?php endif; ?>
                <?php if ($order['rejection_reason']): ?><div class="admin-list-item"><div class="admin-list-copy"><span>Alasan penolakan</span><strong><?= e((string) $order['rejection_reason']) ?></strong></div></div><?php endif; ?>
            </div>
        </section>

        <?php if ($order['promotion_id']): ?>
        <section class="admin-card">
            <div class="admin-card-head"><h2>Voucher & Promo</h2><a href="promotion_detail.php?id=<?= (int) $order['promotion_id'] ?>">Detail</a></div>
            <div class="admin-list">
                <div class="admin-list-item"><div class="admin-list-copy"><span>Nama promo</span><strong><?= e((string) $order['promotion_name']) ?></strong></div></div>
                <div class="admin-list-item"><div class="admin-list-copy"><span>Kode</span><strong><?= e((string) ($order['voucher_code'] ?: 'Promo Otomatis')) ?></strong></div></div>
                <div class="admin-list-item"><div class="admin-list-copy"><span>Diskon produk</span><strong><?= rupiah($order['product_discount']) ?></strong></div></div>
                <div class="admin-list-item"><div class="admin-list-copy"><span>Diskon ongkir</span><strong><?= rupiah($order['shipping_discount']) ?></strong></div></div>
            </div>
        </section>
        <?php endif; ?>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Tindakan Pesanan</h2></div>
            <?php if ($nextActions === []): ?>
                <div class="admin-alert admin-alert-info">Pesanan berstatus <strong><?= e($currentStatus) ?></strong> dan tidak memiliki tindakan lanjutan.</div>
            <?php else: ?>
                <div class="admin-action-panel">
                    <?php foreach ($nextActions as $target => $label): ?>
                        <?php if ($target !== 'Ditolak'): ?>
                            <form class="admin-form-stack" method="post" action="order_update.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="target_status" value="<?= e($target) ?>">
                                <?php if ($target === 'Dikirim'): ?>
                                    <label class="admin-field"><span>Nomor resi</span><input class="admin-input" name="tracking_number" maxlength="100" value="<?= e((string) ($order['tracking_number'] ?? '')) ?>" required placeholder="Masukkan nomor resi"></label>
                                    <label class="admin-field"><span>Lokasi awal pengiriman</span><input class="admin-input" name="shipment_location" maxlength="150" value="<?= e(setting($pdo, 'store_city', '')) ?>" placeholder="Contoh: Gudang Rembang"></label>
                                <?php endif; ?>
                                <button class="admin-button admin-button-primary admin-button-block" type="submit" data-confirm="Ubah status menjadi <?= e($target) ?>?"><?= e($label) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="order_update.php">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                                <input type="hidden" name="target_status" value="Ditolak">
                                <label class="admin-field"><span>Alasan penolakan</span><select class="admin-select" name="rejection_reason" required><option value="">Pilih alasan</option><?php foreach ($rejectionOptions as $reason): ?><option value="<?= e($reason) ?>"><?= e($reason) ?></option><?php endforeach; ?></select></label>
                                <label class="admin-field"><span>Keterangan lainnya</span><textarea class="admin-textarea" name="other_reason" placeholder="Wajib jika memilih Lainnya"></textarea></label>
                                <button class="admin-button admin-button-danger admin-button-block" type="submit" data-confirm="Tolak pesanan ini dan kembalikan stok?">Tolak Pesanan</button>
                            </form>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-card">
            <div class="admin-card-head"><h2>Catatan Internal</h2></div>
            <form class="admin-form-stack" method="post" action="order_update.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <input type="hidden" name="target_status" value="save_notes">
                <textarea class="admin-textarea" name="admin_notes" maxlength="1000" placeholder="Catatan hanya terlihat oleh admin"><?= e((string) ($order['admin_notes'] ?? '')) ?></textarea>
                <button class="admin-button admin-button-secondary admin-button-block" type="submit">Simpan Catatan</button>
            </form>
        </section>
    </aside>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
