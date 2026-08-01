<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period = report_period();
$payment = report_safe_choice($_GET['payment'] ?? '', ['', 'cod', 'cod_check', 'transfer_bank', 'e_wallet', 'qris', 'card']);
$params = ['from_start' => $period['from_start'], 'to_end' => $period['to_end']];
$where = ["o.status = 'Selesai'", 'o.created_at BETWEEN :from_start AND :to_end'];
if ($payment !== '') {
    if (in_array($payment, ['cod', 'cod_check'], true)) {
        $where[] = 'o.payment_method = :payment';
        $params['payment'] = $payment;
    } else {
        $where[] = 'o.payment_category = :payment';
        $params['payment'] = $payment;
    }
}
$whereSql = implode(' AND ', $where);

$summary = report_fetch_one($pdo, "SELECT
        COUNT(*) AS completed_orders,
        COALESCE(SUM(o.subtotal_amount), 0) AS gross_product_sales,
        COALESCE(SUM(o.product_discount), 0) AS product_discount,
        COALESCE(SUM(o.shipping_cost), 0) AS shipping_cost,
        COALESCE(SUM(o.shipping_discount), 0) AS shipping_discount,
        COALESCE(SUM(o.cod_fee), 0) AS cod_fee,
        COALESCE(SUM(o.total_amount), 0) AS total_payment,
        COALESCE(AVG(o.total_amount), 0) AS average_order
    FROM orders o WHERE {$whereSql}", $params);

$sold = report_fetch_one($pdo, "SELECT COALESCE(SUM(oi.quantity), 0) AS quantity
    FROM order_items oi INNER JOIN orders o ON o.id = oi.order_id
    WHERE {$whereSql}", $params);
$refund = report_completed_refund($pdo, $period);
$netSales = max(0, (float) ($summary['gross_product_sales'] ?? 0) - (float) ($summary['product_discount'] ?? 0) - $refund);

$dailyRows = report_fetch_all($pdo, "SELECT DATE(o.created_at) AS report_date,
        COUNT(*) AS completed_orders,
        COALESCE(SUM(o.subtotal_amount), 0) AS gross_product_sales,
        COALESCE(SUM(o.product_discount), 0) AS product_discount,
        COALESCE(SUM(o.total_amount), 0) AS total_payment
    FROM orders o WHERE {$whereSql}
    GROUP BY DATE(o.created_at) ORDER BY report_date", $params);
$maxDaily = max(array_map(static fn(array $row): float => (float) $row['gross_product_sales'] - (float) $row['product_discount'], $dailyRows) ?: [0]);

$orderRows = report_fetch_all($pdo, "SELECT o.id, o.order_number, o.created_at, o.subtotal_amount,
        o.product_discount, o.shipping_cost, o.shipping_discount, o.cod_fee, o.total_amount,
        o.payment_category, o.payment_method, o.payment_detail, u.name AS customer_name
    FROM orders o INNER JOIN users u ON u.id = o.user_id
    WHERE {$whereSql} ORDER BY o.created_at DESC LIMIT 100", $params);

$pageTitle = 'Laporan Penjualan';
$activeMenu = 'reports';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Laporan Penjualan</h1><p>Penjualan produk hanya dihitung dari pesanan berstatus Selesai.</p></div>
    <div class="admin-page-actions">
        <a class="admin-button admin-button-secondary" target="_blank" href="<?= e(report_print_url('sales', $period, ['payment' => $payment])) ?>">Cetak</a>
        <a class="admin-button admin-button-primary" href="<?= e(report_export_url('sales', $period, ['payment' => $payment])) ?>">Ekspor CSV</a>
    </div>
</div>
<?php report_nav('sales', $period); ?>
<?php report_filter_form($period, [[
    'label' => 'Kelompok pembayaran', 'name' => 'payment', 'value' => $payment,
    'options' => ['' => 'Semua pembayaran', 'cod' => 'COD', 'cod_check' => 'COD Cek Dulu', 'transfer_bank' => 'Transfer Bank', 'e_wallet' => 'E-Wallet', 'qris' => 'QRIS', 'card' => 'Kartu Kredit/Debit'],
]]); ?>

<section class="admin-grid report-kpi-grid">
    <article class="admin-stat-card"><div class="admin-stat-icon">💰</div><div class="admin-stat-copy"><span>Penjualan Bersih</span><strong><?= rupiah($netSales) ?></strong><small>Produk setelah diskon dan refund</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🧾</div><div class="admin-stat-copy"><span>Pesanan Selesai</span><strong><?= (int) ($summary['completed_orders'] ?? 0) ?></strong><small><?= (int) ($sold['quantity'] ?? 0) ?> produk terjual</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🎟️</div><div class="admin-stat-copy"><span>Diskon Produk</span><strong><?= rupiah($summary['product_discount'] ?? 0) ?></strong><small>Belum termasuk diskon ongkir</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">📊</div><div class="admin-stat-copy"><span>Rata-rata Transaksi</span><strong><?= rupiah($summary['average_order'] ?? 0) ?></strong><small>Nilai total pembayaran</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🚚</div><div class="admin-stat-copy"><span>Ongkir Bersih</span><strong><?= rupiah(max(0, (float) ($summary['shipping_cost'] ?? 0) - (float) ($summary['shipping_discount'] ?? 0))) ?></strong><small>Ditampilkan terpisah dari produk</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">↩️</div><div class="admin-stat-copy"><span>Refund Selesai</span><strong><?= rupiah($refund) ?></strong><small>Mengurangi penjualan bersih</small></div></article>
</section>

<section class="admin-card report-section-gap">
    <div class="admin-card-head"><div><h2>Tren Harian</h2><small><?= e(report_period_label($period['from'], $period['to'])) ?></small></div></div>
    <?php if ($dailyRows === []): ?><div class="admin-empty"><strong>Belum ada penjualan</strong><p>Ubah filter periode atau selesaikan pesanan terlebih dahulu.</p></div><?php else: ?>
    <div class="admin-chart report-chart">
        <?php foreach ($dailyRows as $row): ?>
            <?php $dailyNet = max(0, (float) $row['gross_product_sales'] - (float) $row['product_discount']); $width = $maxDaily > 0 ? max(2, ($dailyNet / $maxDaily) * 100) : 2; ?>
            <div class="admin-chart-row"><span class="admin-chart-label"><?= e(date('d/m', strtotime((string) $row['report_date']))) ?></span><div class="admin-chart-track"><div class="admin-chart-bar" style="width:<?= e(number_format($width, 2, '.', '')) ?>%"></div></div><span class="admin-chart-value"><?= rupiah($dailyNet) ?> · <?= (int) $row['completed_orders'] ?></span></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="admin-card report-section-gap">
    <div class="admin-card-head"><div><h2>Transaksi Selesai</h2><small>Maksimal 100 transaksi terbaru pada filter.</small></div></div>
    <?php if ($orderRows === []): ?><div class="admin-empty"><strong>Belum ada transaksi selesai</strong><p>Tidak ada data pada periode yang dipilih.</p></div><?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table report-table-wide"><thead><tr><th>Pesanan</th><th>Pelanggan</th><th>Subtotal</th><th>Diskon</th><th>Ongkir</th><th>COD</th><th>Total</th><th>Pembayaran</th></tr></thead><tbody>
    <?php foreach ($orderRows as $row): ?><tr>
        <td><a href="order_detail.php?id=<?= (int) $row['id'] ?>"><strong><?= e(order_code($row)) ?></strong></a><br><small><?= e(format_datetime((string) $row['created_at'])) ?></small></td>
        <td><?= e((string) $row['customer_name']) ?></td>
        <td><?= rupiah($row['subtotal_amount']) ?></td>
        <td class="report-negative">-<?= rupiah($row['product_discount']) ?></td>
        <td><?= rupiah(max(0, (float) $row['shipping_cost'] - (float) $row['shipping_discount'])) ?></td>
        <td><?= rupiah($row['cod_fee']) ?></td>
        <td><strong><?= rupiah($row['total_amount']) ?></strong></td>
        <td><?= e(payment_method_label((string) $row['payment_method'], (string) ($row['payment_detail'] ?? ''))) ?><?php if (!is_cod_payment((string) $row['payment_method'])): ?><br><small>Simulasi</small><?php endif; ?></td>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</section>
<script src="assets/admin-reports.js"></script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
