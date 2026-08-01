<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));
$rating = filter_input(INPUT_GET, 'rating', FILTER_VALIDATE_INT) ?: 0;
$productId = filter_input(INPUT_GET, 'product_id', FILTER_VALIDATE_INT) ?: 0;
$search = trim((string) ($_GET['search'] ?? ''));
$allowedStatuses = ['ALL', 'PENDING', 'PUBLISHED', 'REJECTED', 'HIDDEN'];
if (!in_array($status, $allowedStatuses, true)) {
    $status = 'ALL';
}
if ($rating < 1 || $rating > 5) {
    $rating = 0;
}

$where = ['1=1'];
$params = [];
if ($status !== 'ALL') {
    $where[] = 'r.status = :status';
    $params['status'] = $status;
}
if ($rating > 0) {
    $where[] = 'r.rating = :rating';
    $params['rating'] = $rating;
}
if ($productId > 0) {
    $where[] = 'r.product_id = :product_id';
    $params['product_id'] = $productId;
}
if ($search !== '') {
    $where[] = '(p.name LIKE :search OR u.name LIKE :search OR u.email LIKE :search OR r.review_text LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$sql = "SELECT r.*, p.name AS product_name, p.image AS product_image,
               u.name AS customer_name, u.email AS customer_email,
               o.order_number,
               COUNT(ri.id) AS image_count
        FROM product_reviews r
        INNER JOIN products p ON p.id = r.product_id
        INNER JOIN users u ON u.id = r.user_id
        INNER JOIN orders o ON o.id = r.order_id
        LEFT JOIN product_review_images ri ON ri.review_id = r.id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY r.id
        ORDER BY CASE r.status WHEN 'PENDING' THEN 0 ELSE 1 END, r.created_at DESC, r.id DESC
        LIMIT 300";
$statement = $pdo->prepare($sql);
$statement->execute($params);
$reviews = $statement->fetchAll();

$summary = $pdo->query(
    "SELECT COUNT(*) AS total,
            COALESCE(AVG(CASE WHEN status = 'PUBLISHED' THEN rating END), 0) AS average_rating,
            SUM(status = 'PENDING') AS pending_count,
            SUM(status = 'PUBLISHED') AS published_count,
            SUM(rating = 5 AND status = 'PUBLISHED') AS five_star_count
     FROM product_reviews"
)->fetch() ?: [];
$products = $pdo->query('SELECT id, name FROM products ORDER BY name')->fetchAll();

$pageTitle = 'Ulasan Produk';
$activeMenu = 'reviews';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head">
    <div><h1>Ulasan Produk</h1><p>Moderasi rating, foto, komentar pelanggan, dan balasan toko.</p></div>
    <div class="admin-page-actions"><a class="admin-button admin-button-secondary" href="reports.php">Laporan</a></div>
</div>

<section class="admin-grid admin-stats">
    <article class="admin-stat-card"><div class="admin-stat-icon">⭐</div><div class="admin-stat-copy"><span>Total Ulasan</span><strong><?= (int) ($summary['total'] ?? 0) ?></strong><small>Semua status</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">★</div><div class="admin-stat-copy"><span>Rating Rata-rata</span><strong><?= number_format((float) ($summary['average_rating'] ?? 0), 2, ',', '.') ?></strong><small>Ulasan diterbitkan</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">⏳</div><div class="admin-stat-copy"><span>Menunggu Moderasi</span><strong><?= (int) ($summary['pending_count'] ?? 0) ?></strong><small>Perlu ditinjau</small></div></article>
    <article class="admin-stat-card"><div class="admin-stat-icon">🌟</div><div class="admin-stat-copy"><span>Ulasan 5 Bintang</span><strong><?= (int) ($summary['five_star_count'] ?? 0) ?></strong><small>Sudah diterbitkan</small></div></article>
</section>

<section class="admin-card" style="margin-bottom:18px">
    <form class="admin-filter-grid" method="get">
        <label><span>Status</span><select name="status"><?php foreach (['ALL'=>'Semua','PENDING'=>'Menunggu','PUBLISHED'=>'Diterbitkan','REJECTED'=>'Ditolak','HIDDEN'=>'Disembunyikan'] as $key=>$label): ?><option value="<?= e($key) ?>" <?= $status===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
        <label><span>Rating</span><select name="rating"><option value="0">Semua</option><?php for($i=5;$i>=1;$i--): ?><option value="<?= $i ?>" <?= $rating===$i?'selected':'' ?>><?= $i ?> bintang</option><?php endfor; ?></select></label>
        <label><span>Produk</span><select name="product_id"><option value="0">Semua produk</option><?php foreach($products as $product): ?><option value="<?= (int)$product['id'] ?>" <?= $productId===(int)$product['id']?'selected':'' ?>><?= e((string)$product['name']) ?></option><?php endforeach; ?></select></label>
        <label><span>Pencarian</span><input type="search" name="search" value="<?= e($search) ?>" placeholder="Produk, pelanggan, ulasan..."></label>
        <div class="admin-filter-actions"><button class="admin-button admin-button-primary" type="submit">Terapkan</button><a class="admin-button admin-button-secondary" href="reviews.php">Reset</a></div>
    </form>
</section>

<section class="admin-card" style="padding:0;overflow:hidden">
<?php if ($reviews === []): ?>
    <div class="admin-empty"><strong>Tidak ada ulasan</strong><p>Belum ada data yang sesuai dengan filter.</p></div>
<?php else: ?>
    <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Produk</th><th>Pelanggan</th><th>Rating & Ulasan</th><th>Status</th><th>Waktu</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($reviews as $review): ?>
        <tr>
            <td><div class="admin-product-cell"><img src="../<?= e((string)($review['product_image'] ?: 'assets/images/bag.svg')) ?>" alt=""><div><strong><?= e((string)$review['product_name']) ?></strong><?php if($review['variant_name']): ?><small><?= e((string)$review['variant_name']) ?></small><?php endif; ?></div></div></td>
            <td><strong><?= e((string)$review['customer_name']) ?></strong><br><small><?= e((string)$review['customer_email']) ?></small></td>
            <td><div class="admin-review-stars"><?= str_repeat('★',(int)$review['rating']) ?><span><?= str_repeat('☆',5-(int)$review['rating']) ?></span></div><?php if($review['review_title']): ?><strong><?= e((string)$review['review_title']) ?></strong><br><?php endif; ?><small><?= e(text_excerpt((string)$review['review_text'], 110)) ?><?php if((int)$review['image_count']>0): ?> · <?= (int)$review['image_count'] ?> foto<?php endif; ?></small></td>
            <td><span class="admin-badge <?= e(review_status_css_class((string)$review['status'])) ?>"><?= e(review_status_label((string)$review['status'])) ?></span></td>
            <td><?= format_datetime((string)$review['created_at']) ?></td>
            <td><a class="admin-button admin-button-primary admin-button-small" href="review_detail.php?id=<?= (int)$review['id'] ?>">Detail</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</section>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
