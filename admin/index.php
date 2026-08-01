<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$todaySales = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'Selesai' AND DATE(COALESCE(completed_at, created_at)) = CURDATE()"
)->fetchColumn();
$monthSales = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'Selesai' AND YEAR(COALESCE(completed_at, created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(completed_at, created_at)) = MONTH(CURDATE())"
)->fetchColumn();
$monthProductSales = (float) $pdo->query(
    "SELECT COALESCE(SUM(subtotal_amount - product_discount), 0) FROM orders WHERE status = 'Selesai' AND YEAR(COALESCE(completed_at, created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(completed_at, created_at)) = MONTH(CURDATE())"
)->fetchColumn();
$monthRefund = (float) $pdo->query(
    "SELECT COALESCE(SUM(refund_amount), 0) FROM return_requests WHERE refund_status = 'COMPLETED' AND YEAR(COALESCE(refunded_at, completed_at, updated_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(refunded_at, completed_at, updated_at)) = MONTH(CURDATE())"
)->fetchColumn();
$monthNetSales = max(0, $monthProductSales - $monthRefund);
$monthCompletedOrders = (int) $pdo->query(
    "SELECT COUNT(*) FROM orders WHERE status = 'Selesai' AND YEAR(COALESCE(completed_at, created_at)) = YEAR(CURDATE()) AND MONTH(COALESCE(completed_at, created_at)) = MONTH(CURDATE())"
)->fetchColumn();
$totalOrders = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
$totalCustomers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'")->fetchColumn();
$waitingOrders = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Menunggu Konfirmasi','Menunggu Pembayaran')")->fetchColumn();
$lowStock = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active' AND stock <= low_stock_threshold")->fetchColumn();
$codConfirmationHours = max(1, (int) setting($pdo, 'cod_confirmation_hours', '24'));
$overdueCod = (int) $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Menunggu Konfirmasi' AND payment_method IN ('cod','cod_check') AND created_at < DATE_SUB(NOW(), INTERVAL {$codConfirmationHours} HOUR)")->fetchColumn();
$wishlistSummary = $pdo->query(
    "SELECT COUNT(*) AS total_items,
            COUNT(DISTINCT user_id) AS customer_count,
            COUNT(DISTINCT product_id) AS product_count
     FROM wishlist_items"
)->fetch() ?: ['total_items' => 0, 'customer_count' => 0, 'product_count' => 0];
$mostWishedProduct = $pdo->query(
    "SELECT p.id, p.name, COUNT(wi.id) AS total
     FROM wishlist_items wi
     INNER JOIN products p ON p.id = wi.product_id
     GROUP BY p.id, p.name
     ORDER BY total DESC, MAX(wi.updated_at) DESC
     LIMIT 1"
)->fetch();
$reviewSummary = $pdo->query(
    "SELECT COUNT(*) AS total_reviews,
            COALESCE(AVG(CASE WHEN status = 'PUBLISHED' THEN rating END), 0) AS average_rating,
            SUM(status = 'PENDING') AS pending_reviews
     FROM product_reviews"
)->fetch() ?: ['total_reviews' => 0, 'average_rating' => 0, 'pending_reviews' => 0];
$returnSummary = $pdo->query(
    "SELECT COUNT(*) AS total_returns,
            SUM(status IN ('SUBMITTED','UNDER_REVIEW')) AS pending_returns,
            SUM(status = 'CUSTOMER_SHIPPED') AS shipped_returns,
            SUM(status = 'COMPLETED') AS completed_returns,
            COALESCE(SUM(CASE WHEN refund_status = 'COMPLETED' THEN refund_amount ELSE 0 END), 0) AS refunded_amount
     FROM return_requests"
)->fetch() ?: ['total_returns'=>0,'pending_returns'=>0,'shipped_returns'=>0,'completed_returns'=>0,'refunded_amount'=>0];
$topRatedProduct = $pdo->query(
    "SELECT id, name, average_rating, review_count
     FROM products
     WHERE review_count > 0
     ORDER BY average_rating DESC, review_count DESC
     LIMIT 1"
)->fetch();

$statusRows = $pdo->query('SELECT status, COUNT(*) AS total FROM orders GROUP BY status')->fetchAll();
$statusCounts = [];
foreach ($statusRows as $row) {
    $statusCounts[(string) $row['status']] = (int) $row['total'];
}

$salesStatement = $pdo->query(
    "SELECT DATE_FORMAT(d.day_date, '%d/%m') AS label,
            COALESCE(SUM(CASE WHEN o.status = 'Selesai' THEN o.total_amount ELSE 0 END), 0) AS amount
     FROM (
        SELECT CURDATE() - INTERVAL 6 DAY AS day_date UNION ALL
        SELECT CURDATE() - INTERVAL 5 DAY UNION ALL
        SELECT CURDATE() - INTERVAL 4 DAY UNION ALL
        SELECT CURDATE() - INTERVAL 3 DAY UNION ALL
        SELECT CURDATE() - INTERVAL 2 DAY UNION ALL
        SELECT CURDATE() - INTERVAL 1 DAY UNION ALL
        SELECT CURDATE()
     ) d
     LEFT JOIN orders o ON DATE(COALESCE(o.completed_at, o.created_at)) = d.day_date
     GROUP BY d.day_date
     ORDER BY d.day_date"
);
$dailySales = $salesStatement->fetchAll();
$maxSales = max(array_map(fn(array $row): float => (float) $row['amount'], $dailySales) ?: [0]);

$latestOrders = $pdo->query(
    "SELECT o.id, o.order_number, o.status, o.total_amount, o.payment_method, o.created_at,
            u.name AS customer_name
     FROM orders o
     INNER JOIN users u ON u.id = o.user_id
     ORDER BY o.id DESC LIMIT 6"
)->fetchAll();

$topProducts = $pdo->query(
    "SELECT oi.product_name, SUM(oi.quantity) AS sold, SUM(oi.subtotal) AS revenue
     FROM order_items oi
     INNER JOIN orders o ON o.id = oi.order_id AND o.status = 'Selesai'
     GROUP BY oi.product_name
     ORDER BY sold DESC, revenue DESC LIMIT 5"
)->fetchAll();

$lowStockProducts = $pdo->query(
    "SELECT id, name, stock, low_stock_threshold, image
     FROM products
     WHERE status = 'active' AND stock <= low_stock_threshold
     ORDER BY stock ASC, name ASC LIMIT 6"
)->fetchAll();

$activities = $pdo->query(
    "SELECT a.description, a.created_at, u.name AS admin_name
     FROM admin_activity_logs a
     INNER JOIN users u ON u.id = a.admin_id
     ORDER BY a.id DESC LIMIT 6"
)->fetchAll();

$pageTitle = 'Dashboard';
$activeMenu = 'dashboard';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div>
        <h1>Selamat datang, <?= e((string) ($adminUser['name'] ?? 'Admin')) ?> 👋</h1>
        <p>Ringkasan operasional toko dan aktivitas terbaru ShopFlow.</p>
    </div>
    <div class="admin-page-actions">
        <a class="admin-button admin-button-secondary" href="reports.php">Lihat Laporan</a>
        <a class="admin-button admin-button-primary" href="product_form.php">+ Tambah Produk</a>
    </div>
</div>
<?php if ($overdueCod > 0): ?>
    <div class="admin-alert admin-alert-warning"><strong><?= $overdueCod ?> pesanan COD</strong> sudah menunggu konfirmasi lebih dari <?= $codConfirmationHours ?> jam. <a href="orders.php?status=Menunggu+Konfirmasi" style="font-weight:700">Periksa sekarang →</a></div>
<?php endif; ?>

<section class="admin-grid admin-stats">
    <article class="admin-stat-card"><div class="admin-stat-icon">💰</div><div class="admin-stat-copy"><span>Penjualan Hari Ini</span><strong><?= rupiah($todaySales) ?></strong><small>Berdasarkan pesanan selesai</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">📈</div><div class="admin-stat-copy"><span>Penjualan Bersih Bulan Ini</span><strong><?= rupiah($monthNetSales) ?></strong><small><?= $monthCompletedOrders ?> selesai · refund <?= rupiah($monthRefund) ?></small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">📦</div><div class="admin-stat-copy"><span>Total Pesanan</span><strong><?= $totalOrders ?></strong><small><?= $waitingOrders ?> perlu ditindaklanjuti</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">👥</div><div class="admin-stat-copy"><span>Total Pelanggan</span><strong><?= $totalCustomers ?></strong><small>Akun pelanggan terdaftar</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🛍️</div><div class="admin-stat-copy"><span>Produk Aktif</span><strong><?= $totalProducts ?></strong><small><?= $lowStock ?> stok menipis/habis</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">↩️</div><div class="admin-stat-copy"><span>Refund Bulan Ini</span><strong><?= rupiah($monthRefund) ?></strong><small>Refund berstatus selesai</small></div></article>
</section>

<section class="admin-card admin-wishlist-insight" style="margin-bottom:18px">
    <div class="admin-wishlist-insight-icon">♥</div>
    <div>
        <span>Minat Pelanggan</span>
        <strong><?= (int) $wishlistSummary['total_items'] ?> item tersimpan di wishlist</strong>
        <small><?= (int) $wishlistSummary['customer_count'] ?> pelanggan • <?= (int) $wishlistSummary['product_count'] ?> produk<?= $mostWishedProduct ? ' • Terfavorit: ' . e((string) $mostWishedProduct['name']) : '' ?></small>
    </div>
    <a class="admin-button admin-button-secondary" href="reports_wishlist.php">Lihat Laporan Wishlist</a>
</section>

<section class="admin-card admin-wishlist-insight" style="margin-bottom:18px">
    <div class="admin-wishlist-insight-icon">⭐</div>
    <div>
        <span>Kepuasan Pelanggan</span>
        <strong><?= (int) $reviewSummary['total_reviews'] ?> ulasan · Rating <?= number_format((float) $reviewSummary['average_rating'], 2, ',', '.') ?></strong>
        <small><?= (int) $reviewSummary['pending_reviews'] ?> menunggu moderasi<?= $topRatedProduct ? ' • Terbaik: ' . e((string) $topRatedProduct['name']) . ' (' . number_format((float) $topRatedProduct['average_rating'], 1, ',', '.') . ')' : '' ?></small>
    </div>
    <a class="admin-button admin-button-secondary" href="reviews.php">Kelola Ulasan</a>
</section>

<section class="admin-card admin-wishlist-insight" style="margin-bottom:18px">
    <div class="admin-wishlist-insight-icon">↩️</div>
    <div>
        <span>Layanan Setelah Pembelian</span>
        <strong><?= (int) $returnSummary['total_returns'] ?> pengajuan retur · <?= (int) $returnSummary['pending_returns'] ?> menunggu tinjauan</strong>
        <small><?= (int) $returnSummary['shipped_returns'] ?> barang sedang dikirim kembali • <?= (int) $returnSummary['completed_returns'] ?> selesai • Dana kembali <?= rupiah($returnSummary['refunded_amount']) ?></small>
    </div>
    <a class="admin-button admin-button-secondary" href="returns.php">Kelola Retur</a>
</section>

<section class="admin-grid admin-grid-2" style="margin-bottom:18px">
    <article class="admin-card">
        <div class="admin-card-head"><div><h2>Penjualan 7 Hari Terakhir</h2><small><?= rupiah($monthSales) ?> bulan ini</small></div><a href="reports.php">Detail</a></div>
        <div class="admin-chart">
            <?php foreach ($dailySales as $row): ?>
                <?php $width = $maxSales > 0 ? max(2, ((float) $row['amount'] / $maxSales) * 100) : 2; ?>
                <div class="admin-chart-row">
                    <span class="admin-chart-label"><?= e((string) $row['label']) ?></span>
                    <div class="admin-chart-track"><div class="admin-chart-bar" style="width:<?= number_format($width, 2, '.', '') ?>%"></div></div>
                    <span class="admin-chart-value"><?= rupiah($row['amount']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="admin-card">
        <div class="admin-card-head"><h2>Status Pesanan</h2><a href="orders.php">Kelola</a></div>
        <div class="admin-grid admin-grid-2">
            <?php foreach (['Menunggu Konfirmasi','Diproses','Dikirim','Selesai','Ditolak','Dibatalkan'] as $status): ?>
                <a class="admin-stat-card" href="orders.php?status=<?= urlencode($status) ?>">
                    <div class="admin-stat-copy"><span><?= e($status) ?></span><strong><?= (int) ($statusCounts[$status] ?? 0) ?></strong></div>
                    <span class="admin-badge <?= e(status_css_class($status)) ?>">Lihat</span>
                </a>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<section class="admin-grid admin-grid-2" style="margin-bottom:18px">
    <article class="admin-card">
        <div class="admin-card-head"><h2>Pesanan Terbaru</h2><a href="orders.php">Semua pesanan</a></div>
        <?php if ($latestOrders === []): ?>
            <div class="admin-empty"><strong>Belum ada pesanan</strong><p>Pesanan pelanggan akan muncul di sini.</p></div>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($latestOrders as $order): ?>
                    <a class="admin-list-item" href="order_detail.php?id=<?= (int) $order['id'] ?>">
                        <div class="admin-list-copy"><strong><?= e(order_code($order)) ?> — <?= e((string) $order['customer_name']) ?></strong><span><?= e(payment_method_label((string) $order['payment_method'])) ?> • <?= format_datetime((string) $order['created_at']) ?></span></div>
                        <div style="text-align:right"><strong><?= rupiah($order['total_amount']) ?></strong><br><span class="admin-badge <?= e(status_css_class((string) $order['status'])) ?>"><?= e((string) $order['status']) ?></span></div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="admin-card">
        <div class="admin-card-head"><h2>Produk Terlaris</h2><a href="reports_products.php">Laporan produk</a></div>
        <?php if ($topProducts === []): ?>
            <div class="admin-empty"><strong>Belum ada data penjualan</strong><p>Produk terlaris dihitung dari pesanan selesai.</p></div>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($topProducts as $index => $product): ?>
                    <div class="admin-list-item"><div class="admin-list-copy"><strong>#<?= $index + 1 ?> <?= e((string) $product['product_name']) ?></strong><span><?= (int) $product['sold'] ?> produk terjual</span></div><strong><?= rupiah($product['revenue']) ?></strong></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="admin-grid admin-grid-2">
    <article class="admin-card">
        <div class="admin-card-head"><h2>Stok Perlu Perhatian</h2><a href="stock.php">Kelola stok</a></div>
        <?php if ($lowStockProducts === []): ?>
            <div class="admin-empty"><strong>Stok aman</strong><p>Tidak ada produk yang berada di bawah batas stok.</p></div>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($lowStockProducts as $product): ?>
                    <a class="admin-list-item" href="product_form.php?id=<?= (int) $product['id'] ?>">
                        <div class="admin-product-cell"><img src="../<?= e((string) $product['image']) ?>" alt=""><div><strong><?= e((string) $product['name']) ?></strong><small>Batas minimum <?= (int) $product['low_stock_threshold'] ?></small></div></div>
                        <strong class="<?= (int) $product['stock'] === 0 ? 'admin-stock-empty' : 'admin-stock-low' ?>"><?= (int) $product['stock'] ?> unit</strong>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="admin-card">
        <div class="admin-card-head"><h2>Aktivitas Admin</h2><a href="activities.php">Lihat semua</a></div>
        <?php if ($activities === []): ?>
            <div class="admin-empty"><strong>Belum ada aktivitas</strong><p>Perubahan penting akan dicatat otomatis.</p></div>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach ($activities as $activity): ?>
                    <div class="admin-list-item"><div class="admin-list-copy"><strong><?= e((string) $activity['description']) ?></strong><span><?= e((string) $activity['admin_name']) ?> • <?= format_datetime((string) $activity['created_at']) ?></span></div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
