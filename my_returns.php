<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
$allowedStatuses = ['SUBMITTED','UNDER_REVIEW','APPROVED','CUSTOMER_SHIPPED','STORE_RECEIVED','PROCESSING','COMPLETED','REJECTED','CANCELLED'];
$params = ['user_id' => (int) $user['id']];
$sql = "SELECT rr.*, o.order_number,
               COUNT(ri.id) AS item_count,
               GROUP_CONCAT(DISTINCT ri.product_name ORDER BY ri.id SEPARATOR ', ') AS product_names,
               SUM(ri.return_quantity) AS total_return_quantity
        FROM return_requests rr
        INNER JOIN orders o ON o.id = rr.order_id
        INNER JOIN return_items ri ON ri.return_request_id = rr.id
        WHERE rr.user_id = :user_id";
if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND rr.status = :status';
    $params['status'] = $statusFilter;
}
$sql .= ' GROUP BY rr.id ORDER BY rr.id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$returns = $statement->fetchAll();

$pageTitle = 'Retur Saya';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Layanan setelah pembelian</span><h1>Retur Saya</h1><p>Pantau pengajuan, pengiriman barang kembali, pemeriksaan, dan penyelesaian retur.</p></div></section>
<section class="section"><div class="container">
    <div class="return-toolbar"><div class="return-filter-links"><a class="<?= $statusFilter === '' ? 'active' : '' ?>" href="my_returns.php">Semua</a><a class="<?= in_array($statusFilter,['SUBMITTED','UNDER_REVIEW'],true) ? 'active' : '' ?>" href="my_returns.php?status=SUBMITTED">Menunggu</a><a class="<?= $statusFilter === 'APPROVED' ? 'active' : '' ?>" href="my_returns.php?status=APPROVED">Disetujui</a><a class="<?= $statusFilter === 'COMPLETED' ? 'active' : '' ?>" href="my_returns.php?status=COMPLETED">Selesai</a></div><a class="button button-secondary button-small" href="return_policy.php">Kebijakan Retur</a></div>
    <?php if ($returns === []): ?><div class="empty-state"><div class="empty-icon">↩️</div><h2>Belum ada pengajuan retur</h2><p>Produk dari pesanan selesai yang masih dalam periode retur dapat diajukan melalui halaman Pesanan.</p><a class="button button-primary" href="orders.php">Buka Pesanan</a></div>
    <?php else: ?><div class="return-list"><?php foreach ($returns as $return): ?><article class="return-card"><div class="return-card-top"><div><span>Nomor Retur</span><strong><?= e((string) $return['return_number']) ?></strong><small>Pesanan <?= e(order_code($return)) ?></small></div><span class="status-pill <?= e(return_status_css_class((string) $return['status'])) ?>"><?= e(return_status_label((string) $return['status'])) ?></span></div><div class="return-card-grid"><div><span>Produk</span><strong><?= e(text_excerpt((string) $return['product_names'], 100)) ?></strong><small><?= (int) $return['total_return_quantity'] ?> produk</small></div><div><span>Penyelesaian</span><strong><?= e(return_resolution_label((string) $return['resolution_type'])) ?></strong></div><div><span>Diajukan</span><strong><?= format_datetime((string) $return['created_at']) ?></strong></div><?php if ((string)$return['resolution_type']==='REFUND'): ?><div><span>Nilai pengajuan</span><strong><?= rupiah($return['refund_amount']) ?></strong><small><?= e(return_refund_status_label((string)$return['refund_status'])) ?></small></div><?php endif; ?></div><div class="return-card-actions"><a class="button button-primary button-small" href="return_detail.php?id=<?= (int) $return['id'] ?>">Lihat Detail</a><?php if ((string)$return['status']==='APPROVED'): ?><a class="button button-secondary button-small" href="return_detail.php?id=<?= (int)$return['id'] ?>#kirim-retur">Masukkan Resi Retur</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?>
</div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
