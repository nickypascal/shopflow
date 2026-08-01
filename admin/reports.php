<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period = report_period();
$previous = report_previous_period($period['from'], $period['to']);
$params = ['from_start' => $period['from_start'], 'to_end' => $period['to_end']];
$previousParams = ['from_start' => $previous['from_start'], 'to_end' => $previous['to_end']];

$summarySql = "SELECT
        COUNT(*) AS order_count,
        SUM(status = 'Selesai') AS completed_count,
        SUM(status IN ('Menunggu Konfirmasi','Menunggu Pembayaran')) AS waiting_count,
        SUM(status = 'Diproses') AS processing_count,
        SUM(status = 'Dikirim') AS shipped_count,
        SUM(status = 'Ditolak') AS rejected_count,
        SUM(status = 'Dibatalkan') AS cancelled_count,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN subtotal_amount ELSE 0 END), 0) AS gross_product_sales,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN product_discount ELSE 0 END), 0) AS product_discount,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN shipping_cost ELSE 0 END), 0) AS shipping_income,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN shipping_discount ELSE 0 END), 0) AS shipping_discount,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN cod_fee ELSE 0 END), 0) AS cod_fee,
        COALESCE(SUM(CASE WHEN status = 'Selesai' THEN total_amount ELSE 0 END), 0) AS completed_payment,
        COALESCE(AVG(CASE WHEN status = 'Selesai' THEN total_amount END), 0) AS average_order
    FROM orders
    WHERE created_at BETWEEN :from_start AND :to_end";
$summary = report_fetch_one($pdo, $summarySql, $params);
$previousSummary = report_fetch_one($pdo, $summarySql, $previousParams);

$itemSummary = report_fetch_one(
    $pdo,
    "SELECT COALESCE(SUM(oi.quantity), 0) AS sold_quantity
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE o.status = 'Selesai' AND o.created_at BETWEEN :from_start AND :to_end",
    $params
);
$refundAmount = report_completed_refund($pdo, $period);
$previousRefund = report_completed_refund($pdo, $previous);
$netSales = max(0, (float) ($summary['gross_product_sales'] ?? 0) - (float) ($summary['product_discount'] ?? 0) - $refundAmount);
$previousNetSales = max(0, (float) ($previousSummary['gross_product_sales'] ?? 0) - (float) ($previousSummary['product_discount'] ?? 0) - $previousRefund);

$dailyRows = report_fetch_all(
    $pdo,
    "SELECT DATE(created_at) AS report_date,
            COUNT(*) AS order_count,
            SUM(status = 'Selesai') AS completed_count,
            COALESCE(SUM(CASE WHEN status = 'Selesai' THEN subtotal_amount - product_discount ELSE 0 END), 0) AS product_sales
     FROM orders
     WHERE created_at BETWEEN :from_start AND :to_end
     GROUP BY DATE(created_at)
     ORDER BY report_date",
    $params
);
$maxDaily = max(array_map(static fn(array $row): float => (float) $row['product_sales'], $dailyRows) ?: [0]);

$statusRows = report_fetch_all(
    $pdo,
    "SELECT status, COUNT(*) AS total
     FROM orders
     WHERE created_at BETWEEN :from_start AND :to_end
     GROUP BY status
     ORDER BY total DESC",
    $params
);

$topProducts = report_fetch_all(
    $pdo,
    "SELECT oi.product_name, COALESCE(oi.variant_name, '') AS variant_name,
            SUM(oi.quantity) AS sold_quantity,
            SUM(oi.subtotal) AS gross_sales
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id
     WHERE o.status = 'Selesai' AND o.created_at BETWEEN :from_start AND :to_end
     GROUP BY oi.product_id, oi.variant_id, oi.product_name, oi.variant_name
     ORDER BY sold_quantity DESC, gross_sales DESC
     LIMIT 8",
    $params
);

$topCustomers = report_fetch_all(
    $pdo,
    "SELECT u.id, u.name, u.email,
            COUNT(o.id) AS order_count,
            SUM(o.status = 'Selesai') AS completed_count,
            COALESCE(SUM(CASE WHEN o.status = 'Selesai' THEN o.total_amount ELSE 0 END), 0) AS total_spent
     FROM users u
     INNER JOIN orders o ON o.user_id = u.id
     WHERE u.role = 'customer' AND o.created_at BETWEEN :from_start AND :to_end
     GROUP BY u.id, u.name, u.email
     ORDER BY total_spent DESC, completed_count DESC
     LIMIT 6",
    $params
);

$attention = [
    'low_stock' => (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock <= low_stock_threshold")->fetchColumn()
        + (int) $pdo->query("SELECT COUNT(*) FROM product_variants WHERE is_active = 1 AND stock <= low_stock_threshold")->fetchColumn(),
    'pending_reviews' => (int) $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'PENDING'")->fetchColumn(),
    'pending_returns' => (int) $pdo->query("SELECT COUNT(*) FROM return_requests WHERE status IN ('SUBMITTED','UNDER_REVIEW','APPROVED','CUSTOMER_SHIPPED','STORE_RECEIVED','PROCESSING')")->fetchColumn(),
    'missing_tracking' => (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Diproses','Dikirim') AND (tracking_number IS NULL OR tracking_number = '')")->fetchColumn(),
];

$pageTitle = 'Pusat Laporan';
$activeMenu = 'reports';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div>
        <h1>Pusat Laporan Admin</h1>
        <p>Ringkasan penjualan, operasional, pelanggan, promosi, ulasan, dan retur.</p>
    </div>
    <div class="admin-page-actions">
        <a class="admin-button admin-button-secondary" target="_blank" href="<?= e(report_print_url('overview', $period)) ?>">Cetak Ringkasan</a>
        <a class="admin-button admin-button-primary" href="<?= e(report_export_url('sales', $period)) ?>">Ekspor Penjualan Excel</a>
    </div>
</div>

<?php report_nav('overview', $period); ?>
<?php report_filter_form($period); ?>

<div class="report-period-note">
    <strong>Periode <?= e(report_period_label($period['from'], $period['to'])) ?></strong>
    <span>Dibandingkan dengan <?= e(report_period_label($previous['from'], $previous['to'])) ?>.</span>
</div>

<section class="admin-grid report-kpi-grid">
    <article class="admin-stat-card">
        <div class="admin-stat-icon">💵</div>
        <div class="admin-stat-copy"><span>Penjualan Bersih Produk</span><strong><?= rupiah($netSales) ?></strong><small>Setelah diskon produk dan refund selesai</small></div>
        <?= report_growth_badge(report_growth($netSales, $previousNetSales)) ?>
    </article>
    <article class="admin-stat-card">
        <div class="admin-stat-icon">🧾</div>
        <div class="admin-stat-copy"><span>Pesanan Selesai</span><strong><?= (int) ($summary['completed_count'] ?? 0) ?></strong><small>Dari <?= (int) ($summary['order_count'] ?? 0) ?> pesanan</small></div>
        <?= report_growth_badge(report_growth((float) ($summary['completed_count'] ?? 0), (float) ($previousSummary['completed_count'] ?? 0))) ?>
    </article>
    <article class="admin-stat-card">
        <div class="admin-stat-icon">🛒</div>
        <div class="admin-stat-copy"><span>Produk Terjual</span><strong><?= (int) ($itemSummary['sold_quantity'] ?? 0) ?></strong><small>Item dari pesanan selesai</small></div>
    </article>
    <article class="admin-stat-card">
        <div class="admin-stat-icon">📊</div>
        <div class="admin-stat-copy"><span>Rata-rata Transaksi</span><strong><?= rupiah($summary['average_order'] ?? 0) ?></strong><small>Nilai pembayaran pesanan selesai</small></div>
    </article>
    <article class="admin-stat-card">
        <div class="admin-stat-icon">🎟️</div>
        <div class="admin-stat-copy"><span>Total Diskon</span><strong><?= rupiah((float) ($summary['product_discount'] ?? 0) + (float) ($summary['shipping_discount'] ?? 0)) ?></strong><small>Diskon produk dan ongkir</small></div>
    </article>
    <article class="admin-stat-card">
        <div class="admin-stat-icon">↩️</div>
        <div class="admin-stat-copy"><span>Refund Selesai</span><strong><?= rupiah($refundAmount) ?></strong><small>Mengurangi penjualan bersih</small></div>
        <?= report_growth_badge(report_growth($refundAmount, $previousRefund), true) ?>
    </article>
</section>

<section class="admin-grid admin-grid-2 report-section-gap">
    <article class="admin-card">
        <div class="admin-card-head"><div><h2>Tren Penjualan Harian</h2><small>Penjualan produk setelah diskon produk</small></div><a href="reports_sales.php?<?= e(report_query()) ?>">Detail</a></div>
        <?php if ($dailyRows === []): ?>
            <div class="admin-empty"><strong>Belum ada data</strong><p>Tidak ada transaksi pada periode ini.</p></div>
        <?php else: ?>
            <div class="admin-chart report-chart">
                <?php foreach ($dailyRows as $row): ?>
                    <?php $width = $maxDaily > 0 ? max(2, ((float) $row['product_sales'] / $maxDaily) * 100) : 2; ?>
                    <div class="admin-chart-row">
                        <span class="admin-chart-label"><?= e(date('d/m', strtotime((string) $row['report_date']))) ?></span>
                        <div class="admin-chart-track"><div class="admin-chart-bar" style="width:<?= e(number_format($width, 2, '.', '')) ?>%"></div></div>
                        <span class="admin-chart-value"><?= rupiah($row['product_sales']) ?> · <?= (int) $row['completed_count'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="admin-card">
        <div class="admin-card-head"><div><h2>Status Pesanan</h2><small>Tingkat penyelesaian <?= number_format(report_percent((int) ($summary['completed_count'] ?? 0), (int) ($summary['order_count'] ?? 0)), 1, ',', '.') ?>%</small></div><a href="reports_orders.php?<?= e(report_query()) ?>">Detail</a></div>
        <?php if ($statusRows === []): ?>
            <div class="admin-empty"><strong>Belum ada pesanan</strong><p>Status pesanan akan tampil di sini.</p></div>
        <?php else: ?>
            <div class="report-status-list">
                <?php foreach ($statusRows as $row): ?>
                    <?php $percentage = report_percent((int) $row['total'], (int) ($summary['order_count'] ?? 0)); ?>
                    <div>
                        <div><span class="admin-badge <?= e(status_css_class((string) $row['status'])) ?>"><?= e((string) $row['status']) ?></span><strong><?= (int) $row['total'] ?> (<?= number_format($percentage, 1, ',', '.') ?>%)</strong></div>
                        <div class="report-progress"><span style="width:<?= e(number_format($percentage, 2, '.', '')) ?>%"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="admin-grid admin-grid-2 report-section-gap">
    <article class="admin-card">
        <div class="admin-card-head"><h2>Produk dan Varian Terlaris</h2><a href="reports_products.php?<?= e(report_query()) ?>">Laporan produk</a></div>
        <?php if ($topProducts === []): ?>
            <div class="admin-empty"><strong>Belum ada produk terjual</strong><p>Data dihitung dari pesanan selesai.</p></div>
        <?php else: ?>
            <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produk</th><th>Terjual</th><th>Penjualan Kotor</th></tr></thead><tbody>
            <?php foreach ($topProducts as $row): ?><tr><td><strong><?= e((string) $row['product_name']) ?></strong><?php if ((string) $row['variant_name'] !== ''): ?><br><small><?= e((string) $row['variant_name']) ?></small><?php endif; ?></td><td><?= (int) $row['sold_quantity'] ?> unit</td><td><strong><?= rupiah($row['gross_sales']) ?></strong></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </article>

    <article class="admin-card">
        <div class="admin-card-head"><h2>Pelanggan Teraktif</h2><a href="reports_customers.php?<?= e(report_query()) ?>">Laporan pelanggan</a></div>
        <?php if ($topCustomers === []): ?>
            <div class="admin-empty"><strong>Belum ada pelanggan aktif</strong><p>Data pelanggan akan muncul setelah transaksi.</p></div>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($topCustomers as $customer): ?>
                    <a class="admin-list-item" href="customer_detail.php?id=<?= (int) $customer['id'] ?>">
                        <div class="admin-list-copy"><strong><?= e((string) $customer['name']) ?></strong><span><?= e((string) $customer['email']) ?> · <?= (int) $customer['completed_count'] ?> selesai</span></div>
                        <strong><?= rupiah($customer['total_spent']) ?></strong>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="admin-card report-section-gap">
    <div class="admin-card-head"><div><h2>Perlu Perhatian</h2><small>Ringkasan operasional saat ini, tidak dibatasi periode.</small></div></div>
    <div class="report-attention-grid">
        <a href="reports_stock.php?stock_status=low"><span>⚠️</span><strong><?= $attention['low_stock'] ?></strong><small>Produk/varian stok menipis</small></a>
        <a href="reviews.php?status=PENDING"><span>⭐</span><strong><?= $attention['pending_reviews'] ?></strong><small>Ulasan menunggu moderasi</small></a>
        <a href="returns.php"><span>↩️</span><strong><?= $attention['pending_returns'] ?></strong><small>Retur masih diproses</small></a>
        <a href="reports_shipping.php"><span>🚚</span><strong><?= $attention['missing_tracking'] ?></strong><small>Pesanan belum memiliki resi</small></a>
    </div>
</section>

<section class="report-module-grid">
    <?php
    $modules = [
        ['reports_sales.php', '💰', 'Penjualan', 'Omzet, diskon, refund, rata-rata transaksi, dan tren harian.'],
        ['reports_orders.php', '📦', 'Pesanan', 'Status, pembatalan, penolakan, pembayaran, dan detail transaksi.'],
        ['reports_products.php', '🛍️', 'Produk', 'Produk dan varian terlaris, rating, wishlist, dan tingkat retur.'],
        ['reports_stock.php', '📊', 'Stok', 'Stok saat ini serta seluruh pergerakan masuk dan keluar.'],
        ['reports_customers.php', '👥', 'Pelanggan', 'Pelanggan baru, aktif, total belanja, pembatalan, dan retur.'],
        ['reports_payments.php', '💳', 'Pembayaran', 'COD, transfer, e-wallet, QRIS, dan metode simulasi lainnya.'],
        ['reports_shipping.php', '🚚', 'Pengiriman', 'Kurir, layanan, ongkir, biaya COD, tujuan, dan resi.'],
        ['reports_promotions.php', '🎟️', 'Voucher & Promo', 'Pemakaian, pengembalian kuota, total diskon, dan penjualan promo.'],
        ['reports_wishlist.php', '♥', 'Wishlist', 'Produk favorit, pelanggan penyimpan, dan konversi pembelian.'],
        ['reports_reviews.php', '⭐', 'Ulasan', 'Rating, moderasi, balasan admin, dan performa produk.'],
        ['reports_returns.php', '↩️', 'Retur', 'Status retur, refund, alasan, produk, dan pengembalian stok.'],
    ];
    foreach ($modules as [$url, $icon, $title, $description]): ?>
        <a class="admin-card report-module-card" href="<?= e($url . '?' . http_build_query(['date_from' => $period['from'], 'date_to' => $period['to'], 'period' => $period['preset']])) ?>">
            <span><?= $icon ?></span><div><strong><?= e($title) ?></strong><small><?= e($description) ?></small></div><b>→</b>
        </a>
    <?php endforeach; ?>
</section>

<script src="assets/admin-reports.js"></script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
