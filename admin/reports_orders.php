<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period = report_period();
$statuses = ['', 'Menunggu Pembayaran', 'Menunggu Konfirmasi', 'Diproses', 'Dikirim', 'Selesai', 'Ditolak', 'Dibatalkan'];
$status = report_safe_choice($_GET['status'] ?? '', $statuses);
$payment = report_safe_choice($_GET['payment'] ?? '', ['', 'cod', 'cod_check', 'transfer_bank', 'e_wallet', 'qris', 'card']);
$courier = trim((string) ($_GET['courier'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));

$where = ['o.created_at BETWEEN :from_start AND :to_end'];
$params = ['from_start' => $period['from_start'], 'to_end' => $period['to_end']];
if ($status !== '') { $where[] = 'o.status = :status'; $params['status'] = $status; }
if ($payment !== '') {
    if (in_array($payment, ['cod', 'cod_check'], true)) { $where[] = 'o.payment_method = :payment'; }
    else { $where[] = 'o.payment_category = :payment'; }
    $params['payment'] = $payment;
}
if ($courier !== '') { $where[] = 'o.shipping_courier_code = :courier'; $params['courier'] = $courier; }
if ($search !== '') {
    $where[] = '(o.order_number LIKE :search_order OR u.name LIKE :search_name OR u.email LIKE :search_email OR o.shipping_phone LIKE :search_phone)';
    $searchValue = '%' . $search . '%';
    $params['search_order'] = $searchValue;
    $params['search_name'] = $searchValue;
    $params['search_email'] = $searchValue;
    $params['search_phone'] = $searchValue;
}
$whereSql = implode(' AND ', $where);

$summary = report_fetch_one($pdo, "SELECT COUNT(*) AS total,
        SUM(o.status = 'Selesai') AS completed,
        SUM(o.status = 'Dibatalkan') AS cancelled,
        SUM(o.status = 'Ditolak') AS rejected,
        SUM(o.status IN ('Menunggu Pembayaran','Menunggu Konfirmasi')) AS waiting,
        COALESCE(SUM(o.total_amount), 0) AS order_value
    FROM orders o INNER JOIN users u ON u.id = o.user_id WHERE {$whereSql}", $params);
$statusRows = report_fetch_all($pdo, "SELECT o.status, COUNT(*) AS total FROM orders o INNER JOIN users u ON u.id=o.user_id WHERE {$whereSql} GROUP BY o.status ORDER BY total DESC", $params);
$orderRows = report_fetch_all($pdo, "SELECT o.*, u.name AS customer_name, u.email AS customer_email,
        (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.order_id=o.id) AS item_quantity
    FROM orders o INNER JOIN users u ON u.id=o.user_id
    WHERE {$whereSql} ORDER BY o.created_at DESC LIMIT 200", $params);
$couriers = $pdo->query("SELECT courier_code, courier_name FROM shipping_couriers ORDER BY courier_name")->fetchAll();

$pageTitle = 'Laporan Pesanan';
$activeMenu = 'reports';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Laporan Pesanan</h1><p>Analisis status, pembatalan, penolakan, pembayaran, kurir, dan pelanggan.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-secondary" target="_blank" href="<?= e(report_print_url('orders', $period, ['status'=>$status,'payment'=>$payment,'courier'=>$courier,'search'=>$search])) ?>">Cetak</a><a class="admin-button admin-button-primary" href="<?= e(report_export_url('orders', $period, ['status'=>$status,'payment'=>$payment,'courier'=>$courier,'search'=>$search])) ?>">Ekspor CSV</a></div>
</div>
<?php report_nav('orders', $period); ?>
<?php
$courierOptions = ['' => 'Semua kurir'];
foreach ($couriers as $row) $courierOptions[(string) $row['courier_code']] = (string) $row['courier_name'];
report_filter_form($period, [
    ['label'=>'Status','name'=>'status','value'=>$status,'options'=>array_combine($statuses, array_map(static fn($v)=>$v===''?'Semua status':$v,$statuses))],
    ['label'=>'Pembayaran','name'=>'payment','value'=>$payment,'options'=>[''=>'Semua pembayaran','cod'=>'COD','cod_check'=>'COD Cek Dulu','transfer_bank'=>'Transfer Bank','e_wallet'=>'E-Wallet','qris'=>'QRIS','card'=>'Kartu Kredit/Debit']],
    ['label'=>'Kurir','name'=>'courier','value'=>$courier,'options'=>$courierOptions],
    ['label'=>'Cari pesanan/pelanggan','name'=>'search','value'=>$search,'type'=>'text','placeholder'=>'Nomor pesanan, nama, email, telepon'],
]);
?>
<section class="admin-grid report-kpi-grid">
    <article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Total Pesanan</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong><small>Nilai <?= rupiah($summary['order_value'] ?? 0) ?></small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">✅</div><div class="admin-stat-copy"><span>Selesai</span><strong><?= (int) ($summary['completed'] ?? 0) ?></strong><small><?= number_format(report_percent((int)($summary['completed']??0),(int)($summary['total']??0)),1,',','.') ?>% dari pesanan</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">⏳</div><div class="admin-stat-copy"><span>Menunggu</span><strong><?= (int) ($summary['waiting'] ?? 0) ?></strong><small>Perlu ditindaklanjuti</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🚫</div><div class="admin-stat-copy"><span>Dibatalkan</span><strong><?= (int) ($summary['cancelled'] ?? 0) ?></strong><small><?= number_format(report_percent((int)($summary['cancelled']??0),(int)($summary['total']??0)),1,',','.') ?>%</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">❌</div><div class="admin-stat-copy"><span>Ditolak</span><strong><?= (int) ($summary['rejected'] ?? 0) ?></strong><small><?= number_format(report_percent((int)($summary['rejected']??0),(int)($summary['total']??0)),1,',','.') ?>%</small></div></article>
</section>

<section class="admin-card report-section-gap">
    <div class="admin-card-head"><h2>Distribusi Status</h2></div>
    <?php if ($statusRows === []): ?><div class="admin-empty"><strong>Belum ada data</strong><p>Tidak ada pesanan pada filter ini.</p></div><?php else: ?><div class="report-status-list report-status-grid"><?php foreach($statusRows as $row): $pct=report_percent((int)$row['total'],(int)($summary['total']??0)); ?><div><div><span class="admin-badge <?= e(status_css_class((string)$row['status'])) ?>"><?= e((string)$row['status']) ?></span><strong><?= (int)$row['total'] ?> · <?= number_format($pct,1,',','.') ?>%</strong></div><div class="report-progress"><span style="width:<?= e(number_format($pct,2,'.','')) ?>%"></span></div></div><?php endforeach; ?></div><?php endif; ?>
</section>

<section class="admin-card report-section-gap">
    <div class="admin-card-head"><div><h2>Daftar Pesanan</h2><small>Maksimal 200 data terbaru.</small></div></div>
    <?php if ($orderRows === []): ?><div class="admin-empty"><strong>Pesanan tidak ditemukan</strong><p>Ubah filter untuk melihat data lain.</p></div><?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Produk</th><th>Pembayaran</th><th>Pengiriman</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach($orderRows as $row): ?><tr>
        <td><strong><?= e(order_code($row)) ?></strong><br><small><?= e(format_datetime((string)$row['created_at'])) ?></small></td>
        <td><strong><?= e((string)$row['customer_name']) ?></strong><br><small><?= e((string)$row['customer_email']) ?></small></td>
        <td><?= (int)$row['item_quantity'] ?> unit</td>
        <td><?= e(payment_method_label((string)$row['payment_method'],(string)($row['payment_detail']??''))) ?><br><span class="admin-badge <?= e(payment_status_css_class((string)$row['payment_status'])) ?>"><?= e((string)$row['payment_status']) ?></span></td>
        <td><?= e(trim((string)($row['shipping_courier_name']??'') . ' ' . (string)($row['shipping_service_name']??'')) ?: '-') ?><?php if(!empty($row['tracking_number'])):?><br><small><?= e((string)$row['tracking_number']) ?></small><?php endif;?></td>
        <td><strong><?= rupiah($row['total_amount']) ?></strong></td>
        <td><span class="admin-badge <?= e(status_css_class((string)$row['status'])) ?>"><?= e((string)$row['status']) ?></span></td>
        <td><a class="admin-button admin-button-primary admin-button-small" href="order_detail.php?id=<?= (int)$row['id'] ?>">Detail</a></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
