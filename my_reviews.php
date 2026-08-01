<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
$statement = $pdo->prepare(
    "SELECT r.*, p.name AS product_name, p.image AS product_image,
            o.order_number,
            GROUP_CONCAT(ri.image_path ORDER BY ri.sort_order, ri.id SEPARATOR '||') AS image_paths
     FROM product_reviews r
     INNER JOIN products p ON p.id = r.product_id
     INNER JOIN orders o ON o.id = r.order_id
     LEFT JOIN product_review_images ri ON ri.review_id = r.id
     WHERE r.user_id = :user_id
     GROUP BY r.id
     ORDER BY r.created_at DESC, r.id DESC"
);
$statement->execute(['user_id' => (int) $user['id']]);
$reviews = $statement->fetchAll();

$pageTitle = 'Ulasan Saya';
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Akun saya</span><h1>Ulasan Saya</h1><p>Kelola ulasan dari produk yang sudah Anda beli.</p></div></section>
<section class="section"><div class="container">
<?php if ($reviews === []): ?>
    <div class="empty-state"><div class="empty-icon">⭐</div><h2>Belum ada ulasan</h2><p>Selesaikan pesanan lalu berikan ulasan pada produk yang Anda beli.</p><a class="button button-primary" href="orders.php">Lihat Pesanan</a></div>
<?php else: ?>
    <div class="my-review-list">
    <?php foreach ($reviews as $review): ?>
        <?php $images = trim((string) ($review['image_paths'] ?? '')) !== '' ? explode('||', (string) $review['image_paths']) : []; ?>
        <article class="my-review-card">
            <div class="my-review-product"><img src="<?= e((string) ($review['product_image'] ?: 'assets/images/bag.svg')) ?>" alt=""><div><span class="verified-purchase-badge">✓ Pembelian Terverifikasi</span><h2><?= e((string) $review['product_name']) ?></h2><?php if ($review['variant_name']): ?><p><?= e((string) $review['variant_name']) ?><?php if ($review['variant_sku']): ?> · SKU <?= e((string) $review['variant_sku']) ?><?php endif; ?></p><?php endif; ?><small>Pesanan <?= e(order_code($review)) ?> · <?= format_datetime((string) $review['created_at']) ?></small></div></div>
            <div class="my-review-content">
                <div class="review-card-head"><div class="review-stars" aria-label="<?= (int) $review['rating'] ?> dari 5 bintang"><?= str_repeat('★', (int) $review['rating']) ?><span><?= str_repeat('☆', 5-(int)$review['rating']) ?></span></div><span class="status-pill <?= e(review_status_css_class((string) $review['status'])) ?>"><?= e(review_status_label((string) $review['status'])) ?></span></div>
                <?php if ($review['review_title']): ?><h3><?= e((string) $review['review_title']) ?></h3><?php endif; ?>
                <p><?= nl2br(e((string) $review['review_text'])) ?></p>
                <?php if ($images !== []): ?><div class="review-image-grid"><?php foreach ($images as $image): ?><a href="<?= e($image) ?>" target="_blank" rel="noopener"><img src="<?= e($image) ?>" alt="Foto ulasan"></a><?php endforeach; ?></div><?php endif; ?>
                <?php if ((string) $review['status'] === 'REJECTED' && $review['rejection_reason']): ?><div class="review-rejection-box"><strong>Alasan penolakan</strong><p><?= e((string) $review['rejection_reason']) ?></p></div><?php endif; ?>
                <?php if ($review['admin_reply']): ?><div class="admin-review-reply"><strong>Balasan Toko</strong><p><?= nl2br(e((string) $review['admin_reply'])) ?></p><small><?= format_datetime((string) $review['replied_at']) ?></small></div><?php endif; ?>
            </div>
            <div class="my-review-actions"><a class="button button-secondary button-small" href="product_detail.php?id=<?= (int) $review['product_id'] ?>#ulasan">Lihat Produk</a><a class="button button-primary button-small" href="review_edit.php?id=<?= (int) $review['id'] ?>">Edit</a><form method="post" action="review_delete.php" data-confirm-review-delete><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="review_id" value="<?= (int) $review['id'] ?>"><button class="button button-danger button-small" type="submit">Hapus</button></form></div>
        </article>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
</div></section>
<script>document.querySelectorAll('[data-confirm-review-delete]').forEach((form)=>form.addEventListener('submit',(event)=>{if(!confirm('Hapus ulasan ini secara permanen?')) event.preventDefault();}));</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
