<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$query = trim((string) ($_GET['q'] ?? ''));
$resolution = strtoupper(trim((string) ($_GET['resolution'] ?? '')));
$allowedStatuses = ['SUBMITTED','UNDER_REVIEW','APPROVED','CUSTOMER_SHIPPED','STORE_RECEIVED','PROCESSING','COMPLETED','REJECTED','CANCELLED'];
$params = [];
$sql = "SELECT rr.*, o.order_number, u.name AS customer_name, u.email AS customer_email,
               COUNT(ri.id) AS item_count,
               GROUP_CONCAT(DISTINCT ri.product_name ORDER BY ri.id SEPARATOR ', ') AS products,
               SUM(ri.return_quantity) AS total_quantity
        FROM return_requests rr
        INNER JOIN orders o ON o.id = rr.order_id
        INNER JOIN users u ON u.id = rr.user_id
        INNER JOIN return_items ri ON ri.return_request_id = rr.id
        WHERE 1=1";
if (in_array($status, $allowedStatuses, true)) {
    $sql .= ' AND rr.status = :status';
    $params['status'] = $status;
}
if (in_array($resolution, ['REFUND','REPLACEMENT'], true)) {
    $sql .= ' AND rr.resolution_type = :resolution';
    $params['resolution'] = $resolution;
}
if ($query !== '') {
    $sql .= ' AND (rr.return_number LIKE :query OR o.order_number LIKE :query OR u.name LIKE :query OR u.email LIKE :query OR ri.product_name LIKE :query)';
    $params['query'] = '%' . $query . '%';
}
$sql .= ' GROUP BY rr.id ORDER BY rr.id DESC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$returns = $statement->fetchAll();

$stats = $pdo->query(
    "SELECT COUNT(*) AS total,
            SUM(status IN ('SUBMITTED','UNDER_REVIEW')) AS pending,
            SUM(status = 'CUSTOMER_SHIPPED') AS shipped,
            SUM(status = 'COMPLETED') AS completed,
            SUM(status = 'REJECTED') AS rejected,
            COALESCE(SUM(CASE WHEN refund_status = 'COMPLETED' THEN refund_amount ELSE 0 END),0) AS refunded
     FROM return_requests"
)->fetch() ?: [];

$pageTitle = 'Retur Barang';
$activeMenu = 'returns';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Retur Barang</h1><p>Kelola pengajuan, pemeriksaan, pengembalian dana, penggantian barang, dan stok hasil retur.</p></div><div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="../return_policy.php" target="_blank">Lihat Kebijakan</a></div></div>
<div class="admin-grid admin-stats"><article class="admin-stat-card"><span>Total Retur</span><strong><?= (int)($stats['total']??0) ?></strong></article><article class="admin-stat-card"><span>Menunggu Tinjauan</span><strong><?= (int)($stats['pending']??0) ?></strong></article><article class="admin-stat-card"><span>Barang Dikirim</span><strong><?= (int)($stats['shipped']??0) ?></strong></article><article class="admin-stat-card"><span>Selesai</span><strong><?= (int)($stats['completed']??0) ?></strong></article><article class="admin-stat-card"><span>Dana Dikembalikan</span><strong><?= rupiah($stats['refunded']??0) ?></strong></article></div>
<section class="admin-card"><form class="admin-filter-bar" method="get"><label><span>Cari</span><input class="admin-input" type="search" name="q" value="<?= e($query) ?>" placeholder="Nomor retur, pesanan, pelanggan, produk"></label><label><span>Status</span><select class="admin-select" name="status"><option value="">Semua status</option><?php foreach($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status===$option?'selected':'' ?>><?= e(return_status_label($option)) ?></option><?php endforeach; ?></select></label><label><span>Penyelesaian</span><select class="admin-select" name="resolution"><option value="">Semua</option><option value="REFUND" <?= $resolution==='REFUND'?'selected':'' ?>>Pengembalian Dana</option><option value="REPLACEMENT" <?= $resolution==='REPLACEMENT'?'selected':'' ?>>Penggantian Barang</option></select></label><button class="admin-button admin-button-primary" type="submit">Terapkan</button><a class="admin-button admin-button-secondary" href="returns.php">Reset</a></form></section>
<section class="admin-card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Retur</th><th>Pelanggan</th><th>Produk</th><th>Penyelesaian</th><th>Status</th><th>Tanggal</th><th></th></tr></thead><tbody><?php if($returns===[]): ?><tr><td colspan="7"><div class="admin-empty">Belum ada data retur.</div></td></tr><?php else: foreach($returns as $return): ?><tr><td><strong><?= e((string)$return['return_number']) ?></strong><small>Pesanan <?= e(order_code($return)) ?></small></td><td><strong><?= e((string)$return['customer_name']) ?></strong><small><?= e((string)$return['customer_email']) ?></small></td><td><strong><?= e(text_excerpt((string)$return['products'],80)) ?></strong><small><?= (int)$return['total_quantity'] ?> produk</small></td><td><strong><?= e(return_resolution_label((string)$return['resolution_type'])) ?></strong><?php if((string)$return['resolution_type']==='REFUND'): ?><small><?= rupiah($return['refund_amount']) ?></small><?php endif; ?></td><td><span class="admin-badge <?= e(return_status_css_class((string)$return['status'])) ?>"><?= e(return_status_label((string)$return['status'])) ?></span></td><td><?= format_datetime((string)$return['created_at']) ?></td><td><a class="admin-button admin-button-primary admin-button-small" href="return_detail.php?id=<?= (int)$return['id'] ?>">Detail</a></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
