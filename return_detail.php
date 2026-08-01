<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$returnId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$returnId) {
    flash('warning', 'Pengajuan retur tidak valid.');
    redirect('my_returns.php');
}

$statement = $pdo->prepare(
    "SELECT rr.*, o.order_number, o.shipping_name, o.shipping_phone, o.shipping_address,
            o.shipping_city, o.shipping_province
     FROM return_requests rr
     INNER JOIN orders o ON o.id = rr.order_id
     WHERE rr.id = :id AND rr.user_id = :user_id
     LIMIT 1"
);
$statement->execute(['id' => $returnId, 'user_id' => (int) $user['id']]);
$return = $statement->fetch();
if (!$return) {
    flash('danger', 'Pengajuan retur tidak ditemukan atau bukan milik akun Anda.');
    redirect('my_returns.php');
}

$itemStatement = $pdo->prepare('SELECT * FROM return_items WHERE return_request_id = :id ORDER BY id');
$itemStatement->execute(['id' => $returnId]);
$items = $itemStatement->fetchAll();
$imageStatement = $pdo->prepare('SELECT * FROM return_images WHERE return_request_id = :id ORDER BY sort_order, id');
$imageStatement->execute(['id' => $returnId]);
$images = $imageStatement->fetchAll();
$historyStatement = $pdo->prepare('SELECT * FROM return_histories WHERE return_request_id = :id ORDER BY id ASC');
$historyStatement->execute(['id' => $returnId]);
$histories = $historyStatement->fetchAll();

$pageTitle = 'Detail Retur';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Detail pengajuan</span><h1><?= e((string) $return['return_number']) ?></h1><p>Pantau status dan tindakan yang diperlukan untuk menyelesaikan retur.</p></div></section>
<section class="section"><div class="container return-detail-layout">
    <div class="return-detail-main">
        <section class="return-detail-card"><div class="return-detail-head"><div><span>Pesanan <?= e(order_code($return)) ?></span><h2><?= e(return_resolution_label((string) $return['resolution_type'])) ?></h2></div><span class="status-pill <?= e(return_status_css_class((string) $return['status'])) ?>"><?= e(return_status_label((string) $return['status'])) ?></span></div><div class="return-detail-meta"><div><span>Diajukan</span><strong><?= format_datetime((string) $return['created_at']) ?></strong></div><div><span>Alasan</span><strong><?= e((string) $return['reason_summary']) ?></strong></div><?php if ((string)$return['resolution_type']==='REFUND'): ?><div><span>Nilai pengajuan</span><strong><?= rupiah($return['refund_amount']) ?></strong></div><div><span>Status dana</span><strong><?= e(return_refund_status_label((string)$return['refund_status'])) ?></strong></div><?php endif; ?></div><?php if ($return['customer_note']): ?><div class="return-note-box"><strong>Keterangan pelanggan</strong><p><?= nl2br(e((string)$return['customer_note'])) ?></p></div><?php endif; ?><?php if ($return['rejection_reason']): ?><div class="return-note-box danger"><strong>Alasan penolakan</strong><p><?= e((string)$return['rejection_reason']) ?></p></div><?php endif; ?><?php if ($return['admin_note']): ?><div class="return-note-box"><strong>Catatan toko</strong><p><?= nl2br(e((string)$return['admin_note'])) ?></p></div><?php endif; ?></section>

        <section class="return-detail-card"><h2>Produk Diretur</h2><div class="return-item-list"><?php foreach ($items as $item): ?><div class="return-item-row"><img src="<?= e((string)($item['product_image'] ?: 'assets/images/bag.svg')) ?>" alt="<?= e((string)$item['product_name']) ?>"><div><strong><?= e((string)$item['product_name']) ?></strong><?php if($item['variant_name']): ?><span><?= e((string)$item['variant_name']) ?><?= $item['variant_sku']?' · '.e((string)$item['variant_sku']):'' ?></span><?php endif; ?><small><?= (int)$item['return_quantity'] ?> dari <?= (int)$item['purchased_quantity'] ?> produk · <?= rupiah($item['item_price']) ?>/produk</small><small>Status stok: <?= e((string)$item['restock_status']) ?></small></div><strong><?= rupiah($item['requested_refund_amount']) ?></strong></div><?php endforeach; ?></div><?php if($images!==[]): ?><div class="return-image-grid"><?php foreach($images as $image): ?><a href="<?= e((string)$image['image_path']) ?>" target="_blank"><img src="<?= e((string)$image['image_path']) ?>" alt="Bukti retur"><span><?= e((string)$image['image_type']) ?></span></a><?php endforeach; ?></div><?php endif; ?></section>

        <?php if ((string)$return['status']==='APPROVED'): ?><section class="return-detail-card" id="kirim-retur"><h2>Kirim Barang Kembali</h2><p>Masukkan kurir dan nomor resi setelah barang diserahkan kepada jasa pengiriman.</p><form method="post" action="return_action.php" class="return-inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="ship"><label><span>Kurir pengembalian</span><input type="text" name="customer_return_courier" maxlength="100" required></label><label><span>Nomor resi</span><input type="text" name="customer_return_tracking_number" maxlength="100" required></label><button class="button button-primary" type="submit">Simpan Resi Retur</button></form></section><?php endif; ?>

        <?php if ($return['customer_return_tracking_number']): ?><section class="return-detail-card"><h2>Pengiriman Barang Retur</h2><div class="return-shipping-info"><div><span>Kurir</span><strong><?= e((string)$return['customer_return_courier']) ?></strong></div><div><span>Nomor resi</span><strong><?= e((string)$return['customer_return_tracking_number']) ?></strong></div><div><span>Dikirim</span><strong><?= format_datetime((string)$return['customer_shipped_at']) ?></strong></div></div></section><?php endif; ?>

        <section class="return-detail-card"><h2>Timeline Retur</h2><div class="return-timeline"><?php foreach($histories as $history): ?><div class="return-timeline-item"><span></span><div><strong><?= e((string)$history['title']) ?></strong><small><?= format_datetime((string)$history['created_at']) ?></small><?php if($history['description']): ?><p><?= e((string)$history['description']) ?></p><?php endif; ?></div></div><?php endforeach; ?></div></section>
    </div>
    <aside class="return-detail-side"><section class="return-info-card"><h3>Informasi Penyelesaian</h3><?php if((string)$return['resolution_type']==='REFUND'): ?><p><strong><?= e((string)($return['refund_method'] ?: 'Metode belum ditentukan')) ?></strong><br><?= e((string)($return['refund_bank_name'] ?: '')) ?><br><?= e((string)($return['refund_account_name'] ?: '')) ?><br><?= e((string)($return['refund_account_number'] ?: '')) ?></p><?php if($return['refund_reference']): ?><p>Referensi: <strong><?= e((string)$return['refund_reference']) ?></strong></p><?php endif; ?><?php else: ?><p>Produk pengganti akan dikirim setelah pemeriksaan barang retur selesai.</p><?php if($return['replacement_tracking_number']): ?><p>Resi pengganti: <strong><?= e((string)$return['replacement_tracking_number']) ?></strong></p><?php endif; ?><?php endif; ?></section><?php if(in_array((string)$return['status'],['SUBMITTED','UNDER_REVIEW'],true)): ?><form method="post" action="return_action.php" class="return-cancel-card" onsubmit="return confirm('Batalkan pengajuan retur ini?')"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_id" value="<?= $returnId ?>"><input type="hidden" name="action" value="cancel"><button class="button button-danger button-small" type="submit">Batalkan Pengajuan</button></form><?php endif; ?><a class="button button-secondary" href="my_returns.php">← Kembali ke Retur Saya</a></aside>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
