<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$allowedStatuses = ['Semua', 'Menunggu Pembayaran', 'Menunggu Konfirmasi', 'Diproses', 'Dikirim', 'Selesai', 'Ditolak', 'Dibatalkan'];
$allowedPayments = ['Semua', 'cod', 'cod_check', 'dana', 'ovo', 'gopay', 'qris', 'transfer_bank', 'credit_debit_card'];
$status = trim((string) ($_GET['status'] ?? 'Semua'));
$payment = trim((string) ($_GET['payment'] ?? 'Semua'));
$search = trim((string) ($_GET['q'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

if (!in_array($status, $allowedStatuses, true)) $status = 'Semua';
if (!in_array($payment, $allowedPayments, true)) $payment = 'Semua';
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = '';
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) $dateTo = '';

$where = ['1=1'];
$params = [];
if ($status !== 'Semua') { $where[] = 'o.status = :status'; $params['status'] = $status; }
if ($payment !== 'Semua') {
    if ($payment === 'transfer_bank') {
        $where[] = "o.payment_category = 'transfer_bank'";
    } else {
        $where[] = 'o.payment_method = :payment';
        $params['payment'] = $payment;
    }
}
if ($dateFrom !== '') { $where[] = 'DATE(o.created_at) >= :date_from'; $params['date_from'] = $dateFrom; }
if ($dateTo !== '') { $where[] = 'DATE(o.created_at) <= :date_to'; $params['date_to'] = $dateTo; }
if ($search !== '') {
    $where[] = "(o.order_number LIKE :search OR o.shipping_name LIKE :search OR o.shipping_phone LIKE :search OR u.email LIKE :search OR EXISTS (SELECT 1 FROM order_items oi2 WHERE oi2.order_id = o.id AND oi2.product_name LIKE :search))";
    $params['search'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$countStatement = $pdo->prepare("SELECT COUNT(*) FROM orders o INNER JOIN users u ON u.id = o.user_id WHERE {$whereSql}");
$countStatement->execute($params);
$totalRows = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT o.id, o.order_number, o.status, o.payment_status, o.payment_category, o.payment_method,
               o.payment_detail, o.total_amount, o.shipping_name, o.shipping_phone,
               o.shipping_courier_name, o.shipping_service_code, o.tracking_number, o.created_at,
               u.name AS customer_name, u.email AS customer_email,
               COUNT(oi.id) AS item_count,
               GROUP_CONCAT(CONCAT(oi.product_name, ' × ', oi.quantity) ORDER BY oi.id SEPARATOR ' • ') AS product_summary
        FROM orders o
        INNER JOIN users u ON u.id = o.user_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE {$whereSql}
        GROUP BY o.id
        ORDER BY o.id DESC
        LIMIT {$perPage} OFFSET {$offset}";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$orders = $statement->fetchAll();

$countRows = $pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();
$statusCounts = [];
foreach ($countRows as $row) $statusCounts[(string) $row['status']] = (int) $row['total'];

$queryParams = $_GET;
unset($queryParams['page']);
$paginationBase = http_build_query($queryParams);

$pageTitle = 'Kelola Pesanan';
$activeMenu = 'orders';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Kelola Pesanan</h1><p>Pantau semua transaksi, metode pembayaran, dan tahapan pemenuhan pesanan.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="export_report.php?type=orders&<?= e(http_build_query(array_filter(['status'=>$status === 'Semua' ? '' : $status,'payment'=>$payment === 'Semua' ? '' : $payment,'date_from'=>$dateFrom,'date_to'=>$dateTo]))) ?>">Ekspor CSV</a></div>
</div>

<section class="admin-grid admin-grid-4" style="margin-bottom:18px">
    <?php foreach (['Menunggu Konfirmasi','Diproses','Dikirim','Selesai'] as $summaryStatus): ?>
        <a class="admin-stat-card" href="orders.php?status=<?= urlencode($summaryStatus) ?>">
            <div class="admin-stat-copy"><span><?= e($summaryStatus) ?></span><strong><?= (int) ($statusCounts[$summaryStatus] ?? 0) ?></strong></div>
            <span class="admin-badge <?= e(status_css_class($summaryStatus)) ?>">Pesanan</span>
        </a>
    <?php endforeach; ?>
</section>

<div class="admin-filter-card">
    <form class="admin-filter-form" method="get">
        <label class="admin-field"><span>Pencarian</span><input class="admin-input" type="search" name="q" value="<?= e($search) ?>" placeholder="Nomor, pelanggan, telepon, produk"></label>
        <label class="admin-field"><span>Status</span><select class="admin-select" name="status"><?php foreach ($allowedStatuses as $item): ?><option value="<?= e($item) ?>" <?= $status === $item ? 'selected' : '' ?>><?= e($item) ?></option><?php endforeach; ?></select></label>
        <label class="admin-field"><span>Pembayaran</span><select class="admin-select" name="payment">
            <option value="Semua" <?= $payment === 'Semua' ? 'selected' : '' ?>>Semua metode</option>
            <option value="cod" <?= $payment === 'cod' ? 'selected' : '' ?>>COD</option>
            <option value="cod_check" <?= $payment === 'cod_check' ? 'selected' : '' ?>>COD Cek Dulu</option>
            <option value="dana" <?= $payment === 'dana' ? 'selected' : '' ?>>DANA</option>
            <option value="ovo" <?= $payment === 'ovo' ? 'selected' : '' ?>>OVO</option>
            <option value="gopay" <?= $payment === 'gopay' ? 'selected' : '' ?>>GoPay</option>
            <option value="qris" <?= $payment === 'qris' ? 'selected' : '' ?>>QRIS</option>
            <option value="transfer_bank" <?= $payment === 'transfer_bank' ? 'selected' : '' ?>>Transfer Bank</option>
            <option value="credit_debit_card" <?= $payment === 'credit_debit_card' ? 'selected' : '' ?>>Kartu Kredit/Debit</option>
        </select></label>
        <label class="admin-field"><span>Dari tanggal</span><input class="admin-input" type="date" name="date_from" value="<?= e($dateFrom) ?>"></label>
        <label class="admin-field"><span>Sampai tanggal</span><input class="admin-input" type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
        <button class="admin-button admin-button-primary" type="submit">Terapkan</button>
    </form>
</div>

<?php if ($orders === []): ?>
    <div class="admin-empty"><strong>Pesanan tidak ditemukan</strong><p>Ubah filter atau tunggu pesanan baru dari pelanggan.</p><a class="admin-button admin-button-secondary" href="orders.php">Reset Filter</a></div>
<?php else: ?>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Produk</th><th>Pengiriman</th><th>Pembayaran</th><th>Total</th><th>Status</th><th>Tanggal</th><th>Aksi</th></tr></thead>
            <tbody>
            <?php foreach ($orders as $order): ?>
                <tr>
                    <td><strong><?= e(order_code($order)) ?></strong><br><small><?= (int) $order['item_count'] ?> jenis produk</small></td>
                    <td><div class="admin-customer-cell"><strong><?= e((string) $order['customer_name']) ?></strong><small><?= e((string) $order['customer_email']) ?></small><small><?= e((string) $order['shipping_phone']) ?></small></div></td>
                    <td style="max-width:260px"><span><?= e((string) ($order['product_summary'] ?? '-')) ?></span></td>
                    <td><strong><?= e((string) ($order['shipping_courier_name'] ?: '-')) ?> <?= e((string) ($order['shipping_service_code'] ?: '')) ?></strong><br><small><?= e((string) ($order['tracking_number'] ?: 'Resi belum tersedia')) ?></small></td>
                    <td><strong><?= e(payment_method_label((string) $order['payment_method'], $order['payment_detail'])) ?></strong><br><span class="admin-badge <?= e(payment_status_css_class((string) $order['payment_status'])) ?>"><?= e((string) $order['payment_status']) ?></span></td>
                    <td><strong><?= rupiah($order['total_amount']) ?></strong></td>
                    <td><span class="admin-badge <?= e(status_css_class((string) $order['status'])) ?>"><?= e((string) $order['status']) ?></span></td>
                    <td><?= format_datetime((string) $order['created_at']) ?></td>
                    <td><div class="admin-table-actions"><a class="admin-button admin-button-primary admin-button-small" href="order_detail.php?id=<?= (int) $order['id'] ?>">Detail</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="admin-pagination" aria-label="Paginasi">
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a class="<?= $i === $page ? 'active' : '' ?>" href="orders.php?<?= e($paginationBase . ($paginationBase ? '&' : '') . 'page=' . $i) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
